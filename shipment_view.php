<?php
/**
 * shipment_view.php — Shipment Details, built from the Figma design
 * Files: shipment_view.php (this), function/shipment_common.php, assets/css/shipments.css, assets/js/shipments.js
 * Tables used: shipments, clients, users, shipment_status_updates, deliveries
 * Open it as shipment_view.php?id=<shipment_id>
 */
session_start();
require_once 'config/database.php';          // $conn (PDO)
require_once 'function/shipment_common.php';

[$userId, $fullName, $role] = requireStaffOrAdmin($conn);

/* ------------------------------------------------------------------
 * 1) LOAD THE SHIPMENT
 * ------------------------------------------------------------------ */
$id = (int)($_GET['id'] ?? 0);
$ship = false;
if ($id > 0) {
    $st = $conn->prepare(
        "SELECT s.*, c.client_name, u.full_name AS staff_name
         FROM shipments s
         JOIN clients c ON c.client_id = s.client_id
         LEFT JOIN users u ON u.user_id = s.assigned_staff_id
         WHERE s.shipment_id = ?"
    );
    $st->execute([$id]);
    $ship = $st->fetch();
}

$bellDot = hasRecentUpdate($conn);
$home    = homePage($role);

if (!$ship) {
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shipment not found | AstraTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff.css">
    <link rel="stylesheet" href="assets/css/shipments.css">
</head>
<body class="fig">
<div class="layout">
    <?php renderSidebar($role, 'shipments', $fullName); ?>
    <div class="fig-main">
        <?php renderTopnav([['Dashboard', $home], ['Shipment Management', 'shipments.php'], ['Shipment Details', null]], $fullName, $bellDot); ?>
        <main class="page">
            <section class="fcard">
                <h2 class="card-title">Shipment not found</h2>
                <p class="soft-text">This shipment does not exist or was removed.</p>
                <p><a class="btn btn-secondary" href="shipments.php">Back to Shipments</a></p>
            </section>
        </main>
    </div>
</div>
<script src="assets/js/shipments.js"></script>
</body>
</html>
    <?php
    exit;
}

/* ------------------------------------------------------------------
 * 2) RELATED DATA
 * ------------------------------------------------------------------ */
$st = $conn->prepare(
    "SELECT stage, remarks, updated_at FROM shipment_status_updates
     WHERE shipment_id = ? ORDER BY updated_at ASC, update_id ASC"
);
$st->execute([$id]);
$updates = $st->fetchAll();

$st = $conn->prepare("SELECT * FROM deliveries WHERE shipment_id = ? ORDER BY delivery_id DESC LIMIT 1");
$st->execute([$id]);
$delivery = $st->fetch();

$lastUpdated = $ship['created_at'];
foreach ($updates as $u) {
    if (strtotime($u['updated_at']) > strtotime($lastUpdated)) $lastUpdated = $u['updated_at'];
}

[$statusLabel, $statusClass] = statusInfo($ship['current_status']);

/* ------------------------------------------------------------------
 * 3) DELIVERY CARD
 * ------------------------------------------------------------------ */
