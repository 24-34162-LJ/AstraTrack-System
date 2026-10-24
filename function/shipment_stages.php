<?php
/**
 * shipment_stages.php — the one place that knows the shipment status wording.
 * Everything here reads the shipment_stages table (stage_id, stage_name, stage_group,
 * sort_order, is_closed). Add or rename a stage in the table, not in the PHP files.
 *
 * Needs the PDO connection in $conn (config/database.php) or pass it to stageRows() once.
 * Loaded by function/shipment_common.php, and directly by staff.php, admin.php and
 * client_dashbroad.php.
 */

// All stages, ordered. Cached for the request.
function stageRows(?PDO $conn = null): array
{
    static $rows = null;
    if ($rows !== null) return $rows;

    $conn = $conn ?? ($GLOBALS['conn'] ?? null);
    if (!($conn instanceof PDO)) {
        throw new RuntimeException('stageRows() needs the $conn PDO connection from config/database.php.');
    }
    $rows = $conn->query(
        "SELECT stage_id, stage_name, stage_group, sort_order, is_closed
         FROM shipment_stages ORDER BY sort_order, stage_id"
    )->fetchAll();
    return $rows;
}

// One stage by name (case-insensitive), or null
function stageByName($name): ?array
{
    $n = strtolower(trim((string)$name));
    foreach (stageRows() as $r) {
        if (strtolower($r['stage_name']) === $n) return $r;
    }
    return null;
}

// Options for the Update Status dropdown: every stage, in order
function stageOptions(): array
{
    return stageRows();
}

// How a stage_group label is shown: [url key, badge colour]. Unknown groups still work (blue).
function stageGroupStyle(string $group): array
{
    static $known = [
        'Pending Documents'  => ['pending',   'amber'],
        'Customs Processing' => ['customs',   'blue'],
        'In Transit'         => ['transit',   'teal'],
        'Delivered'          => ['delivered', 'green'],
        'On Hold'            => ['hold',      'amber'],
        'Cancelled'          => ['cancelled', 'red'],
    ];
    if (isset($known[$group])) return $known[$group];
    $key = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($group)), '_');
    return [$key !== '' ? $key : 'other', 'blue'];
}

// Groups in order: key => [label, badge colour, [lower-case stage names]]
// Default = the 4 progress groups used by the tabs, the dashboard bars and the stepper.
// $includeExceptions = true also returns On Hold and Cancelled.
function statusGroups(bool $includeExceptions = false): array
{
    $out = [];
    foreach (stageRows() as $r) {
        [$key, $colour] = stageGroupStyle($r['stage_group']);
        if (!$includeExceptions && in_array($key, ['hold', 'cancelled'], true)) continue;
        if (!isset($out[$key])) $out[$key] = [$r['stage_group'], $colour, []];
        $out[$key][2][] = strtolower($r['stage_name']);
    }
    return $out;
}

// Group key ('pending', 'customs', 'transit', 'delivered', 'hold', 'cancelled') for a stage name, or null
function statusKey($status): ?string
{
    $r = stageByName($status);
    return $r ? stageGroupStyle($r['stage_group'])[0] : null;
}

// [label, colour] for the badge
function statusInfo($status): array
{
    $r = stageByName($status);
    if ($r) {
        [, $colour] = stageGroupStyle($r['stage_group']);
        // Trucking keeps its own wording inside the In Transit group
        $label = $r['stage_name'] === 'Trucking' ? 'Trucking' : $r['stage_group'];
        return [$label, $colour];
    }
    return [trim((string)$status) !== '' ? (string)$status : 'Unknown', 'blue'];
}

// Steps of the stepper / timeline: key => label. First is the record itself, then one per progress group.
function progressSteps(): array
{
    $steps = ['created' => 'Shipment Record Created'];
    foreach (statusGroups() as $key => $g) $steps[$key] = $g[0];
    return $steps;
}

/* =====================================================================
 * Update Status rules (used by shipment_status.php and shipment_view.php)
 * The order of the stages is shipment_stages.sort_order: that table is the one
 * ordered list. On Hold and Cancelled are "exceptions": they are not a step
 * forward or backward, they can be set from any open stage.
 * ===================================================================== */

// On Hold / Cancelled
function isExceptionStage(array $stage): bool
{
    return in_array(stageGroupStyle((string)$stage['stage_group'])[0], ['hold', 'cancelled'], true);
}

