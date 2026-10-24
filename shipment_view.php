<?php
/**
 * shipment_view.php — Shipment Details, built from the Figma design
 * Files: shipment_view.php (this), function/shipment_common.php, assets/css/shipments.css, assets/js/shipments.js,
 *        shipment_status.php + assets/css/shipment_status.css + assets/js/shipment_status.js (Update Status modal)
 * Tables used: shipments, clients, users, shipment_status_updates, deliveries
 * Open it as shipment_view.php?id=<shipment_id>
 */
session_start();
require_once 'config/database.php';          // $conn (PDO)
require_once 'function/shipment_common.php';
require_once 'function/shipment_documents.php';

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

/* ------------------------------------------------------------------
 * 1b) REASSIGN (POST, admin only). Status changes go through shipment_status.php
 * ------------------------------------------------------------------ */
require_once 'function/log_activity.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$flash = '';
$customsFormOpen = false;
$customsFormValues = [];
$customsFormErrors = [];
$deliveryFormOpen = false;
$deliveryFormValues = [];
$deliveryFormErrors = [];
if ($ship && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign') {
    // Reassign (admin only): the "Reassign" link beside Assigned Staff
    if ($role !== 'admin') {
        $flash = 'Only an administrator can reassign a shipment.';
    } elseif (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $flash = 'Your session expired. Reload the page and try again.';
    } else {
        $newStaff = (int)($_POST['staff_id'] ?? 0);
        $chk = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? AND status = 'active' AND role = 'staff'");
        $chk->execute([$newStaff]);
        $target = $chk->fetch();
        if (!$target) {
            $flash = 'Choose an active staff member.';
        } else {
            try {
                $conn->prepare("UPDATE shipments SET assigned_staff_id = ? WHERE shipment_id = ?")->execute([$newStaff, $id]);
                logActivity($conn, 'Shipment Update', $ship['reference_no'] . ' reassigned from ' . ($ship['staff_name'] ?: 'Unassigned') . ' to ' . $target['full_name']);
                createShipmentNotifications($conn, array_merge($ship, ['assigned_staff_id' => $newStaff]), 'assignment', 'Shipment reassigned', $ship['reference_no'] . ' was assigned to ' . $target['full_name'] . '.', $userId);
                header('Location: shipment_view.php?id=' . $id . '&updated=1');
                exit;
            } catch (Throwable $ex) {
                error_log('shipment_view reassign failed: ' . $ex->getMessage());
                $flash = 'The shipment could not be reassigned.';
            }
        }
    }
} elseif ($ship && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_customs') {
    $customsFormOpen = true;
    $customsFormValues = $_POST;
    $customsId = (int)($_POST['customs_id'] ?? 0);
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $customsFormErrors['_form'] = 'Your session expired. Reload the page and try again.';
    }

    $postedDeclarationType = trim((string)($_POST['declaration_type'] ?? ''));
    $customDeclarationType = trim((string)($_POST['declaration_type_custom'] ?? ''));
    if ($postedDeclarationType === '__other__') $postedDeclarationType = $customDeclarationType;
    $customsInput = [
        'entry_no' => trim((string)($_POST['entry_no'] ?? '')),
        'declaration_type' => $postedDeclarationType,
        'lodgement_date' => trim((string)($_POST['lodgement_date'] ?? '')),
        'customs_value' => trim((string)($_POST['customs_value'] ?? '')),
        'duties_taxes_amount' => trim((string)($_POST['duties_taxes_amount'] ?? '')),
        'duties_paid_date' => trim((string)($_POST['duties_paid_date'] ?? '')),
        'assessment_status' => trim((string)($_POST['assessment_status'] ?? 'pending')),
        'examination_lane' => trim((string)($_POST['examination_lane'] ?? '')),
        'clearance_date' => trim((string)($_POST['clearance_date'] ?? '')),
        'customs_remarks' => trim((string)($_POST['customs_remarks'] ?? '')),
    ];

    if ($customsId > 0) {
        $check = $conn->prepare('SELECT customs_id FROM customs_processing WHERE customs_id = ? AND shipment_id = ?');
        $check->execute([$customsId, $id]);
        if (!$check->fetchColumn()) $customsFormErrors['_form'] = 'That customs record no longer belongs to this shipment.';
    }
    if (mb_strlen($customsInput['entry_no']) > 50) $customsFormErrors['entry_no'] = 'Entry number must be 50 characters or fewer.';
    if ($customsInput['declaration_type'] === '') $customsFormErrors['declaration_type'] = 'Select or enter a declaration type.';
    if (mb_strlen($customsInput['declaration_type']) > 40) $customsFormErrors['declaration_type'] = 'Declaration type must be 40 characters or fewer.';
    if (!in_array($customsInput['assessment_status'], ['pending', 'assessed', 'paid'], true)) $customsFormErrors['assessment_status'] = 'Select a valid assessment status.';
    if ($customsInput['examination_lane'] !== '' && !in_array($customsInput['examination_lane'], ['green', 'yellow', 'red'], true)) $customsFormErrors['examination_lane'] = 'Select a valid examination lane.';

    $parsedDates = [];
    foreach (['lodgement_date', 'duties_paid_date', 'clearance_date'] as $dateField) {
        $dateValue = $customsInput[$dateField];
        if ($dateValue === '') {
            $parsedDates[$dateField] = null;
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateValue, $parts) && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            $parsedDates[$dateField] = $dateValue;
        } else {
            $parsedDates[$dateField] = null;
            $customsFormErrors[$dateField] = 'Enter a valid date.';
        }
    }
    foreach (['customs_value', 'duties_taxes_amount'] as $amountField) {
        $amount = $customsInput[$amountField];
        if ($amount === '' && $amountField === 'duties_taxes_amount' && $customsInput['assessment_status'] !== 'paid') continue;
        if ($amount === '' || !preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $amount) || (float)$amount <= 0) {
            $customsFormErrors[$amountField] = 'Enter an amount greater than zero.';
        }
    }
    if ($customsInput['assessment_status'] === 'paid' && $parsedDates['duties_paid_date'] === null && $customsInput['duties_paid_date'] === '') {
        $customsFormErrors['duties_paid_date'] = 'Enter the date the duties were paid.';
    }
    if ($parsedDates['lodgement_date'] && $parsedDates['duties_paid_date'] && $parsedDates['duties_paid_date'] < $parsedDates['lodgement_date']) {
        $customsFormErrors['duties_paid_date'] = 'Duties paid date cannot precede the lodgement date.';
    }
    if ($parsedDates['clearance_date'] && $parsedDates['lodgement_date'] && $parsedDates['clearance_date'] < $parsedDates['lodgement_date']) {
        $customsFormErrors['clearance_date'] = 'Clearance date cannot precede the lodgement date.';
    }
    if ($parsedDates['clearance_date'] && $parsedDates['duties_paid_date'] && $parsedDates['clearance_date'] < $parsedDates['duties_paid_date']) {
        $customsFormErrors['clearance_date'] = 'Clearance date cannot precede the duties paid date.';
    }
    if (!$customsFormErrors) {
        try {
            $values = [
                $customsInput['entry_no'] !== '' ? $customsInput['entry_no'] : null,
                $customsInput['declaration_type'] !== '' ? $customsInput['declaration_type'] : null,
                $parsedDates['lodgement_date'],
                $customsInput['customs_value'],
                $customsInput['duties_taxes_amount'] !== '' ? $customsInput['duties_taxes_amount'] : null,
                $parsedDates['duties_paid_date'],
                $customsInput['assessment_status'],
                $customsInput['examination_lane'] !== '' ? $customsInput['examination_lane'] : null,
                $parsedDates['clearance_date'],
                $customsInput['customs_remarks'] !== '' ? $customsInput['customs_remarks'] : null,
                $userId,
            ];
            if ($customsId > 0) {
                $save = $conn->prepare('UPDATE customs_processing SET entry_no=?, declaration_type=?, lodgement_date=?, customs_value=?, duties_taxes_amount=?, duties_paid_date=?, assessment_status=?, examination_lane=?, clearance_date=?, customs_remarks=?, updated_by=? WHERE customs_id=? AND shipment_id=?');
                $save->execute([...$values, $customsId, $id]);
                $actionText = 'customs details updated';
            } else {
                $save = $conn->prepare('INSERT INTO customs_processing (entry_no, declaration_type, lodgement_date, customs_value, duties_taxes_amount, duties_paid_date, assessment_status, examination_lane, clearance_date, customs_remarks, updated_by, shipment_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $save->execute([...$values, $id]);
                $actionText = 'customs details added';
            }
            logActivity($conn, 'Shipment Update', $ship['reference_no'] . ' ' . $actionText);
            createShipmentNotifications($conn, $ship, 'customs', 'Customs details updated', $ship['reference_no'] . ' customs processing details were saved.', $userId);
            header('Location: shipment_view.php?id=' . $id . '&customs_saved=1#customs');
            exit;
        } catch (Throwable $ex) {
            error_log('shipment_view customs save failed: ' . $ex->getMessage());
            $customsFormErrors['_form'] = 'Customs details could not be saved. Please review the entries and try again.';
        }
    }
} elseif ($ship && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_delivery') {
    $deliveryFormOpen = true;
    $deliveryFormValues = $_POST;
    $deliveryId = (int)($_POST['delivery_id'] ?? 0);
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $deliveryFormErrors['_form'] = 'Your session expired. Reload the page and try again.';
    }
    if ($deliveryId > 0) {
        $check = $conn->prepare('SELECT delivery_id FROM deliveries WHERE delivery_id = ? AND shipment_id = ?');
        $check->execute([$deliveryId, $id]);
        if (!$check->fetchColumn()) $deliveryFormErrors['_form'] = 'That delivery record no longer belongs to this shipment.';
    }

    $deliveryInput = [
        'trucker' => trim((string)($_POST['trucker'] ?? '')),
        'driver_name' => trim((string)($_POST['driver_name'] ?? '')),
        'plate_no' => strtoupper(preg_replace('/\s+/', ' ', trim((string)($_POST['plate_no'] ?? '')))),
        'scheduled_date' => trim((string)($_POST['scheduled_date'] ?? '')),
        'scheduled_time' => trim((string)($_POST['scheduled_time'] ?? '')),
        'delivery_address' => trim((string)($_POST['delivery_address'] ?? '')),
        'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
        'contact_phone' => trim((string)($_POST['contact_phone'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? 'scheduled')),
        'delivered_date' => trim((string)($_POST['delivered_date'] ?? '')),
    ];
    foreach (['trucker' => 100, 'driver_name' => 100, 'plate_no' => 20, 'delivery_address' => 255, 'contact_name' => 100, 'contact_phone' => 30] as $field => $limit) {
        if (mb_strlen($deliveryInput[$field]) > $limit) $deliveryFormErrors[$field] = 'Must be ' . $limit . ' characters or fewer.';
    }
    if ($deliveryInput['plate_no'] !== '' && !preg_match('/^[A-Z]{3} ?\d{4}$/', $deliveryInput['plate_no'])) {
        $deliveryFormErrors['plate_no'] = 'Invalid format. Example: ABC 1234.';
    } elseif ($deliveryInput['plate_no'] !== '') {
        $deliveryInput['plate_no'] = preg_replace('/^([A-Z]{3}) ?(\d{4})$/', '$1 $2', $deliveryInput['plate_no']);
    }
    if (!in_array($deliveryInput['status'], ['scheduled', 'in_transit', 'delivered', 'cancelled'], true)) {
        $deliveryFormErrors['status'] = 'Select a valid delivery status.';
    }
    $validDeliveryDate = static function (string $value, string $field, array &$errors): ?string {
        if ($value === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) return $value;
        $errors[$field] = 'Enter a valid date.';
        return null;
    };
    $scheduledDate = $validDeliveryDate($deliveryInput['scheduled_date'], 'scheduled_date', $deliveryFormErrors);
    $deliveredDate = $validDeliveryDate($deliveryInput['delivered_date'], 'delivered_date', $deliveryFormErrors);
    if ($deliveryInput['scheduled_time'] !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $deliveryInput['scheduled_time'])) {
        $deliveryFormErrors['scheduled_time'] = 'Enter a valid pickup time.';
    }
    if ($deliveryInput['status'] === 'scheduled' && !$scheduledDate) $deliveryFormErrors['scheduled_date'] = 'Pickup schedule date is required when delivery is Scheduled.';
    if ($deliveryInput['status'] === 'delivered' && !$deliveredDate) $deliveryFormErrors['delivered_date'] = 'Delivered date is required when status is Delivered.';
    $contactParts = array_values(array_filter([$deliveryInput['contact_name'], $deliveryInput['contact_phone']], static function ($part) { return $part !== ''; }));
    $receivingContact = implode(' · ', $contactParts);
    if (mb_strlen($receivingContact) > 150) $deliveryFormErrors['contact_name'] = 'The combined contact details exceed the 150 character limit.';

    if (!$deliveryFormErrors) {
        try {
            $deliveryValues = [
                $deliveryInput['trucker'] !== '' ? $deliveryInput['trucker'] : null,
                $deliveryInput['plate_no'] !== '' ? $deliveryInput['plate_no'] : null,
                $deliveryInput['driver_name'] !== '' ? $deliveryInput['driver_name'] : null,
                $deliveryInput['delivery_address'] !== '' ? $deliveryInput['delivery_address'] : null,
                $receivingContact !== '' ? $receivingContact : null,
                $scheduledDate,
                $deliveryInput['scheduled_time'] !== '' ? $deliveryInput['scheduled_time'] : null,
                $deliveredDate,
                $deliveryInput['status'],
            ];
            if ($deliveryId > 0) {
                $save = $conn->prepare('UPDATE deliveries SET trucker=?, plate_no=?, driver_name=?, delivery_address=?, receiving_contact=?, scheduled_date=?, scheduled_time=?, delivered_date=?, status=? WHERE delivery_id=? AND shipment_id=?');
                $save->execute([...$deliveryValues, $deliveryId, $id]);
                $deliveryAction = 'delivery details updated';
            } else {
                $save = $conn->prepare('INSERT INTO deliveries (trucker, plate_no, driver_name, delivery_address, receiving_contact, scheduled_date, scheduled_time, delivered_date, status, shipment_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $save->execute([...$deliveryValues, $id]);
                $deliveryAction = 'delivery details scheduled';
            }
            logActivity($conn, 'Shipment Update', $ship['reference_no'] . ' ' . $deliveryAction);
            createShipmentNotifications($conn, $ship, 'delivery', 'Delivery details updated', $ship['reference_no'] . ' delivery details were saved.', $userId);
            header('Location: shipment_view.php?id=' . $id . '&delivery_saved=1#delivery');
            exit;
        } catch (Throwable $ex) {
            error_log('shipment_view delivery save failed: ' . $ex->getMessage());
            $deliveryFormErrors['_form'] = 'Delivery details could not be saved. Please review the entries and try again.';
        }
    }
}
// Update Status is no longer posted to this page: the modal calls shipment_status.php (AJAX).
if (isset($_GET['updated'])) $flash = 'Shipment updated.';
if (isset($_GET['shipment_saved'])) $flash = 'Shipment changes saved.';
if (isset($_GET['customs_saved'])) $flash = 'Customs details saved.';
if (isset($_GET['delivery_saved'])) $flash = 'Delivery details updated.';