$truckStatus = [
    'scheduled'  => ['Scheduled', 'amber'],
    'in_transit' => ['In Transit', 'teal'],
    'delivered'  => ['Delivered', 'green'],
    'cancelled'  => ['Cancelled', 'red'],
];
$dispatchNote = 'Not yet dispatched';
$truckBadge   = '<span class="val">—</span>';
$driverVehicle = 'To be assigned';
if ($delivery) {
    $ds = (string)$delivery['status'];
    if (isset($truckStatus[$ds])) $truckBadge = badge($truckStatus[$ds][0], $truckStatus[$ds][1]);
    $dispatchNote = ['scheduled' => 'Not yet dispatched', 'in_transit' => 'Dispatched', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'][$ds] ?? 'Not yet dispatched';

    $parts = array_filter([trim((string)$delivery['driver_name']), trim((string)$delivery['plate_no'])], function ($v) { return $v !== ''; });
    if ($parts) $driverVehicle = implode(' · ', $parts);
}

/* ------------------------------------------------------------------
 * 4) PROGRESS TIMELINE — the 5 stages of the design
 *    done = green check, current = blue dot, upcoming = grey ring
 * ------------------------------------------------------------------ */
$stages = [
    'created'   => 'Shipment Record Created',
    'pending'   => 'Pending Documents',
    'customs'   => 'Customs Processing',
    'transit'   => 'In Transit',
    'delivered' => 'Delivered',
];
$order = ['created' => 0, 'pending' => 1, 'customs' => 2, 'transit' => 3, 'delivered' => 4];

$latest = [];                                   // newest recorded update per stage
foreach ($updates as $u) {
    $k = statusKey($u['stage']);
    if ($k !== null) $latest[$k] = $u;
}
$curKey = statusKey($ship['current_status']);
if ($curKey === null) {                         // unfamiliar wording: use the furthest stage that has an update
    $curKey = 'pending';
    foreach ($order as $k => $i) {
        if ($k !== 'created' && isset($latest[$k])) $curKey = $k;
    }
}
$curIdx = $order[$curKey];

$timeline = [];
foreach ($stages as $key => $label) {
    $i = $order[$key];
    if ($key === 'created') {
        $timeline[] = [
            'label' => $label, 'state' => 'done',
            'date'  => fmtDateTime($ship['created_at']),
            'note'  => 'Record created' . ($ship['staff_name'] ? ' by ' . $ship['staff_name'] : '') . '.',
        ];
        continue;
    }
    if ($i < $curIdx || ($i === $curIdx && $key === 'delivered')) {
        $state = 'done';
    } elseif ($i === $curIdx) {
        $state = 'current';
    } else {
        $state = 'upcoming';
    }

    if ($state === 'upcoming') {
        $note = 'Not started.';
        if ($key === 'transit' && $delivery && $delivery['scheduled_date']) {
            $note = 'Not started. Trucking is scheduled for ' . date('M d', strtotime((string)$delivery['scheduled_date'])) . '.';
        }
        $timeline[] = ['label' => $label, 'state' => $state, 'date' => 'Awaiting update', 'note' => $note];
    } else {
        $u = $latest[$key] ?? null;
        $timeline[] = [
            'label' => $label, 'state' => $state,
            'date'  => $u ? fmtDate($u['updated_at']) : 'Date not recorded',
            'note'  => $u ? (string)$u['remarks'] : '',
        ];
    }
}

$hasRemarks = array_key_exists('remarks', $ship);

function dField(string $label, string $text): string
{
    return '<div class="dfield"><div class="lbl">' . e($label) . '</div><div class="val">' . e($text) . '</div></div>';
}
function dFieldHtml(string $label, string $html): string
{
    return '<div class="dfield"><div class="lbl">' . e($label) . '</div><div class="val">' . $html . '</div></div>';
}
function orDash($v): string { $v = trim((string)$v); return $v === '' ? '—' : $v; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($ship['reference_no']) ?> | Shipment Details | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff.css">
    <link rel="stylesheet" href="assets/css/shipments.css">
</head>
<body class="fig">

<div class="layout">

    <?php renderSidebar($role, 'shipments', $fullName); ?>

    <div class="fig-main">

        <?php renderTopnav([['Dashboard', $home], ['Shipment Management', 'shipments.php'], ['Shipment Details', null]], $fullName, $bellDot); ?>

        <main class="page">

            <div class="page-head">
                <div>
                    <h1>Shipment Details</h1>
                    <p class="ref-row">
                        <span class="soft-text">Shipment Reference:</span>
                        <strong><?= e($ship['reference_no']) ?></strong>
                        <?= badge($statusLabel, $statusClass) ?>
                    </p>
                </div>
                <div class="head-buttons">
                    <a class="btn btn-secondary" href="shipments.php">Back to Shipments</a>
                    <span class="btn btn-secondary is-disabled" aria-disabled="true" title="Editing comes in the next step">Edit Shipment</span>
                    <span class="btn btn-primary is-disabled" aria-disabled="true" title="Status updates come in the next step">Update Status</span>
                </div>
            </div>

            <div class="cols">

                <div class="col-left">

                    <section class="fcard">
                        <div class="card-head"><h2 class="card-title">Shipment Information</h2></div>
                        <div class="field-row">
                            <?= dField('Shipment Reference', $ship['reference_no']) ?>
                            <?= dField('Client Name', $ship['client_name']) ?>
                            <?= dField('Shipment Date', fmtDate($ship['created_at'])) ?>
                        </div>
                        <div class="field-row">
                            <?= dFieldHtml('Current Shipment Status', badge($statusLabel, $statusClass)) ?>
                            <?= dField('Assigned Staff', $ship['staff_name'] ?: 'Unassigned') ?>
                            <?= dField('Last Updated', fmtDate($lastUpdated)) ?>
                        </div>
                        <?php if ($hasRemarks): ?>
                            <div class="dfield">
                                <div class="lbl">Description / Remarks</div>
                                <p class="remarks"><?= e(orDash($ship['remarks'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="fcard">
                        <div class="card-head">
                            <h2 class="card-title">Delivery Information</h2>
                            <span class="soft-text small"><?= e($dispatchNote) ?></span>
                        </div>
                        <div class="field-row">
                            <?= dField('Trucking Company', orDash($delivery['trucker'] ?? '')) ?>
                            <?= dField('Pickup Schedule', fmtSchedule($delivery['scheduled_date'] ?? null)) ?>
                            <?= dFieldHtml('Trucking Status', $truckBadge) ?>
                        </div>
                        <?= dField('Delivery Address', orDash($delivery['delivery_address'] ?? '')) ?>
                        <div class="field-row">
                            <?= dField('Driver / Vehicle', $driverVehicle) ?>
                            <?= dField('Receiving Contact', orDash($delivery['receiving_contact'] ?? '')) ?>
                            <?= dField('Delivery Date', !empty($delivery['delivered_date']) ? fmtDate($delivery['delivered_date']) : 'To be confirmed') ?>
                        </div>
                    </section>

                </div>

                <section class="fcard progress">
                    <div class="card-head">
                        <h2 class="card-title">Shipment Progress</h2>
                        <span class="soft-text small">Based on recorded updates</span>
                    </div>
                    <ol class="timeline">
                        <?php foreach ($timeline as $t): ?>
                            <li class="tl-item tl-<?= e($t['state']) ?>">
                                <div class="tl-indicator">
                                    <span class="tl-dot"><?= $t['state'] === 'done' ? icon('check') : '' ?></span>
                                    <span class="tl-line"></span>
                                </div>
                                <div class="tl-text">
                                    <div class="tl-label">
                                        <span><?= e($t['label']) ?></span>
                                        <?php if ($t['state'] === 'current'): ?><?= badge('Current', 'blue') ?><?php endif; ?>
                                    </div>
                                    <div class="tl-date"><?= e($t['date']) ?></div>
                                    <?php if ($t['note'] !== ''): ?><p class="tl-note"><?= e($t['note']) ?></p><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>

            </div>
        </main>
    </div>
</div>

<script src="assets/js/shipments.js"></script>
</body>
</html>