// Where the shipment really is on the ordered line. For a shipment that is On Hold or
// Cancelled this is the last normal stage it had reached (so "forward" still means something).
function shipmentPosition(PDO $conn, int $shipmentId, array $currentStage): int
{
    if (!isExceptionStage($currentStage)) return (int)$currentStage['sort_order'];

    $st = $conn->prepare("SELECT stage FROM shipment_status_updates WHERE shipment_id = ? ORDER BY updated_at DESC, update_id DESC");
    $st->execute([$shipmentId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $s = stageByName($name);
        if ($s && !isExceptionStage($s)) return (int)$s['sort_order'];
    }
    $first = stageRows()[0] ?? null;
    return $first ? (int)$first['sort_order'] : 0;
}

/**
 * The stages a person may pick for a shipment.
 *  - correction (admin only): every stage except the current one
 *  - otherwise: stages after the current one, the exceptions, and "resume" from On Hold
 *    (the stage it was held at, or later). A closed shipment (Delivered / Cancelled) offers nothing.
 */
function allowedStages(array $current, int $position, string $role, bool $correction): array
{
    $out = [];
    $isAdmin = ($role === 'admin');
    if ($correction && !$isAdmin) return [];
    if (!$correction && !empty($current['is_closed'])) return [];

    foreach (stageRows() as $s) {
        if ($s['stage_name'] === $current['stage_name']) continue;
        if ($correction)                                   { $out[] = $s; continue; }
        if (isExceptionStage($s))                          { $out[] = $s; continue; }
        if ((int)$s['sort_order'] > $position)             { $out[] = $s; continue; }
        if ((int)$s['sort_order'] === $position && isExceptionStage($current)) { $out[] = $s; }   // resume
    }
    return $out;
}

// Field errors for one requested change: [] when it is allowed. Keys: stage, remarks.
function stageChangeErrors(array $current, int $position, array $target, string $role, bool $correction, string $remarks): array
{
    $errors = [];
    if ($target['stage_name'] === $current['stage_name']) {
        $errors['stage'] = 'The shipment is already at ' . $target['stage_name'] . '.';
    } else {
        $ok = array_column(allowedStages($current, $position, $role, $correction), 'stage_name');
        if (!in_array($target['stage_name'], $ok, true)) {
            if (!$correction && !empty($current['is_closed'])) {
                $errors['stage'] = $role === 'admin'
                    ? 'This shipment is closed. Tick "Correct a previous stage" to change it.'
                    : 'This shipment is closed. Only an administrator can correct it.';
            } elseif (!isExceptionStage($target) && (int)$target['sort_order'] <= $position) {
                $errors['stage'] = $role === 'admin'
                    ? 'Moving backwards needs "Correct a previous stage" and remarks.'
                    : 'Staff can only move a shipment forward. Ask an administrator to correct it.';
            } else {
                $errors['stage'] = 'That stage is not available from ' . $current['stage_name'] . '.';
            }
        }
    }
    if ($remarks === '') {
        if ($correction)                      $errors['remarks'] = 'Remarks are required for every correction.';
        elseif (isExceptionStage($target) && stageGroupStyle((string)$target['stage_group'])[0] !== 'hold')
            $errors['remarks'] = 'Remarks are required when setting status to ' . $target['stage_name'] . '.';
    }
    return $errors;
}

// transactions.status for a stage: pending / ongoing / completed. On Hold and Cancelled keep the
// current value (the ENUM has no value for them), except that a completed one is re-opened.
function transactionStatusFor(array $stage, string $currentTrx): string
{
    switch (stageGroupStyle((string)$stage['stage_group'])[0]) {
        case 'pending':   return 'pending';
        case 'delivered': return 'completed';
        case 'hold':
        case 'cancelled': return $currentTrx === 'completed' ? 'ongoing' : $currentTrx;
        default:          return 'ongoing';
    }
}

// shipment_status_updates.status wording for the new row
function updateRowStatus(array $stage, bool $correction): string
{
    if ($correction) return 'Correction';
    switch (stageGroupStyle((string)$stage['stage_group'])[0]) {
        case 'delivered': return 'Completed';
        case 'hold':      return 'On hold';
        case 'cancelled': return 'Cancelled';
        default:          return 'In progress';
    }
}