$bellDot = hasRecentUpdate($conn);
$home    = homePage($role);
$listPage = shipListPage($role);   // admin: shipments.php, staff: staff_shipment.php

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
        <?php renderTopnav([['Dashboard', $home], ['Shipment Management', $listPage], ['Shipment Details', null]], $fullName, $bellDot); ?>
        <main class="page">
            <section class="fcard">
                <h2 class="card-title">Shipment not found</h2>
                <p class="soft-text">This shipment does not exist or was removed.</p>
                <p><a class="btn btn-secondary" href="<?= e($listPage) ?>">Back to Shipments</a></p>
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
$st = $conn->prepare("SELECT * FROM deliveries WHERE shipment_id = ? ORDER BY delivery_id DESC LIMIT 1");
$st->execute([$id]);
$delivery = $st->fetch();
$receivingContactName = '';
$receivingContactPhone = '';
if (!empty($delivery['receiving_contact'])) {
    $contactParts = explode(' · ', (string)$delivery['receiving_contact'], 2);
    $receivingContactName = $contactParts[0];
    $receivingContactPhone = $contactParts[1] ?? '';
}
$st = $conn->prepare("SELECT * FROM containers WHERE shipment_id = ? ORDER BY container_id");
$st->execute([$id]);
$containers = $st->fetchAll();
$st = $conn->prepare("SELECT cp.*, u.full_name AS updater_name FROM customs_processing cp LEFT JOIN users u ON u.user_id = cp.updated_by WHERE cp.shipment_id = ? ORDER BY cp.updated_at DESC, cp.customs_id DESC");
$st->execute([$id]);
$customsRows = $st->fetchAll();
$st = $conn->prepare("SELECT d.document_id, d.doc_type, d.file_path, d.remarks, d.uploaded_at, u.full_name AS uploader_name FROM shipment_documents d LEFT JOIN users u ON u.user_id = d.uploaded_by WHERE d.shipment_id = ? ORDER BY d.uploaded_at DESC, d.document_id DESC");
$st->execute([$id]);
$documents = $st->fetchAll();
$availableDocuments = array_values(array_filter($documents, function ($document) { return documentAbsolutePath($document['file_path']) !== false; }));
$documentChecklist = documentChecklist($availableDocuments);
$availableDocumentCount = count($availableDocuments);

