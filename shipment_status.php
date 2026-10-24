<?php
/**
 * shipment_status.php — Update Status endpoint (AJAX, POST only, JSON in and out)
 *
 * Called by assets/js/shipment_status.js from the modal on shipment_view.php.
 * Files:  shipment_status.php (this), assets/js/shipment_status.js, assets/css/shipment_status.css,
 *         function/shipment_common.php + function/shipment_stages.php (rules and helpers), function/log_activity.php
 * Tables written, in ONE transaction: shipment_status_updates, shipments, transactions,
 *         transaction_history, activity_log (through logActivity()).
 *
 * POST fields: shipment_id, stage, remarks, occurred_at, correction (1 = admin correction), notify (1/0),
 *              expected_current (the status the person was looking at)
 * Header:      X-CSRF-Token (same pattern as shipment_add.php)
 * Answers:     200 saved | 401 no session | 403 client, bad CSRF or staff using correction | 404 | 405 not POST
 *              409 someone else changed the status first | 422 validation errors | 500 database error
 */
session_start();
require_once 'config/database.php';        // $conn (PDO)
require_once 'function/shipment_common.php';
require_once 'function/log_activity.php';

function jsonOut(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/* ---------- 1) method, access, CSRF ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonOut(405, ['error' => 'Use POST.']);
}

[$userId, $fullName, $role] = requireStaffOrAdmin($conn, true);   // clients -> 403, no session -> 401

$token = (string)($_SESSION['csrf'] ?? '');
$sent  = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($token === '' || $sent === '' || !hash_equals($token, $sent)) {
    jsonOut(403, ['error' => 'Your session expired. Reload the page and try again.']);
}

/* ---------- 2) helpers ---------- */

// "2026-10-22T16:05" (or with a space / seconds) -> "2026-10-22 16:05:00", null when it is not a real date
function parseOccurredAt(string $raw): ?string
{
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
        $d   = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        $err = DateTimeImmutable::getLastErrors();
        if ($d && (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0)) && (int)$d->format('Y') >= 2000) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    return null;
}

// Everything the page needs to redraw itself after a save (or after a 409)
function statusPayload(PDO $conn, int $id, string $role): array
{
    $st = $conn->prepare(
        "SELECT s.*, u.full_name AS staff_name FROM shipments s
         LEFT JOIN users u ON u.user_id = s.assigned_staff_id WHERE s.shipment_id = ?"
    );
    $st->execute([$id]);
    $ship = $st->fetch();
    $prog = buildProgress($conn, $ship, staffCreatedNote($ship));
    [$label, $colour] = statusInfo($ship['current_status']);

    $out = [
        'status'      => ['name' => $ship['current_status'], 'label' => $label, 'class' => $colour, 'badge' => badge($label, $colour)],
        'html'        => ['stepper' => renderStepperItems($prog['items']), 'timeline' => renderTimelineItems($prog['items'])],
        'lastUpdated' => fmtDate($prog['lastUpdated']),
        'modal'       => statusModalState($conn, $ship, $role),
    ];
    if ($role === 'admin') $out['html']['audit'] = renderAuditBody(auditRows($conn, $ship['reference_no']));
    return $out;
}

