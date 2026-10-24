<?php
/**
 * clint_shipment.php — CLIENT "My Shipments" (list) and Shipment Details (read-only), from the Figma design
 * Files: clint_shipment.php (this), assets/css/clint_shipment.css, assets/js/clint_shipment.js, function/shipment_common.php
 * Tables used: users, clients, shipments, shipment_status_updates, deliveries, shipment_documents, v_billing_balance
 *
 *   clint_shipment.php          -> My Shipments (list)
 *   clint_shipment.php?id=12    -> Shipment Details (read-only)
 *   shipment_documents_download.php?shipment_id=12 -> Download Documents
 * The details branch is the established client layout.
 *
 * Clients can only see their own shipments. There is no Add / Edit / Update here.
 */
session_start();
require_once 'config/database.php';          // $conn (PDO)
require_once 'function/shipment_common.php';
require_once 'function/shipment_documents.php';

[$userId, $clientId, $displayName] = requireClient($conn);

$id = (int)($_GET['id'] ?? 0);

/* ==================================================================
 * Bell dot: a status update in the last 7 days on one of MY shipments
 * ================================================================== */
$st = $conn->prepare(
    "SELECT COUNT(*) FROM shipment_status_updates u JOIN shipments s ON s.shipment_id = u.shipment_id
     WHERE s.client_id = ? AND u.updated_at >= (NOW() - INTERVAL 7 DAY)"
);
$st->execute([$clientId]);
$bellDot = (int)$st->fetchColumn() > 0;

$crumbHome = ['Dashboard', 'client_dashbroad.php'];

/* ==================================================================
 * DETAILS
 * ================================================================== */