$progress    = buildProgress($conn, $ship, staffCreatedNote($ship));   // stepper + timeline items (function/shipment_common.php)
$lastUpdated = $progress['lastUpdated'];

[$statusLabel, $statusClass] = statusInfo($ship['current_status']);
$currentStageMeta = stageByName($ship['current_status']);
$isArchived = !empty($ship['archived_at']);
$isShipmentClosed = $currentStageMeta && !empty($currentStageMeta['is_closed']);
$movementLabel = $ship['mode'] === 'land' ? 'Truck Plate' : ($ship['mode'] === 'air' ? 'Airline / Flight' : 'Vessel / Voyage');
$documentNumberLabel = $ship['mode'] === 'land' ? 'Waybill No.' : ($ship['mode'] === 'air' ? 'AWB No.' : 'B/L No.');
$originLabel = $ship['mode'] === 'air' ? 'Airport of Departure' : ($ship['mode'] === 'land' ? 'Origin' : 'Port of Loading');
$destinationLabel = $ship['mode'] === 'air' ? 'Airport of Arrival' : ($ship['mode'] === 'land' ? 'Destination' : 'Port of Discharge');

// Staff list for admin reassignment and the latest activity-log lines for this shipment
$staffList = [];
$audit     = [];
if ($role === 'admin') {
    $staffList = $conn->query("SELECT u.user_id, u.full_name, COUNT(s.shipment_id) AS open_shipments
        FROM users u LEFT JOIN shipments s ON s.assigned_staff_id = u.user_id
          AND LOWER(TRIM(s.current_status)) NOT IN ('delivered','cancelled')
        WHERE u.role = 'staff' AND u.status = 'active' GROUP BY u.user_id, u.full_name ORDER BY u.full_name")->fetchAll();
}
if (in_array($role, ['admin', 'staff'], true)) {
    $audit = auditRows($conn, $ship['reference_no']);
}

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
 * 4) UPDATE STATUS MODAL DATA — only the stages this person may pick (see function/shipment_stages.php)
 * ------------------------------------------------------------------ */
$modalState = statusModalState($conn, $ship, $role);