// Optional e-mail to the client. Off until config/mail.local.php exists and returns ['enabled' => true, 'from' => '...'].
// The update is already saved when this runs: a mail problem never undoes it.
function notifyClientByEmail(PDO $conn, array $ship, array $stage, string $remarks): bool
{
    $file = __DIR__ . '/config/mail.local.php';
    $cfg  = is_file($file) ? require $file : [];
    if (!is_array($cfg) || empty($cfg['enabled'])) return false;

    $st = $conn->prepare("SELECT email FROM clients WHERE client_id = ?");
    $st->execute([(int)$ship['client_id']]);
    $to = trim((string)$st->fetchColumn());
    if ($to === '' || preg_match('/[\r\n]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $from = (string)($cfg['from'] ?? 'no-reply@astratrack.local');
    if (preg_match('/[\r\n]/', $from)) return false;

    $subject = 'AstraTrack: shipment ' . $ship['reference_no'] . ' is now ' . $stage['stage_name'];
    $body    = "Hello,\r\n\r\nYour shipment " . $ship['reference_no'] . ' was updated to ' . $stage['stage_name'] . ".\r\n"
             . ($remarks !== '' ? "Note: " . $remarks . "\r\n" : '')
             . "\r\nYou can follow it any time in AstraTrack under My Shipments.\r\n\r\nAstra Cargo Impex Solutions OPC";
    try {
        return (bool)@mail($to, str_replace(["\r", "\n"], ' ', $subject), $body, 'From: ' . $from);
    } catch (Throwable $ex) {
        error_log('shipment_status mail failed: ' . $ex->getMessage());
        return false;
    }
}

/* ---------- 3) read and validate the fields that need no database ---------- */
$shipmentId = (int)($_POST['shipment_id'] ?? 0);
$stageName  = is_string($_POST['stage'] ?? null) ? trim($_POST['stage']) : '';
$rawRemarks = is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : '';
$rawAt      = is_string($_POST['occurred_at'] ?? null) ? trim($_POST['occurred_at']) : '';
$rawArrival = is_string($_POST['actual_arrival_date'] ?? null) ? trim($_POST['actual_arrival_date']) : '';
$reason     = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
$expected   = is_string($_POST['expected_current'] ?? null) ? trim($_POST['expected_current']) : '';
$correction = (($_POST['correction'] ?? '') === '1');
$notify     = (($_POST['notify'] ?? '') === '1');

if ($shipmentId <= 0) jsonOut(404, ['error' => 'Shipment not found.']);
if ($correction && $role !== 'admin') jsonOut(403, ['error' => 'Only an administrator can correct a previous stage.']);

$errors = [];

$target = $stageName !== '' ? stageByName($stageName) : null;
if ($target === null) $errors['stage'] = 'Choose a valid stage.';

$actualArrival = null;
if ($rawArrival !== '') {
    $arrivalDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rawArrival);
    $arrivalErrors = DateTimeImmutable::getLastErrors();
    if (!$arrivalDate || ($arrivalErrors && ($arrivalErrors['warning_count'] || $arrivalErrors['error_count'])) || $arrivalDate->format('Y-m-d') !== $rawArrival) {
        $errors['actual_arrival_date'] = 'Enter a valid arrival date.';
    } else {
        $actualArrival = $arrivalDate->format('Y-m-d');
    }
}

$remarks = '';
if (!mb_check_encoding($rawRemarks, 'UTF-8')) {
    $errors['remarks'] = 'Remarks contain characters that cannot be saved.';
} else {
    $clean = trim(str_replace(["\r\n", "\r"], "\n", $rawRemarks));
    $clean = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
    if (mb_strlen($clean, 'UTF-8') > 255) {
        $errors['remarks'] = 'Remarks can be up to 255 characters.';
    } else {
        $remarks = trim((string)preg_replace('/\s+/u', ' ', $clean));   // one line in the timeline and the audit trail
    }
}

$at = null;                                          // null = now (decided inside the transaction, from the database clock)
if ($rawAt !== '') {
    $at = parseOccurredAt($rawAt);
    if ($at === null) $errors['occurred_at'] = 'Enter a valid date and time.';
}

/* ---------- 4) save, all or nothing ---------- */
$fail = function (int $code, array $data) use ($conn): void {
    if ($conn->inTransaction()) $conn->rollBack();
    jsonOut($code, $data);
};

try {
    $conn->beginTransaction();

    // Lock the shipment row: two people (or a double click) cannot change it at the same moment
    $st = $conn->prepare("SELECT shipment_id, reference_no, client_id, assigned_staff_id, current_status, archived_at FROM shipments WHERE shipment_id = ? FOR UPDATE");
    $st->execute([$shipmentId]);
    $ship = $st->fetch();
    if (!$ship) $fail(404, ['error' => 'Shipment not found.']);
    if (!empty($ship['archived_at'])) $fail(409, ['error' => 'This shipment is archived. Reopen it before changing its status.']);

    $cur = stageByName($ship['current_status']);
    if ($cur === null) $fail(422, ['error' => 'This shipment has a status that is not in the stage list. Ask an administrator to check it.']);

    if ($expected !== '' && $expected !== $ship['current_status']) {
        $fail(409, ['error' => 'This shipment was just changed to ' . $ship['current_status'] . '. Check the new status and try again.']
            + statusPayload($conn, $shipmentId, $role));
    }

    $now = (string)$conn->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn();
    if ($at === null) $at = $now;
    if ($actualArrival !== null && $actualArrival > substr($now, 0, 10)) {
        $errors['actual_arrival_date'] = 'Actual arrival cannot be in the future.';
    }

    if (!isset($errors['occurred_at'])) {
        $last = $conn->prepare("SELECT MAX(updated_at) FROM shipment_status_updates WHERE shipment_id = ?");
        $last->execute([$shipmentId]);
        $lastAt = $last->fetchColumn();
        if (strtotime($at) > strtotime($now) + 300) {
            $errors['occurred_at'] = 'The date and time cannot be in the future.';
        } elseif ($lastAt && substr($at, 0, 16) < substr((string)$lastAt, 0, 16)) {
            $errors['occurred_at'] = 'The date and time cannot be earlier than the last update (' . fmtDateTime($lastAt) . ').';
        }
    }

    if ($target !== null) {
        $pos    = shipmentPosition($conn, $shipmentId, $cur);
        $errors = $errors + stageChangeErrors($cur, $pos, $target, $role, $correction, $remarks);   // field errors found above win
        if ($target['stage_name'] === 'On Hold' && !in_array($reason, ['Missing documents', 'Client request', 'Payment issue', 'Customs issue', 'Other'], true)) {
            $errors['reason'] = 'Select a reason for putting the shipment on hold.';
        }
    }
    if ($errors) $fail(422, ['error' => 'Please fix the highlighted fields.', 'errors' => $errors]);

    $timelineRemarks = $remarks;
    if ($target['stage_name'] === 'On Hold') {
        $timelineRemarks = 'Reason: ' . $reason . ($remarks !== '' ? ' — ' . $remarks : '');
        if (mb_strlen($timelineRemarks, 'UTF-8') > 255) $fail(422, ['error' => 'Please fix the highlighted fields.', 'errors' => ['remarks' => 'Reason and remarks together can be up to 255 characters.']]);
    }

    // a) the timeline row
    $conn->prepare(
        "INSERT INTO shipment_status_updates (shipment_id, stage, status, remarks, updated_by, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$shipmentId, $target['stage_name'], updateRowStatus($target, $correction), $timelineRemarks !== '' ? $timelineRemarks : null, $userId, $at]);

    // b) the shipment's current status
    $conn->prepare("UPDATE shipments SET current_status = ?, actual_arrival_date = ? WHERE shipment_id = ?")
         ->execute([$target['stage_name'], $actualArrival, $shipmentId]);

    // c) the transaction: pending -> ongoing -> completed (completed_at is cleared again if an admin reopens it)
    $st = $conn->prepare("SELECT transaction_id, status FROM transactions WHERE shipment_id = ? ORDER BY transaction_id DESC LIMIT 1 FOR UPDATE");
    $st->execute([$shipmentId]);
    $trx = $st->fetch();
    if (!$trx) {   // older shipment saved without one: create it so the history has a home
        $conn->prepare("INSERT INTO transactions (client_id, shipment_id, status) VALUES (?, ?, 'pending')")
             ->execute([(int)$ship['client_id'], $shipmentId]);
        $trx = ['transaction_id' => (int)$conn->lastInsertId(), 'status' => 'pending'];
    }
    $newTrx = transactionStatusFor($target, (string)$trx['status']);
    $conn->prepare("UPDATE transactions SET status = ?, completed_at = ? WHERE transaction_id = ?")
         ->execute([$newTrx, $newTrx === 'completed' ? $at : null, (int)$trx['transaction_id']]);

    // d) the transaction history
    $line = ($correction ? 'Stage corrected from ' : 'Moved from ') . $cur['stage_name'] . ' to ' . $target['stage_name']
          . ($timelineRemarks !== '' ? ' — ' . $timelineRemarks : '');
    $conn->prepare("INSERT INTO transaction_history (transaction_id, event_type, description, performed_by) VALUES (?, 'Status update', ?, ?)")
         ->execute([(int)$trx['transaction_id'], mb_substr($line, 0, 255), $userId]);

    // e) the activity log (inside the transaction, so it is rolled back together with the rest)
    logActivity($conn, 'Shipment Update',
        'Status of ' . $ship['reference_no'] . ($correction ? ' corrected from ' . $cur['stage_name'] . ' to ' : ' updated to ') . $target['stage_name']
        . ($target['stage_name'] === 'On Hold' ? ' (' . $reason . ')' : ''));
    createShipmentNotifications($conn, $ship, 'status', 'Shipment status updated', $ship['reference_no'] . ' is now ' . $target['stage_name'] . ($remarks !== '' ? ': ' . $remarks : ''), $userId);

    $conn->commit();
} catch (Throwable $ex) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('shipment_status failed: ' . $ex->getMessage());
    jsonOut(500, ['error' => 'The status could not be updated. Please try again.']);
}

/* ---------- 5) answer ---------- */
$emailSent = $notify ? notifyClientByEmail($conn, $ship, $target, $timelineRemarks) : false;

$where = ($target['stage_group'] !== '' && strcasecmp($target['stage_group'], $target['stage_name']) !== 0)
    ? $target['stage_group'] . ' · ' . $target['stage_name'] : $target['stage_name'];

try {
    $payload = statusPayload($conn, $shipmentId, $role);
} catch (Throwable $ex) {      // saved, but the redraw data failed: the page can reload itself
    error_log('shipment_status payload failed: ' . $ex->getMessage());
    jsonOut(200, ['ok' => true, 'reload' => true, 'message' => 'Status updated']);
}
jsonOut(200, [
    'ok'        => true,
    'message'   => $correction ? 'Status corrected' : 'Status updated',
    'detail'    => $ship['reference_no'] . ' is now ' . $where,
    'notify'    => $notify,
    'emailSent' => $emailSent,
] + $payload);