if ($id > 0) {

    $st = $conn->prepare("SELECT s.* FROM shipments s WHERE s.shipment_id = ? AND s.client_id = ?");
    $st->execute([$id, $clientId]);
    $ship = $st->fetch();

    if (!$ship) {
        http_response_code(404);
        renderClientHead('Shipment not found');
        ?>
<body class="fig">
<div class="layout">
    <?php renderClientSidebar('shipments', $displayName); ?>
    <div class="fig-main">
        <?php renderTopnav([$crumbHome, ['My Shipments', 'clint_shipment.php'], ['Shipment Details', null]], $displayName, $bellDot); ?>
        <main class="page">
            <section class="fcard">
                <h2 class="card-title">Shipment not found</h2>
                <p class="soft-text">This shipment does not exist or is not on your account.</p>
                <p><a class="btn btn-secondary" href="clint_shipment.php">Back to My Shipments</a></p>
            </section>
        </main>
    </div>
</div>
<script src="assets/js/clint_shipment.js"></script>
</body>
</html>
        <?php
        exit;
    }

    /* ---------- documents ---------- */
    $st = $conn->prepare("SELECT document_id, doc_type, file_path, remarks FROM shipment_documents WHERE shipment_id = ? ORDER BY uploaded_at DESC, document_id DESC");
    $st->execute([$id]);
    $shipmentDocuments = $st->fetchAll();
    $docFiles = [];
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    foreach ($shipmentDocuments as $d) {
        $real = realpath(__DIR__ . '/' . ltrim((string)$d['file_path'], '/\\'));
        // only files that really sit inside /uploads
        if ($uploadsRoot && $real && is_file($real) && strpos($real, $uploadsRoot . DIRECTORY_SEPARATOR) === 0) {
            $docFiles[] = ['id' => (int)$d['document_id'], 'type' => $d['doc_type'], 'path' => $real];
        }
    }
    $documentChecklist = documentChecklist(array_map(function ($document) { return ['doc_type' => $document['type']]; }, $docFiles));

    if (isset($_GET['download'])) {
        if (!$docFiles) {
            http_response_code(404);
            exit('No documents available for this shipment.');
        }
        $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $ship['reference_no']);
        if (count($docFiles) === 1 || !class_exists('ZipArchive')) {
            $f = $docFiles[0]['path'];
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($f) . '"');
            header('Content-Length: ' . filesize($f));
            readfile($f);
            exit;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ast');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($docFiles as $i => $f) $zip->addFile($f['path'], ($i + 1) . '_' . basename($f['path']));
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $safeRef . '_documents.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /* ---------- related data ---------- */
    $st = $conn->prepare("SELECT * FROM deliveries WHERE shipment_id = ? ORDER BY delivery_id DESC LIMIT 1");
    $st->execute([$id]);
    $delivery = $st->fetch();

    $st = $conn->prepare("SELECT container_no, seal_no, size_type FROM containers WHERE shipment_id = ? ORDER BY container_id");
    $st->execute([$id]);
    $containers = $st->fetchAll();

    $st = $conn->prepare("SELECT assessment_status, examination_lane, clearance_date, updated_at FROM customs_processing WHERE shipment_id = ? ORDER BY updated_at DESC, customs_id DESC LIMIT 1");
    $st->execute([$id]);
    $customsRows = $st->fetchAll();

    $st = $conn->prepare("SELECT billing_no, total_amount, amount_paid, payment_status FROM v_billing_balance WHERE shipment_id = ? AND client_id = ? ORDER BY billing_id");
    $st->execute([$id, $clientId]);
    $bills = $st->fetchAll();

    // Same builder as shipment_view.php: every row saved by Update Status (with its remarks) shows here, read-only
    $progress    = buildProgress($conn, $ship, 'Your shipment record was created.');
    $lastUpdated = $progress['lastUpdated'];
    [$statusLabel, $statusClass] = statusInfo($ship['current_status']);

    /* ---------- delivery ---------- */
    $truckStatus = ['scheduled' => ['Scheduled', 'amber'], 'in_transit' => ['In Transit', 'teal'], 'delivered' => ['Delivered', 'green'], 'cancelled' => ['Cancelled', 'red']];
    $dispatchNote  = 'Not yet dispatched';
    $truckBadge    = '<span class="val">—</span>';
    $driverVehicle = 'To be assigned';
    if ($delivery) {
        $ds = (string)$delivery['status'];
        if (isset($truckStatus[$ds])) $truckBadge = badge($truckStatus[$ds][0], $truckStatus[$ds][1]);
        $dispatchNote = ['scheduled' => 'Not yet dispatched', 'in_transit' => 'Dispatched', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'][$ds] ?? 'Not yet dispatched';
        $parts = array_filter([trim((string)$delivery['driver_name']), trim((string)$delivery['plate_no'])], function ($v) { return $v !== ''; });
        if ($parts) $driverVehicle = implode(' · ', $parts);
    }

    /* ---------- billing summary ---------- */
    $billing = null;
    if ($bills) {
        $due = 0; $paid = 0;
        foreach ($bills as $b) { $due += (float)$b['total_amount']; $paid += (float)$b['amount_paid']; }
        $pay = ($paid >= $due && $due > 0) ? ['Paid', 'green'] : ($paid > 0 ? ['Partially Paid', 'amber'] : ['Unpaid', 'red']);
        $billing = [
            'no'   => count($bills) === 1 ? $bills[0]['billing_no'] : count($bills) . ' invoices',
            'due'  => $due, 'paid' => $paid, 'pay' => $pay,
        ];
    }

    $typeMode = ucfirst((string)$ship['transaction_type']) . ' · ' . ucfirst((string)$ship['mode']);
    $movementLabel = $ship['mode'] === 'land' ? 'Truck Plate' : ($ship['mode'] === 'air' ? 'Airline / Flight' : 'Vessel / Voyage');
    $route    = trim((string)$ship['port_of_loading']) !== '' || trim((string)$ship['port_of_discharge']) !== ''
              ? orDash($ship['port_of_loading']) . ' → ' . orDash($ship['port_of_discharge']) : '—';

    renderClientHead($ship['reference_no'] . ' | Shipment Details');
    ?>
<body class="fig">

<div class="layout">

    <?php renderClientSidebar('shipments', $displayName); ?>

    <div class="fig-main">

        <?php renderTopnav([$crumbHome, ['My Shipments', 'clint_shipment.php'], ['Shipment Details', null]], $displayName, $bellDot); ?>

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
                    <a class="btn btn-secondary" href="clint_shipment.php"><?= icon('left') ?>Back to My Shipments</a>
                    <?php if ($docFiles): ?>
                        <a class="btn btn-secondary" href="shipment_documents_download.php?shipment_id=<?= (int)$ship['shipment_id'] ?>"><?= icon('download') ?>Download Documents</a>
                    <?php else: ?>
                        <span class="btn btn-secondary is-disabled" aria-disabled="true" title="No documents available yet"><?= icon('download') ?>Download Documents</span>
                    <?php endif; ?>
                </div>
            </div>

            <section class="fcard stepper-card" aria-label="Shipment stages">
                <ol class="stepper"><?= renderStepperItems($progress['items']) ?></ol>
            </section>

            <div class="cols">

                <div class="col-left">

                    <section class="fcard collapse-card">
                        <?= collapseHead('Shipment Information', $ship['reference_no'] . ' · ' . $displayName . ' · ' . orDash($ship['bl_awb_no']), 'clientShipmentInfoMore') ?>
                        <div class="collapse-body" id="clientShipmentInfoMore">
                        <div class="field-row">
                            <?= dField('Shipment Reference', $ship['reference_no']) ?>
                            <?= dField('Shipment Date', fmtDate($ship['created_at'])) ?>
                            <?= dFieldHtml('Current Shipment Status', badge($statusLabel, $statusClass)) ?>
                        </div>
                        <div class="field-row">
                            <?= dField('Type · Mode', $typeMode) ?>
                            <?= dField('B/L Number', orDash($ship['bl_awb_no'])) ?>
                            <?= dField('Actual Arrival', fmtDate($ship['actual_arrival_date'] ?? null)) ?>
                        </div>
                        <?= dField('Last Updated', fmtDate($lastUpdated)) ?>
                        <?= dField('Route', $route) ?>
                        </div>
                    </section>

                    <section class="fcard collapse-card" id="key-dates">
                        <?= collapseHead('Key Dates', !empty($ship['eta_date']) ? 'ETA ' . fmtDate($ship['eta_date']) : 'No arrival or delivery dates set', 'clientKeyDatesMore') ?>
                        <div class="collapse-body" id="clientKeyDatesMore"><div class="field-row">
                            <?= dField('Estimated Arrival (ETA)', fmtDate($ship['eta_date'] ?? null)) ?>
                            <?= dField('First Free Day', fmtDate($ship['first_free_day'] ?? null)) ?>
                            <?= dField('Delivery Due Date', fmtDate($ship['delivery_due_date'] ?? null)) ?>
                            <?= dField('Actual Arrival', fmtDate($ship['actual_arrival_date'] ?? null)) ?>
                        </div></div>
                    </section>

                    <section class="fcard collapse-card">
                        <?= collapseHead('Cargo and Route', $typeMode . ' · ' . $route, 'clientCargoMore') ?>
                        <div class="collapse-body" id="clientCargoMore">
                        <div class="field-row">
                            <?= dField('Shipper', orDash($ship['shipper_name'])) ?>
                            <?= dField('Consignee', orDash($ship['consignee_name'])) ?>
                            <?= dField($movementLabel, orDash($ship['vessel_or_flight'])) ?>
                        </div>
                        <div class="field-row">
                            <?= dField('HS Code', orDash($ship['hs_code'])) ?>
                            <?= dField('Packages', orDash($ship['total_packages'])) ?>
                            <?= dField('Gross Weight · CBM', orDash($ship['total_gross_weight']) . ' kg · ' . orDash($ship['measurement_cbm']) . ' CBM') ?>
                        </div>
                        <div class="field-row">
                            <?= dField('Incoterm', orDash($ship['incoterm'])) ?>
                            <?= dField('Invoice No.', orDash($ship['invoice_no'])) ?>
                            <?= dField('Currency', orDash($ship['currency'])) ?>
                        </div>
                        <div class="field-row field-row-3">
                            <?= dField('Invoice Value', orDash($ship['invoice_value'])) ?>
                            <?= dField('Freight Amount', orDash($ship['freight_amount'])) ?>
                            <?= dField('FOB Value', orDash($ship['fob_value'])) ?>
                        </div>
                        </div>
                    </section>

                    <section class="fcard collapse-card">
                        <?= collapseHead('Containers', count($containers) . ' container' . (count($containers) === 1 ? '' : 's') . ($containers ? ' · ' . orDash($containers[0]['size_type']) . ' · ' . orDash($containers[0]['container_no']) : ''), 'clientContainersMore') ?>
                        <div class="collapse-body" id="clientContainersMore">
                        <?php if ($containers): ?><div class="table-wrap"><table class="ship-table"><thead><tr><th>Container No.</th><th>Seal No.</th><th>Size / Type</th></tr></thead><tbody>
                            <?php foreach ($containers as $container): ?><tr><td><?= e($container['container_no']) ?></td><td><?= e(orDash($container['seal_no'])) ?></td><td><?= e(orDash($container['size_type'])) ?></td></tr><?php endforeach; ?>
                        </tbody></table></div><?php else: ?><div class="empty-containers"><?= icon('box') ?><span>No containers for this <?= e(strtolower((string)$ship['mode'])) ?> shipment.</span></div><?php endif; ?>
                        </div>
                    </section>

                    <section class="fcard collapse-card">
                        <?= collapseHead('Customs Status', $customsRows ? ucfirst((string)$customsRows[0]['assessment_status']) . ' assessment · ' . ($customsRows[0]['examination_lane'] ? ucfirst((string)$customsRows[0]['examination_lane']) . ' lane' : 'Lane not assigned') . ' · ' . (!empty($customsRows[0]['clearance_date']) ? fmtDate($customsRows[0]['clearance_date']) : 'Awaiting clearance') : 'Status not available yet', 'clientCustomsMore') ?>
                        <div class="collapse-body" id="clientCustomsMore">
                        <?php if ($customsRows): $customs = $customsRows[0]; $assessmentColor = ['pending' => 'amber', 'assessed' => 'blue', 'paid' => 'green'][$customs['assessment_status']] ?? 'blue'; ?>
                            <div class="field-row field-row-3">
                                <?= dFieldHtml('Assessment Status', badge(ucfirst((string)$customs['assessment_status']), $assessmentColor)) ?><?= dField('Examination Lane', $customs['examination_lane'] ? ucfirst((string)$customs['examination_lane']) : 'Not assigned') ?><?= dField('Clearance Date', fmtDate($customs['clearance_date'])) ?>
                            </div>
                            <p class="soft-text small customs-client-note">Customs status is read-only. Declaration values and duties are not shown here.</p>
                        <?php else: ?><p class="soft-text">Customs status is not available yet.</p><?php endif; ?>
                        </div>
                    </section>

                    <section class="fcard collapse-card">
                        <?= collapseHead('Documents', count($docFiles) . ' documents available · ' . $documentChecklist['requiredReceived'] . '/' . $documentChecklist['requiredTotal'] . ' required received', 'clientDocumentsMore') ?>
                        <div class="collapse-body" id="clientDocumentsMore">
                        <div class="document-checklist" aria-label="Required document checklist">
                            <?php foreach ($documentChecklist['slots'] as $slot): ?><div class="document-check-row <?= $slot['received'] ? 'is-received' : 'is-missing' ?>"><span class="document-check-icon" aria-hidden="true"><?= $slot['received'] ? '✓' : '•' ?></span><span><?= e($slot['label']) ?><?= !$slot['required'] ? ' <small>(optional)</small>' : '' ?></span><strong><?= $slot['received'] ? ($slot['count'] > 1 ? $slot['count'] . ' files' : 'Received') : ($slot['required'] ? 'Missing' : 'Not provided') ?></strong></div><?php endforeach; ?>
                        </div>
                        <?php if ($docFiles): ?><ul class="shipment-doc-list"><?php foreach ($docFiles as $document): ?><li><strong><?= e($document['type']) ?></strong><span><?= e(basename($document['path'])) ?></span><span class="document-actions"><a class="btn-sm" href="shipment_document.php?id=<?= (int)$document['id'] ?>"><?= icon('download') ?>Download</a></span></li><?php endforeach; ?></ul>
                        <?php else: ?><p class="soft-text">No documents are available yet.</p><?php endif; ?>
                        <p class="soft-text small">Documents are read-only. Use Download Documents above to retrieve the available files.</p>
                        </div>
                    </section>

                    <section class="fcard collapse-card">
                        <?= collapseHead('Delivery Information', $delivery ? orDash($delivery['trucker']) . ' · ' . ($truckStatus[$delivery['status']][0] ?? 'Scheduled') . ' · ' . fmtSchedule($delivery['scheduled_date'] ?? null, $delivery['scheduled_time'] ?? null) : 'Not yet scheduled', 'clientDeliveryMore') ?>
                        <div class="collapse-body" id="clientDeliveryMore">
                        <div class="field-row">
                            <?= dField('Trucking Company', orDash($delivery['trucker'] ?? '')) ?>
                            <?= dField('Pickup Schedule', fmtSchedule($delivery['scheduled_date'] ?? null, $delivery['scheduled_time'] ?? null)) ?>
                            <?= dFieldHtml('Trucking Status', $truckBadge) ?>
                        </div>
                        <?= dField('Delivery Address', orDash($delivery['delivery_address'] ?? '')) ?>
                        <div class="field-row">
                            <?= dField('Driver / Vehicle', $driverVehicle) ?>
                            <?= dField('Receiving Contact', orDash($delivery['receiving_contact'] ?? '')) ?>
                            <?= dField('Delivery Date', !empty($delivery['delivered_date']) ? fmtDate($delivery['delivered_date']) : 'To be confirmed') ?>
                        </div>
                        </div>
                    </section>

                </div>

                <div class="col-right">

                    <section class="fcard progress">
                        <div class="card-head">
                            <h2 class="card-title">Shipment Progress</h2>
                            <span class="soft-text small">Based on recorded updates</span>
                        </div>
                        <ol class="timeline"><?= renderTimelineItems($progress['items']) ?></ol>
                    </section>

                    <section class="fcard">
                        <div class="card-head">
                            <h2 class="card-title">Billing Summary</h2>
                            <?php if ($billing): ?><span class="soft-text small"><?= e($billing['no']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($billing): ?>
                            <div class="field-row field-row-3">
                                <?= dField('Amount Due', money($billing['due'])) ?>
                                <?= dField('Amount Paid', money($billing['paid'])) ?>
                                <?= dFieldHtml('Payment Status', badge($billing['pay'][0], $billing['pay'][1])) ?>
                            </div>
                        <?php else: ?>
                            <p class="soft-text">No billing has been issued for this shipment yet.</p>
                        <?php endif; ?>
                    </section>

                </div>

            </div>
        </main>
    </div>
</div>

<script src="assets/js/clint_shipment.js"></script>
<script src="assets/js/shipment_details.js?v=20261024-2"></script>
</body>
</html>
    <?php
    exit;
}

/* ==================================================================
 * LIST — My Shipments
 * ================================================================== */
$perPage   = 8;
$search    = trim((string)($_GET['q'] ?? ''));
$groups    = statusGroups(true);
$statusKey = (string)($_GET['status'] ?? '');
if (!isset($groups[$statusKey])) $statusKey = '';
$page      = max(1, (int)($_GET['page'] ?? 1));

// "All types" dropdown: transaction type (import / export) or mode (sea / air / land)
$typeOptions = [
    ''       => 'All types',
    'import' => 'Import',
    'export' => 'Export',
    'sea'    => 'Sea freight',
    'air'    => 'Air freight',
    'land'   => 'Land',
];
$typeKey = (string)($_GET['type'] ?? '');
if (!isset($typeOptions[$typeKey])) $typeKey = '';

function buildWhere(bool $withStatus, int $clientId, string $search, string $statusKey, array $groups, string $typeKey): array
{
    $where  = ['s.client_id = :cid'];            // a client only ever sees their own shipments
    $params = [':cid' => $clientId];

    if ($search !== '') {
        $like    = '%' . addcslashes($search, '%_\\') . '%';
        $where[] = '(s.reference_no LIKE :q1 OR s.bl_awb_no LIKE :q2)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
    }
    if ($withStatus && $statusKey !== '') {
        $ph = [];
        foreach ($groups[$statusKey][2] as $i => $raw) {
            $ph[] = ':st' . $i;
            $params[':st' . $i] = $raw;
        }
        $where[] = 'LOWER(TRIM(s.current_status)) IN (' . implode(', ', $ph) . ')';
    }
    if (in_array($typeKey, ['import', 'export'], true)) {
        $where[] = 's.transaction_type = :type';
        $params[':type'] = $typeKey;
    } elseif (in_array($typeKey, ['sea', 'air', 'land'], true)) {
        $where[] = 's.mode = :mode';
        $params[':mode'] = $typeKey;
    }
    return ['WHERE ' . implode(' AND ', $where), $params];
}

[$whereSql, $params]      = buildWhere(true,  $clientId, $search, $statusKey, $groups, $typeKey);
[$whereNoSt, $paramsNoSt] = buildWhere(false, $clientId, $search, $statusKey, $groups, $typeKey);

$lastUpd = "COALESCE((SELECT MAX(x.updated_at) FROM shipment_status_updates x WHERE x.shipment_id = s.shipment_id), s.created_at)";

$st = $conn->prepare("SELECT LOWER(TRIM(s.current_status)) AS st, COUNT(*) AS n FROM shipments s $whereNoSt GROUP BY LOWER(TRIM(s.current_status))");
$st->execute($paramsNoSt);
$byStatus = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$tabCount = ['' => array_sum($byStatus)];
foreach ($groups as $key => $g) {
    $n = 0;
    foreach ($g[2] as $raw) $n += (int)($byStatus[$raw] ?? 0);
    $tabCount[$key] = $n;
}

$st = $conn->prepare("SELECT COUNT(*) FROM shipments s $whereSql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$st = $conn->prepare(
    "SELECT s.shipment_id, s.reference_no, s.created_at, s.transaction_type, s.mode, s.bl_awb_no, s.current_status, $lastUpd AS last_updated
     FROM shipments s $whereSql
     ORDER BY s.created_at DESC, s.shipment_id DESC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

$from = $total ? ($page - 1) * $perPage + 1 : 0;
$to   = $total ? $from + count($rows) - 1 : 0;

function listUrl(array $extra = []): string
{
    $q = $_GET;
    unset($q['page'], $q['id'], $q['download']);
    $q = array_merge($q, $extra);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return 'clint_shipment.php' . ($q ? '?' . http_build_query($q) : '');
}

function shipPager(int $page, int $pages): string
{
    if ($pages <= 1) return '';
    $show = [];
    for ($p = 1; $p <= $pages; $p++) {
        if ($p === 1 || $p === $pages || abs($p - $page) <= 1
            || ($page <= 2 && $p <= 3) || ($page >= $pages - 1 && $p >= $pages - 2)) {
            $show[] = $p;
        }
    }
    $html  = '<nav class="pager" aria-label="Pagination">';
    $html .= $page > 1
        ? '<a class="page-btn" href="' . e(listUrl(['page' => $page - 1])) . '" aria-label="Previous page">' . icon('left') . '</a>'
        : '<span class="page-btn disabled">' . icon('left') . '</span>';
    $prev = 0;
    foreach ($show as $p) {
        if ($p - $prev > 1) $html .= '<span class="page-gap">…</span>';
        $html .= $p === $page
            ? '<span class="page-btn active" aria-current="page">' . $p . '</span>'
            : '<a class="page-btn" href="' . e(listUrl(['page' => $p])) . '">' . $p . '</a>';
        $prev = $p;
    }
    $html .= $page < $pages
        ? '<a class="page-btn" href="' . e(listUrl(['page' => $page + 1])) . '" aria-label="Next page">' . icon('right') . '</a>'
        : '<span class="page-btn disabled">' . icon('right') . '</span>';
    return $html . '</nav>';
}

$filtering = ($search !== '' || $statusKey !== '' || $typeKey !== '');

renderClientHead('My Shipments');
?>
<body class="fig">

<div class="layout">

    <?php renderClientSidebar('shipments', $displayName); ?>

    <div class="fig-main">

        <?php renderTopnav([$crumbHome, ['My Shipments', null]], $displayName, $bellDot); ?>

        <main class="page">

            <div class="page-head">
                <div>
                    <h1>My Shipments</h1>
                    <p class="sub">Track the status of every shipment on your account.</p>
                </div>
            </div>

            <section class="fcard">

                <nav class="tabs" aria-label="Filter by status">
                    <a class="tab <?= $statusKey === '' ? 'active' : '' ?>" href="<?= e(listUrl(['status' => ''])) ?>">All <span class="tab-count"><?= (int)$tabCount[''] ?></span></a>
                    <?php foreach ($groups as $key => $g): ?>
                        <a class="tab <?= $key === $statusKey ? 'active' : '' ?>" href="<?= e(listUrl(['status' => $key])) ?>"><?= e($g[0]) ?> <span class="tab-count"><?= (int)$tabCount[$key] ?></span></a>
                    <?php endforeach; ?>
                </nav>

                <form class="toolbar" method="get" action="clint_shipment.php" role="search">
                    <?php if ($statusKey !== ''): ?><input type="hidden" name="status" value="<?= e($statusKey) ?>"><?php endif; ?>
                    <label class="search-field">
                        <?= icon('search') ?>
                        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by shipment reference or B/L number" aria-label="Search by shipment reference or B/L number">
                    </label>
                    <select class="select" name="type" data-autosubmit aria-label="Filter by type">
                        <?php foreach ($typeOptions as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $val === $typeKey ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="sr-only">Search</button>
                </form>

                <div class="table-wrap">
                    <table class="ship-table">
                        <thead>
                            <tr>
                                <th>Shipment Reference</th>
                                <th>Shipment Date</th>
                                <th>Type · Mode</th>
                                <th>B/L / AWB No.</th>
                                <th>Current Status</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td class="empty" colspan="7">
                                    <?= $filtering ? 'No shipments match your search.' : 'You have no shipments yet.' ?>
                                    <?php if ($filtering): ?><a class="link" href="clint_shipment.php">Clear filters</a><?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): [$label, $class] = statusInfo($r['current_status']); ?>
                            <tr>
                                <td class="ref" data-label="Shipment Reference">
                                    <a href="clint_shipment.php?id=<?= (int)$r['shipment_id'] ?>"><?= e($r['reference_no']) ?></a>
                                </td>
                                <td class="soft" data-label="Shipment Date"><?= e(fmtDate($r['created_at'])) ?></td>
                                <td data-label="Type · Mode"><?= e(ucfirst((string)$r['transaction_type']) . ' · ' . ucfirst((string)$r['mode'])) ?></td>
                                <td class="soft" data-label="B/L / AWB No."><?= e($r['bl_awb_no'] !== null && $r['bl_awb_no'] !== '' ? $r['bl_awb_no'] : '—') ?></td>
                                <td data-label="Current Status"><?= badge($label, $class) ?></td>
                                <td class="soft" data-label="Last Updated"><?= e(fmtDate($r['last_updated'])) ?></td>
                                <td data-label="Actions">
                                    <div class="row-actions">
                                        <a class="btn-sm btn-sm-view" href="clint_shipment.php?id=<?= (int)$r['shipment_id'] ?>">View Details</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="ship-foot">
                    <p>Showing <?= $from ?>–<?= $to ?> of <?= $total ?> shipment<?= $total === 1 ? '' : 's' ?></p>
                    <?= shipPager($page, $pages) ?>
                </div>
            </section>

        </main>
    </div>
</div>

<script src="assets/js/clint_shipment.js"></script>
</body>
</html>
<?php
/* ------------------------------------------------------------------
 * helpers used above (functions are hoisted, so they can sit down here)
 * ------------------------------------------------------------------ */
function renderClientHead(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff.css">
    <link rel="stylesheet" href="assets/css/clint_shipment.css">
    <link rel="stylesheet" href="assets/css/shipment_status.css">
    <link rel="stylesheet" href="assets/css/shipment_details.css?v=20261024-4">
</head>
    <?php
}

function dField(string $label, string $text): string
{
    return '<div class="dfield"><div class="lbl">' . e($label) . '</div><div class="val">' . e($text) . '</div></div>';
}
function dFieldHtml(string $label, string $html): string
{
    return '<div class="dfield"><div class="lbl">' . e($label) . '</div><div class="val">' . $html . '</div></div>';
}
function orDash($v): string { $v = trim((string)$v); return $v === '' ? '—' : $v; }
function collapseHead(string $title, string $summary, string $id): string
{
    return '<div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">' . e($title) . '</h2><p>' . e($summary) . '</p></div>'
        . '<button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="' . e($id) . '">Show less</button></div>';
}