function dField(string $label, string $text): string
{
    return '<div class="dfield"><div class="lbl">' . e($label) . '</div><div class="val">' . e($text) . '</div></div>';
}
function dFieldHtml(string $label, string $html): string
{
    return '<div class="dfield"><div class="lbl">' . e($label) . '</div><div class="val">' . $html . '</div></div>';
}
function collapseHead(string $title, string $summary, string $id): string
{
    return '<div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">' . e($title) . '</h2><p>' . e($summary) . '</p></div>'
        . '<button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="' . e($id) . '">Show less</button></div>';
}
function orDash($v): string { $v = trim((string)$v); return $v === '' ? '—' : $v; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($ship['reference_no']) ?> | Shipment Details | AstraTrack</title>
    <meta name="csrf" content="<?= e($_SESSION['csrf']) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff.css">
    <link rel="stylesheet" href="assets/css/shipments.css">
    <link rel="stylesheet" href="assets/css/shipment_status.css?v=20261024-2">
    <link rel="stylesheet" href="assets/css/customs_processing.css?v=20261024-1">
    <link rel="stylesheet" href="assets/css/shipment_details.css?v=20261024-4">
</head>
<body class="fig">

<div class="layout">

    <?php renderSidebar($role, 'shipments', $fullName); ?>

    <div class="fig-main">

        <?php renderTopnav([['Dashboard', $home], ['Shipment Management', $listPage], ['Shipment Details', null]], $fullName, $bellDot); ?>

        <main class="page">

            <div class="page-head">
                <div>
                    <h1>Shipment Details</h1>
                    <p class="ref-row">
                        <span class="soft-text">Shipment Reference:</span>
                        <strong><?= e($ship['reference_no']) ?></strong>
                        <span class="status-badge-slot" data-status-badge><?= badge($statusLabel, $statusClass) ?></span>
                    </p>
                </div>
                <div class="head-buttons">
                    <a class="btn btn-secondary" href="<?= e($listPage) ?>"><?= icon('left') ?>Back to Shipments</a>
                    <a class="btn btn-secondary" href="shipment_summary.php?id=<?= (int)$ship['shipment_id'] ?>"><?= icon('download') ?>Print Summary</a>
                    <?php if (!$isArchived && (!$isShipmentClosed || $role === 'admin')): ?><a class="btn btn-secondary" href="shipment_edit.php?id=<?= (int)$ship['shipment_id'] ?>"><?= icon('edit') ?>Edit Shipment</a><?php endif; ?>
                    <div class="more head-more">
                        <button type="button" class="btn btn-secondary btn-more" data-more aria-haspopup="true" aria-expanded="false" aria-label="More actions">&#8943;</button>
                        <div class="more-menu" role="menu" hidden>
                            <button role="menuitem" type="button" data-copy="<?= e($ship['reference_no']) ?>">Copy reference</button>
                            <?php if ($role === 'admin' && ($isArchived || $isShipmentClosed || $ship['current_status'] === 'On Hold')): ?><button role="menuitem" type="button" data-lifecycle-action="reopen">Reopen shipment</button><?php endif; ?>
                            <?php if (!$isArchived && !$isShipmentClosed && $ship['current_status'] !== 'On Hold'): ?><button role="menuitem" type="button" data-lifecycle-action="hold">Put on hold</button><?php endif; ?>
                            <?php if ($role === 'admin' && !$isArchived && !$isShipmentClosed): ?><button role="menuitem" type="button" data-lifecycle-action="cancel">Cancel shipment</button><?php endif; ?>
                            <?php if ($role === 'admin' && !$isArchived && $isShipmentClosed): ?><button role="menuitem" type="button" data-lifecycle-action="archive">Archive shipment</button><?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$isArchived): ?><button class="btn btn-primary" type="button" data-status-open aria-haspopup="dialog"><?= icon('refresh') ?>Update Status</button><?php endif; ?>
                </div>
            </div>

            <?php if ($flash !== ''): ?><p class="flash<?= (isset($_GET['delivery_saved']) || isset($_GET['shipment_saved'])) ? ' delivery-flash' : '' ?>" role="status"><?= e($flash) ?></p><?php endif; ?>
            <?php if ($isArchived): ?><div class="shipment-archived-banner"><strong>Archived shipment</strong><span>This record is hidden from active shipment lists. Its shipment and billing history is retained.</span><?php if ($role === 'admin'): ?><button type="button" data-lifecycle-action="reopen">Restore and reopen</button><?php endif; ?></div><?php endif; ?>

            <section class="fcard stepper-card" aria-label="Shipment stages">
                <ol class="stepper" id="stepperList"><?= renderStepperItems($progress['items']) ?></ol>
            </section>

            <div class="cols">

                <div class="col-left">

                    <section class="fcard collapse-card">
                        <?= collapseHead('Shipment Information', $ship['reference_no'] . ' · ' . $ship['client_name'], 'shipmentInfoMore') ?>
                        <div class="collapse-body" id="shipmentInfoMore">
                        <div class="field-row">
                            <?= dField('Shipment Reference', $ship['reference_no']) ?>
                            <?= dField('Client Name', $ship['client_name']) ?>
                            <?= dField('Shipment Date', fmtDate($ship['created_at'])) ?>
                        </div>
                        <div class="field-row">
                            <?= dFieldHtml('Current Shipment Status', '<span class="status-badge-slot" data-status-badge>' . badge($statusLabel, $statusClass) . '</span>') ?>
                            <?php if ($role === 'admin'): ?>
                                <?= dFieldHtml('Assigned Staff',
                                    '<span class="assigned">'
                                    . ($ship['staff_name']
                                        ? '<span class="staff-chip"><span class="chip-av">' . e(initialsOf($ship['staff_name'])) . '</span>' . e($ship['staff_name']) . '</span>'
                                        : '<span class="soft-text">Unassigned</span>')
                                    . ' <button type="button" class="link" data-open-dialog="reassignDialog">Reassign</button></span>') ?>
                            <?php else: ?>
                                <?= dField('Assigned Staff', $ship['staff_name'] ?: 'Unassigned') ?>
                            <?php endif; ?>
                            <?= dFieldHtml('Last Updated', '<span data-last-updated>' . e(fmtDate($lastUpdated)) . '</span>') ?>
                        </div>
                        <?= dFieldHtml('Actual Arrival', '<span data-actual-arrival>' . e(fmtDate($ship['actual_arrival_date'] ?? null)) . '</span>') ?>
                        <div class="dfield">
                            <div class="lbl">Description / Remarks<?php if (isset($_GET['shipment_saved'])): ?> <span class="updated-marker">UPDATED</span><?php endif; ?></div>
                            <p class="remarks"><?= e(orDash($ship['remarks'])) ?></p>
                        </div>
                        </div>
                    </section>

                    <section class="fcard collapse-card" id="key-dates">
                        <?= collapseHead('Key Dates', !empty($ship['eta_date']) ? 'ETA ' . fmtDate($ship['eta_date']) : 'No arrival or delivery dates set', 'keyDatesMore') ?>
                        <div class="collapse-body" id="keyDatesMore"><div class="field-row">
                            <?= dField('Estimated Arrival (ETA)', fmtDate($ship['eta_date'] ?? null)) ?>
                            <?= dField('First Free Day', fmtDate($ship['first_free_day'] ?? null)) ?>
                            <?= dField('Delivery Due Date', fmtDate($ship['delivery_due_date'] ?? null)) ?>
                            <?= dField('Actual Arrival', fmtDate($ship['actual_arrival_date'] ?? null)) ?>
                        </div><p class="soft-text small">Upcoming ETA and overdue delivery dates are highlighted in Shipment Management.</p></div>
                    </section>

                    <section class="fcard collapse-card">
                        <?= collapseHead('Cargo and Route', ucfirst((string)$ship['transaction_type']) . ' · ' . ucfirst((string)$ship['mode']) . ' · ' . orDash($ship['port_of_loading']) . ' → ' . orDash($ship['port_of_discharge']), 'cargoMore') ?>
                        <div class="collapse-body" id="cargoMore">
                        <div class="field-row">
                            <?= dField('Transaction Type · Mode', ucfirst((string)$ship['transaction_type']) . ' · ' . ucfirst((string)$ship['mode'])) ?>
                            <?= dField($documentNumberLabel, orDash($ship['bl_awb_no'])) ?>
                            <?= dField($movementLabel, orDash($ship['vessel_or_flight'])) ?>
                        </div>
                        <div class="field-row">
                            <?= dField('Shipper', orDash($ship['shipper_name'])) ?>
                            <?= dField('Consignee', orDash($ship['consignee_name'])) ?>
                            <?= dField('HS Code', orDash($ship['hs_code'])) ?>
                        </div>
                        <div class="field-row">
                            <?= dField($originLabel, orDash($ship['port_of_loading'])) ?>
                            <?= dField($destinationLabel, orDash($ship['port_of_discharge'])) ?>
                            <?= dField('Packages · Gross Weight · CBM', orDash($ship['total_packages']) . ' · ' . orDash($ship['total_gross_weight']) . ' kg · ' . orDash($ship['measurement_cbm']) . ' CBM') ?>
                        </div>
                        <div class="field-row"><?= dField('Incoterm', orDash($ship['incoterm'])) ?></div>
                        </div>
                    </section>

                    <section class="fcard collapse-card">
                        <?= collapseHead('Invoice and Values', orDash($ship['invoice_no']) . ' · ' . orDash($ship['currency']) . ' ' . orDash($ship['invoice_value']), 'invoiceMore') ?>
                        <div class="collapse-body" id="invoiceMore">
                            <div class="field-row field-row-3">
                                <?= dField('Invoice No.', orDash($ship['invoice_no'])) ?><?= dField('Currency', orDash($ship['currency'])) ?><?= dField('Invoice Value', orDash($ship['invoice_value'])) ?>
                            </div><div class="field-row field-row-3"><?= dField('Freight Amount', orDash($ship['freight_amount'])) ?><?= dField('FOB Value', orDash($ship['fob_value'])) ?></div>
                        </div>
                    </section>

                    <section class="fcard collapse-card">
                        <?= collapseHead('Containers', count($containers) . ' container' . (count($containers) === 1 ? '' : 's') . ($containers ? ' · ' . orDash($containers[0]['size_type']) . ' · ' . orDash($containers[0]['container_no']) : ''), 'containersMore') ?>
                        <div class="collapse-body" id="containersMore">
                        <?php if ($containers): ?><div class="table-wrap"><table class="ship-table"><thead><tr><th>Container No.</th><th>Seal No.</th><th>Size / Type</th></tr></thead><tbody>
                            <?php foreach ($containers as $container): ?><tr><td><?= e($container['container_no']) ?></td><td><?= e(orDash($container['seal_no'])) ?></td><td><?= e(orDash($container['size_type'])) ?></td></tr><?php endforeach; ?>
                        </tbody></table></div><?php else: ?><div class="empty-containers"><?= icon('box') ?><span>No containers for this <?= e(strtolower((string)$ship['mode'])) ?> shipment.</span></div><?php endif; ?>
                        </div>
                    </section>

                    <section class="fcard collapse-card" id="customs">
                        <div class="collapse-head">
                            <div class="collapse-head-text"><h2 class="card-title">Customs Processing</h2><p><?= $customsRows ? e(orDash($customsRows[0]['entry_no']) . ' · ' . ucfirst((string)$customsRows[0]['assessment_status']) . ' assessment · ' . ($customsRows[0]['examination_lane'] ? ucfirst((string)$customsRows[0]['examination_lane']) . ' lane' : 'Lane not assigned')) : 'Not yet filed' ?></p></div>
                            <div class="collapse-actions"><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="customsMore">Show less</button>
                            <?php if ($customsRows): ?><button class="link" type="button" data-customs-edit data-customs="<?= e(json_encode($customsRows[0], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) ?>">Edit</button><?php else: ?><button class="link" type="button" data-customs-add>Add details</button><?php endif; ?></div>
                        </div>
                        <div class="collapse-body" id="customsMore">
                        <?php if ($customsRows): foreach ($customsRows as $customs): ?>
                            <?php $assessmentColor = ['pending' => 'amber', 'assessed' => 'blue', 'paid' => 'green'][$customs['assessment_status']] ?? 'blue'; ?>
                            <div class="shipment-record">
                                <div class="customs-record-head">
                                    <span class="soft-text small">Updated <?= e(fmtDateTime($customs['updated_at'])) ?><?= !empty($customs['updater_name']) ? ' · ' . e($customs['updater_name']) : '' ?></span>
                                    <button class="link" type="button" data-customs-edit data-customs="<?= e(json_encode($customs, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) ?>">Edit</button>
                                </div>
                                <div class="field-row">
                                    <?= dField('Entry No.', orDash($customs['entry_no'])) ?><?= dField('Declaration Type', orDash($customs['declaration_type'])) ?><?= dField('Lodgement Date', fmtDate($customs['lodgement_date'])) ?>
                                </div><div class="field-row">
                                    <?= dFieldHtml('Assessment Status', badge(ucfirst((string)$customs['assessment_status']), $assessmentColor)) ?><?= dField('Examination Lane', $customs['examination_lane'] ? ucfirst((string)$customs['examination_lane']) : '—') ?><?= dField('Clearance Date', fmtDate($customs['clearance_date'])) ?>
                                </div><div class="field-row">
                                    <?= dField('Customs Value', $customs['customs_value'] !== null ? money($customs['customs_value']) : '—') ?><?= dField('Duties and Taxes', $customs['duties_taxes_amount'] !== null ? money($customs['duties_taxes_amount']) : '—') ?><?= dField('Duties Paid Date', fmtDate($customs['duties_paid_date'])) ?>
                                </div>
                                <?php if (trim((string)$customs['customs_remarks']) !== ''): ?><p class="soft-text"><?= e($customs['customs_remarks']) ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; else: ?><div class="customs-empty"><span class="customs-empty-icon" aria-hidden="true">＋</span><div><strong>Not yet filed</strong><p class="soft-text">Add the customs declaration, assessment and clearance details when available.</p></div></div><?php endif; ?>
                        </div>
                    </section>

                    <section class="fcard collapse-card shipment-documents-card">
                        <div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">Documents</h2><p><?= count($documents) ?> <?= count($documents) === 1 ? 'document' : 'documents' ?> · <?= $documentChecklist['requiredReceived'] ?>/<?= $documentChecklist['requiredTotal'] ?> required received</p></div><div class="collapse-actions"><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="documentsMore">Show less</button><button type="button" class="link" data-documents-open>Upload document</button></div></div>
                        <div class="collapse-body" id="documentsMore">
                            <div class="document-checklist" aria-label="Required document checklist">
                                <?php foreach ($documentChecklist['slots'] as $slot): ?><div class="document-check-row <?= $slot['received'] ? 'is-received' : 'is-missing' ?>"><span class="document-check-icon" aria-hidden="true"><?= $slot['received'] ? '✓' : '•' ?></span><span><?= e($slot['label']) ?><?= !$slot['required'] ? ' <small>(optional)</small>' : '' ?></span><strong><?= $slot['received'] ? ($slot['count'] > 1 ? $slot['count'] . ' files' : 'Received') : ($slot['required'] ? 'Missing' : 'Not provided') ?></strong></div><?php endforeach; ?>
                            </div>
                            <?php if ($documents): ?><div class="document-table-wrap"><table class="document-table"><thead><tr><th>Document Type</th><th>File Name</th><th>Size</th><th>Uploaded By</th><th>Date</th><th>Actions</th></tr></thead><tbody><?php foreach ($documents as $document): ?>
                                <?php $documentPath = documentAbsolutePath($document['file_path']); $documentAvailable = $documentPath !== false; ?>
                                <tr><td><?= e($document['doc_type']) ?><?php if (!empty($document['remarks'])): ?><small class="document-remarks"><?= e($document['remarks']) ?></small><?php endif; ?></td><td class="document-filename"><?= e(basename((string)$document['file_path'])) ?><?php if (!$documentAvailable): ?><small class="document-unavailable">File unavailable</small><?php endif; ?></td><td><?= $documentAvailable ? e(formatFileSize(filesize($documentPath))) : '—' ?></td><td><?= e($document['uploader_name'] ?: '—') ?></td><td><?= e(fmtDate($document['uploaded_at'])) ?></td><td><div class="document-actions"><?php if ($documentAvailable): ?><a class="btn-sm btn-sm-view" href="shipment_document.php?id=<?= (int)$document['document_id'] ?>&amp;view=1" target="_blank" rel="noopener">View</a><a class="btn-sm" href="shipment_document.php?id=<?= (int)$document['document_id'] ?>">Download</a><?php else: ?><span class="soft-text small">Unavailable</span><?php endif; ?><?php if ($role === 'admin'): ?><button type="button" class="document-delete" data-document-delete="<?= (int)$document['document_id'] ?>" data-file-name="<?= e(basename((string)$document['file_path'])) ?>">Delete</button><?php endif; ?></div></td></tr>
                            <?php endforeach; ?></tbody></table></div><?php else: ?><div class="document-empty"><strong>No documents uploaded yet</strong><p>Upload the shipment paperwork to track required documents.</p></div><?php endif; ?>
                            <div class="document-footer"><span><?= $availableDocumentCount ?> <?= $availableDocumentCount === 1 ? 'file' : 'files' ?> available · <?= $documentChecklist['requiredReceived'] ?> of <?= $documentChecklist['requiredTotal'] ?> required document types received</span><?php if ($availableDocumentCount): ?><a href="shipment_documents_download.php?shipment_id=<?= (int)$ship['shipment_id'] ?>">Download available documents</a><?php endif; ?></div>
                        </div>
                    </section>

                    <section class="fcard collapse-card" id="delivery">
                        <div class="collapse-head">
                            <div class="collapse-head-text"><h2 class="card-title">Delivery Information</h2><p><?= $delivery ? e(orDash($delivery['trucker']) . ' · ' . ($truckStatus[$delivery['status']][0] ?? 'Scheduled') . ' · ' . fmtSchedule($delivery['scheduled_date'] ?? null, $delivery['scheduled_time'] ?? null)) : 'Not yet scheduled' ?></p></div>
                            <div class="collapse-actions"><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="deliveryMore">Show less</button>
                            <?php if ($delivery): ?><button class="link" type="button" data-delivery-edit data-delivery="<?= e(json_encode($delivery, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) ?>">Edit</button><?php else: ?><button class="link" type="button" data-delivery-add>Schedule delivery</button><?php endif; ?></div>
                        </div>
                        <div class="collapse-body" id="deliveryMore">
                            <?php if ($delivery): ?>
                                <div class="field-row">
                                    <?= dField('Trucking Company', orDash($delivery['trucker'])) ?>
                                    <?= dField('Pickup Schedule', fmtSchedule($delivery['scheduled_date'] ?? null, $delivery['scheduled_time'] ?? null)) ?>
                                    <?= dFieldHtml('Delivery Status', $truckBadge) ?>
                                </div>
                                <?= dField('Delivery Address', orDash($delivery['delivery_address'])) ?>
                                <div class="field-row">
                                    <?= dField('Driver Name', orDash($delivery['driver_name'])) ?>
                                    <?= dField('Plate No.', orDash($delivery['plate_no'])) ?>
                                    <?= dField('Receiving Contact', orDash($delivery['receiving_contact'])) ?>
                                </div>
                                <?= dField('Delivered Date', !empty($delivery['delivered_date']) ? fmtDate($delivery['delivered_date']) : 'To be confirmed') ?>
                            <?php else: ?><div class="customs-empty"><span class="customs-empty-icon" aria-hidden="true">↗</span><div><strong>Delivery not scheduled</strong><p class="soft-text">No trucking or delivery details have been recorded.</p></div><button class="btn btn-primary" type="button" data-delivery-add>Schedule delivery</button></div><?php endif; ?>
                        </div>
                    </section>

                </div>

                <div class="col-right">
                <section class="fcard progress">
                    <div class="card-head">
                        <h2 class="card-title">Shipment Progress</h2>
                        <span class="soft-text small">Based on recorded updates</span>
                    </div>
                    <ol class="timeline" id="timelineList"><?= renderTimelineItems($progress['items']) ?></ol>
                </section>

                <?php if (in_array($role, ['admin', 'staff'], true)): ?>
                    <section class="fcard audit">
                        <div class="card-head">
                            <h2 class="card-title">Audit Trail</h2>
                            <button class="link audit-open" type="button" data-audit-open aria-haspopup="dialog">View all</button>
                        </div>
                        <div id="auditBody"><?= renderAuditBody($audit) ?></div>
                    </section>
                <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</div>

<dialog class="sdlg" id="statusDialog" aria-labelledby="statusDlgTitle"
        data-endpoint="shipment_status.php" data-state="<?= e(json_encode($modalState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
    <div class="sdlg-head">
        <div>
            <h2 id="statusDlgTitle">Update Shipment Status</h2>
            <p class="sdlg-sub">Shipment <strong id="sfRef"><?= e($ship['reference_no']) ?></strong> <span id="sfCurrentBadge"></span></p>
        </div>
        <button type="button" class="sdlg-x" data-status-close aria-label="Close">&times;</button>
    </div>

    <form class="sdlg-form" id="statusForm" novalidate autocomplete="off">
        <input type="hidden" name="shipment_id" value="<?= (int)$ship['shipment_id'] ?>">
        <div class="sdlg-body">
            <div class="sf-banner" id="sfBanner" role="alert" hidden></div>

            <?php if ($role === 'admin'): ?>
            <label class="sf-correct" id="sfCorrectWrap">
                <span class="sf-switch"><input type="checkbox" id="sfCorrect" name="correction_toggle" role="switch"><span></span></span>
                <span><strong>Correct a previous stage</strong><small>Admin only. Allows moving backwards and makes remarks mandatory.</small></span>
            </label>
            <?php endif; ?>

            <div class="sf-field" data-field="stage">
                <label class="sf-label" for="sfStage"><span id="sfStageLabel">New Stage <span class="req">*</span></span></label>
                <select class="select" id="sfStage" name="stage"></select>
                <div class="sf-help" id="sfCurrent"></div>
                <div class="sf-note" id="sfNote" hidden></div>
                <div class="sf-note sf-warn" id="sfWarn" hidden>Correcting a stage updates the timeline and is recorded in the Audit Trail.</div>
            </div>

            <div class="sf-field" data-field="occurred_at">
                <label class="sf-label" for="sfAt">Date and Time <span class="req">*</span><span class="hint">Defaults to now</span></label>
                <input type="datetime-local" id="sfAt" name="occurred_at" step="60">
            </div>

            <div class="sf-field" data-field="actual_arrival_date">
                <label class="sf-label" for="sfArrival">Actual Arrival <span class="hint">Optional</span></label>
                <input type="date" id="sfArrival" name="actual_arrival_date">
                <div class="sf-help">Record the shipment's arrival date. Leave blank if it has not arrived.</div>
            </div>

            <div class="sf-field" data-field="reason" id="sfReasonField" hidden>
                <label class="sf-label" for="sfReason">Reason <span class="req">*</span></label>
                <select class="select" id="sfReason" name="reason">
                    <option value="">Select a reason</option>
                    <option value="Missing documents">Missing documents</option>
                    <option value="Client request">Client request</option>
                    <option value="Payment issue">Payment issue</option>
                    <option value="Customs issue">Customs issue</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="sf-field" data-field="remarks">
                <label class="sf-label" for="sfRemarks">Remarks <span class="req" id="sfRemarksReq" hidden>*</span><span class="hint" id="sfRemarksHint">Optional</span></label>
                <textarea id="sfRemarks" name="remarks" maxlength="255" rows="3" placeholder="Add a note for the shipment timeline..."></textarea>
                <div class="sf-meta"><span class="sf-help">Visible in the timeline and Audit Trail.</span><span class="sf-counter" id="sfCounter">0 / 255</span></div>
            </div>

            <label class="sf-notify">
                <input type="checkbox" id="sfNotify" name="notify" value="1" checked>
                <span><strong>Notify client</strong><small>Sends an email and shows the update in the client&rsquo;s timeline.</small></span>
            </label>
        </div>
        <div class="sdlg-foot">
            <button type="button" class="btn btn-secondary" id="sfCancel" data-status-close>Cancel</button>
            <button type="submit" class="btn btn-primary" id="sfSave">Save Update</button>
        </div>
    </form>

    <div class="sdlg-form" id="statusConfirm" hidden>
        <div class="sdlg-body">
            <div class="sf-confirm">
                <span class="sf-confirm-ico"><?= icon('check') ?></span>
                <h3 id="sfConfirmText">Mark this shipment as Delivered?</h3>
                <p>This marks the shipment as completed.</p>
                <div class="sf-flow" id="sfConfirmFlow"></div>
                <p class="sf-small" id="sfConfirmSmall"></p>
            </div>
        </div>
        <div class="sdlg-foot">
            <button type="button" class="btn btn-secondary" id="sfConfirmBack">Back</button>
            <button type="button" class="btn btn-primary" id="sfConfirmYes">Yes, mark as Delivered</button>
        </div>
    </div>
</dialog>

<?php if ($role === 'admin'): ?>
<dialog class="dlg" id="reassignDialog" aria-labelledby="reassignDlgTitle">
    <form method="post" action="shipment_view.php?id=<?= (int)$ship['shipment_id'] ?>" autocomplete="off" id="reassignForm" data-current-staff="<?= (int)($ship['assigned_staff_id'] ?? 0) ?>">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="reassign">
        <input type="hidden" name="staff_id" id="reassignStaffId" value="">
        <section id="reassignPickerPanel">
            <header class="assign-detail-head"><div><h2 id="reassignDlgTitle">Reassign staff</h2><p>Active staff only</p></div><button type="button" data-close-dialog aria-label="Close">&times;</button></header>
            <label class="assign-detail-search"><span class="sr-only">Search staff</span><input type="search" id="reassignSearch" placeholder="Search staff"></label>
            <div class="assign-detail-list">
                <?php foreach ($staffList as $sf): ?>
                    <label class="assign-detail-option" data-reassign-staff data-name="<?= e(mb_strtolower($sf['full_name'])) ?>">
                        <input type="radio" name="staff_choice" value="<?= (int)$sf['user_id'] ?>" data-name-label="<?= e($sf['full_name']) ?>" data-open-count="<?= (int)$sf['open_shipments'] ?>">
                        <span class="assignment-avatar"><?= e(initialsOf($sf['full_name'])) ?></span>
                        <span class="assignment-staff-info"><strong><?= e($sf['full_name']) ?></strong><?php if ((int)$sf['user_id'] === (int)$ship['assigned_staff_id']): ?><small>Current assignment</small><?php else: ?><small><?= (int)$sf['open_shipments'] ?> open shipments</small><?php endif; ?></span>
                        <span class="assignment-selected">Selected</span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if (!$staffList): ?><div class="assignment-no-staff"><strong>No active staff</strong><p>Activate a staff account in User Management to make an assignment.</p></div><?php endif; ?>
            <footer class="assignment-foot"><button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button><button type="button" class="btn btn-primary" id="reassignContinue" disabled>Continue</button></footer>
        </section>
        <section id="reassignConfirmPanel" hidden>
            <div class="reassign-confirm-head"><h2>Reassign staff?</h2><p><?= e($ship['reference_no']) ?> · <?= e($ship['client_name']) ?></p></div>
            <div class="reassign-compare"><div><small>Current staff</small><strong id="reassignCurrentName"><?= e($ship['staff_name'] ?: 'Unassigned') ?></strong></div><span aria-hidden="true">→</span><div><small>New staff</small><strong id="reassignNewName">—</strong></div></div>
            <p class="reassign-confirm-copy">This shipment will be reassigned. Its current shipment status will stay the same; only the staff assignment will change.</p>
            <div class="dlg-actions"><button type="button" class="btn btn-secondary" id="reassignBack">Back</button><button type="submit" class="btn btn-primary" id="reassignSave">Reassign</button></div>
        </section>
    </form>
</dialog>
<?php endif; ?>

<dialog class="document-dialog" id="documentsDialog" aria-labelledby="documentsDialogTitle" data-upload-endpoint="shipment_documents_upload.php" data-shipment-id="<?= (int)$ship['shipment_id'] ?>">
    <form id="documentsUploadForm" enctype="multipart/form-data">
        <header class="document-dialog-head"><div><h2 id="documentsDialogTitle">Upload shipment documents</h2><p><?= e($ship['reference_no']) ?> · Add one or more files</p></div><button type="button" data-documents-close aria-label="Close">&times;</button></header>
        <div class="document-dialog-body">
            <label class="document-dropzone" id="documentDropzone"><input type="file" id="documentFiles" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx" multiple><strong>Choose files or drag them here</strong><span>PDF, JPG, PNG, XLS, XLSX · Up to 10 MB per file</span></label>
            <div class="document-queue" id="documentQueue" aria-live="polite"><p class="document-queue-empty">No files selected.</p></div>
            <p class="document-upload-message" id="documentUploadMessage" role="status" hidden></p>
        </div>
        <footer class="document-dialog-foot"><span id="documentUploadProgress">Uploads are recorded in the shipment audit trail.</span><div><button type="button" class="btn btn-secondary" data-documents-close>Cancel</button><button type="submit" class="btn btn-primary" id="documentUploadSubmit" disabled>Upload documents</button></div></footer>
    </form>
</dialog>

<dialog class="lifecycle-dialog" id="shipmentActionDialog" aria-labelledby="shipmentActionTitle" data-endpoint="shipment_lifecycle.php" data-shipment-id="<?= (int)$ship['shipment_id'] ?>">
    <form id="shipmentActionForm">
        <input type="hidden" name="action" id="shipmentActionValue"><input type="hidden" name="shipment_id" value="<?= (int)$ship['shipment_id'] ?>">
        <header><div><h2 id="shipmentActionTitle">Shipment action</h2><p><?= e($ship['reference_no']) ?> · <?= e($ship['client_name']) ?></p></div><button type="button" data-lifecycle-close aria-label="Close">&times;</button></header>
        <div class="lifecycle-body"><p id="shipmentActionPrompt"></p>
            <label id="lifecycleReasonField" hidden>Reason <span class="required-mark">*</span><select name="reason" id="lifecycleReason"><option value="">Select a reason</option><option>Missing documents</option><option>Client request</option><option>Payment issue</option><option>Customs issue</option><option>Operational issue</option><option>Other</option></select></label>
            <label id="lifecycleRemarksField">Remarks <span class="optional-mark">(optional)</span><textarea name="remarks" id="lifecycleRemarks" maxlength="255" rows="3" placeholder="Add a note for the shipment record"></textarea></label>
            <p class="lifecycle-error" id="lifecycleError" role="alert" hidden></p>
        </div>
        <footer><button type="button" class="btn btn-secondary" data-lifecycle-close>Cancel</button><button type="submit" class="btn btn-primary" id="lifecycleSubmit">Continue</button></footer>
    </form>
</dialog>

<dialog class="customs-dialog" id="customsDialog" aria-labelledby="customsDialogTitle" data-auto-open="<?= $customsFormOpen ? '1' : '0' ?>">
    <form method="post" action="shipment_view.php?id=<?= (int)$ship['shipment_id'] ?>#customs" id="customsForm" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save_customs">
        <input type="hidden" name="customs_id" id="customsId" value="<?= (int)($customsFormValues['customs_id'] ?? 0) ?>">
        <header class="customs-dialog-head">
            <div><h2 id="customsDialogTitle">Add Customs Details</h2><p><?= e($ship['reference_no']) ?> · <?= e($ship['client_name']) ?></p></div>
            <button type="button" class="customs-dialog-close" data-customs-close aria-label="Close">&times;</button>
        </header>
        <div class="customs-dialog-body">
            <?php if (isset($customsFormErrors['_form'])): ?><div class="customs-error-banner" role="alert"><?= e($customsFormErrors['_form']) ?></div><?php endif; ?>
            <div class="customs-form-grid">
                <label>Entry No.<input name="entry_no" type="text" maxlength="50" value="<?= e((string)($customsFormValues['entry_no'] ?? '')) ?>" placeholder="e.g. C-2026-0148"><?php if (isset($customsFormErrors['entry_no'])): ?><small class="customs-field-error"><?= e($customsFormErrors['entry_no']) ?></small><?php endif; ?></label>
                <?php $knownDeclarationTypes = ['Import · Consumption', 'Import · Warehousing', 'Export', 'Other']; $customDeclarationPosted = ($customsFormValues['declaration_type'] ?? '') === '__other__'; ?>
                <label>Declaration Type<select name="declaration_type" id="customsDeclarationType" required><option value="">Select type</option><?php foreach ($knownDeclarationTypes as $declarationType): ?><option value="<?= e($declarationType) ?>"<?= ($customsFormValues['declaration_type'] ?? '') === $declarationType ? ' selected' : '' ?>><?= e($declarationType) ?></option><?php endforeach; ?><option value="__other__"<?= $customDeclarationPosted ? ' selected' : '' ?>>Other / enter custom value</option></select><input type="text" name="declaration_type_custom" id="customsDeclarationTypeCustom" maxlength="40" placeholder="Enter declaration type" value="<?= e((string)($customsFormValues['declaration_type_custom'] ?? '')) ?>"<?= $customDeclarationPosted ? '' : ' hidden' ?>><?php if (isset($customsFormErrors['declaration_type'])): ?><small class="customs-field-error"><?= e($customsFormErrors['declaration_type']) ?></small><?php endif; ?></label>
                <label>Lodgement Date<input name="lodgement_date" type="date" value="<?= e((string)($customsFormValues['lodgement_date'] ?? '')) ?>"><?php if (isset($customsFormErrors['lodgement_date'])): ?><small class="customs-field-error"><?= e($customsFormErrors['lodgement_date']) ?></small><?php endif; ?></label>
                <label>Assessment Status<select name="assessment_status" id="customsAssessmentStatus"><?php foreach (['pending' => 'Pending', 'assessed' => 'Assessed', 'paid' => 'Paid'] as $value => $label): ?><option value="<?= $value ?>"<?= ($customsFormValues['assessment_status'] ?? 'pending') === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><?php if (isset($customsFormErrors['assessment_status'])): ?><small class="customs-field-error"><?= e($customsFormErrors['assessment_status']) ?></small><?php endif; ?></label>
                <label>Customs Value (PHP)<input name="customs_value" type="number" min="0.01" step="0.01" value="<?= e((string)($customsFormValues['customs_value'] ?? '')) ?>" placeholder="0.00" required><?php if (isset($customsFormErrors['customs_value'])): ?><small class="customs-field-error"><?= e($customsFormErrors['customs_value']) ?></small><?php endif; ?></label>
                <label>Duties and Taxes (PHP)<input name="duties_taxes_amount" type="number" min="0.01" step="0.01" value="<?= e((string)($customsFormValues['duties_taxes_amount'] ?? '')) ?>" placeholder="Add when assessed"><?php if (isset($customsFormErrors['duties_taxes_amount'])): ?><small class="customs-field-error"><?= e($customsFormErrors['duties_taxes_amount']) ?></small><?php endif; ?></label>
                <label>Duties Paid Date<input name="duties_paid_date" type="date" value="<?= e((string)($customsFormValues['duties_paid_date'] ?? '')) ?>"><?php if (isset($customsFormErrors['duties_paid_date'])): ?><small class="customs-field-error"><?= e($customsFormErrors['duties_paid_date']) ?></small><?php endif; ?></label>
                <label>Clearance Date<input name="clearance_date" type="date" value="<?= e((string)($customsFormValues['clearance_date'] ?? '')) ?>"><?php if (isset($customsFormErrors['clearance_date'])): ?><small class="customs-field-error"><?= e($customsFormErrors['clearance_date']) ?></small><?php endif; ?></label>
                <fieldset class="customs-lane"><legend>Examination Lane</legend><?php foreach (['green' => 'Green', 'yellow' => 'Yellow', 'red' => 'Red'] as $value => $label): ?><label><input type="radio" name="examination_lane" value="<?= $value ?>"<?= ($customsFormValues['examination_lane'] ?? '') === $value ? ' checked' : '' ?>><span><?= $label ?></span></label><?php endforeach; ?><label><input type="radio" name="examination_lane" value=""<?= empty($customsFormValues['examination_lane']) ? ' checked' : '' ?>><span>Not set</span></label><?php if (isset($customsFormErrors['examination_lane'])): ?><small class="customs-field-error"><?= e($customsFormErrors['examination_lane']) ?></small><?php endif; ?></fieldset>
                <label class="customs-remarks">Customs Remarks<textarea name="customs_remarks" rows="3" placeholder="Add a note for customs processing"><?= e((string)($customsFormValues['customs_remarks'] ?? '')) ?></textarea></label>
            </div>
            <p class="customs-form-note">Dates must follow the processing order: lodgement, duties paid, then clearance.</p>
        </div>
        <footer class="customs-dialog-foot"><span>Admin and Staff access only</span><div><button type="button" class="btn btn-secondary" data-customs-close>Cancel</button><button type="submit" class="btn btn-primary">Save</button></div></footer>
    </form>
</dialog>

<dialog class="delivery-dialog" id="deliveryDialog" aria-labelledby="deliveryDialogTitle" data-auto-open="<?= $deliveryFormOpen ? '1' : '0' ?>">
    <form method="post" action="shipment_view.php?id=<?= (int)$ship['shipment_id'] ?>#delivery" id="deliveryForm" autocomplete="off" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save_delivery">
        <input type="hidden" name="delivery_id" value="<?= (int)($deliveryFormValues['delivery_id'] ?? 0) ?>">
        <header class="delivery-dialog-head"><div><h2 id="deliveryDialogTitle">Edit Delivery Details</h2><p><?= e($ship['reference_no']) ?> · <?= e($ship['client_name']) ?></p></div><button type="button" data-delivery-close aria-label="Close">&times;</button></header>
        <div class="delivery-dialog-body">
            <?php if (isset($deliveryFormErrors['_form'])): ?><div class="delivery-form-error" role="alert"><?= e($deliveryFormErrors['_form']) ?></div><?php endif; ?>
            <div class="delivery-form-grid">
                <label>Trucking Company<input name="trucker" type="text" maxlength="100" value="<?= e((string)($deliveryFormValues['trucker'] ?? '')) ?>" placeholder="Trucking company"><?php if (isset($deliveryFormErrors['trucker'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['trucker']) ?></small><?php endif; ?></label>
                <label>Driver Name<input name="driver_name" type="text" maxlength="100" value="<?= e((string)($deliveryFormValues['driver_name'] ?? '')) ?>" placeholder="Driver name"><?php if (isset($deliveryFormErrors['driver_name'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['driver_name']) ?></small><?php endif; ?></label>
                <label>Plate No.<input name="plate_no" type="text" maxlength="20" value="<?= e((string)($deliveryFormValues['plate_no'] ?? '')) ?>" placeholder="ABC 1234"><?php if (isset($deliveryFormErrors['plate_no'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['plate_no']) ?></small><?php endif; ?></label>
                <div class="delivery-schedule"><label>Pickup Schedule · Date<input name="scheduled_date" type="date" value="<?= e((string)($deliveryFormValues['scheduled_date'] ?? '')) ?>"><?php if (isset($deliveryFormErrors['scheduled_date'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['scheduled_date']) ?></small><?php endif; ?></label><label>Time<input name="scheduled_time" type="time" value="<?= e((string)($deliveryFormValues['scheduled_time'] ?? '')) ?>"><?php if (isset($deliveryFormErrors['scheduled_time'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['scheduled_time']) ?></small><?php endif; ?></label></div>
                <label class="delivery-wide">Delivery Address<textarea name="delivery_address" rows="2" maxlength="255" placeholder="Delivery address"><?= e((string)($deliveryFormValues['delivery_address'] ?? '')) ?></textarea><?php if (isset($deliveryFormErrors['delivery_address'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['delivery_address']) ?></small><?php endif; ?></label>
                <label>Receiving Contact · Name<input name="contact_name" type="text" maxlength="100" value="<?= e((string)($deliveryFormValues['contact_name'] ?? '')) ?>" placeholder="Contact name"><?php if (isset($deliveryFormErrors['contact_name'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['contact_name']) ?></small><?php endif; ?></label>
                <label>Receiving Contact · Phone<input name="contact_phone" type="tel" maxlength="30" value="<?= e((string)($deliveryFormValues['contact_phone'] ?? '')) ?>" placeholder="Phone number"><?php if (isset($deliveryFormErrors['contact_phone'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['contact_phone']) ?></small><?php endif; ?></label>
                <fieldset class="delivery-status"><legend>Delivery Status</legend><?php foreach ($truckStatus as $value => [$label, $color]): ?><label><input type="radio" name="status" value="<?= e($value) ?>"<?= ($deliveryFormValues['status'] ?? 'scheduled') === $value ? ' checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></fieldset>
                <?php if (isset($deliveryFormErrors['status'])): ?><small class="delivery-field-error delivery-wide"><?= e($deliveryFormErrors['status']) ?></small><?php endif; ?>
                <label class="delivery-wide" id="deliveredDateField">Delivered Date<input name="delivered_date" type="date" value="<?= e((string)($deliveryFormValues['delivered_date'] ?? '')) ?>"><?php if (isset($deliveryFormErrors['delivered_date'])): ?><small class="delivery-field-error"><?= e($deliveryFormErrors['delivered_date']) ?></small><?php endif; ?></label>
            </div>
        </div>
        <footer class="delivery-dialog-foot"><span id="deliverySavingNote">Admin and Staff access only</span><div><button type="button" class="btn btn-secondary" data-delivery-close>Cancel</button><button type="submit" class="btn btn-primary" id="deliverySave">Save</button></div></footer>
    </form>
</dialog>

<dialog class="audit-drawer" id="auditDialog" aria-labelledby="auditTitle" data-shipment-id="<?= (int)$ship['shipment_id'] ?>" data-endpoint="shipment_audit.php">
    <header class="audit-drawer-head"><div><h2 id="auditTitle">Audit Trail, <?= e($ship['reference_no']) ?></h2><p><?= $role === 'admin' ? 'Admin activity log' : 'Shipment activity log' ?> · Newest first</p></div><button type="button" data-audit-close aria-label="Close audit trail">&times;</button></header>
    <nav class="audit-filters" aria-label="Filter shipment activity" role="tablist">
        <button type="button" class="active" role="tab" aria-selected="true" data-audit-filter="all">All</button>
        <button type="button" role="tab" aria-selected="false" data-audit-filter="status">Status</button>
        <button type="button" role="tab" aria-selected="false" data-audit-filter="documents">Documents</button>
        <button type="button" role="tab" aria-selected="false" data-audit-filter="edits">Edits</button>
    </nav>
    <section class="audit-drawer-content" id="auditList" aria-live="polite"><p class="audit-loading">Loading shipment activity…</p></section>
    <div class="audit-empty" id="auditEmpty" hidden><span aria-hidden="true">◷</span><strong>No activity yet.</strong><p>Recorded shipment activity will appear here.</p></div>
    <p class="audit-error" id="auditError" hidden role="alert"></p>
    <footer class="audit-drawer-foot"><button type="button" id="auditLoadMore" hidden>Load more</button></footer>
</dialog>

<script src="assets/js/shipments.js"></script>
<script src="assets/js/shipment_status.js?v=20261024-2"></script>
<script src="assets/js/customs_processing.js?v=20261024-1"></script>
<script src="assets/js/shipment_details.js?v=20261024-2"></script>
<script src="assets/js/shipment_audit.js?v=20261024-1"></script>
<script src="assets/js/shipment_documents_manager.js?v=20261024-1"></script>
<script src="assets/js/shipment_lifecycle.js?v=20261024-1"></script>
</body>
</html>
