<?php
/**
 * staff_shipment.php — Shipment Management (list) for STAFF, built from the Figma design
 * Files: staff_shipment.php (this), assets/css/staff_shipment.css, assets/js/staff_shipment.js, function/shipment_common.php
 * Tables used: shipments, clients, containers, shipment_status_updates
 *
 * Staff see: Add Shipment button, status tabs with counts, search, Any date filter,
 *            View / Edit actions. (No export, no staff filter, no Assigned Staff column: those are admin only.)
 * "Add Shipment" is not in the sidebar. It is only the button on this page.
 */
session_start();
require_once 'config/database.php';          // $conn (PDO)
require_once 'function/shipment_common.php';

[$userId, $fullName, $role] = requireStaffOrAdmin($conn);
if ($role === 'admin') {                     // admin has its own page
    header('Location: shipments.php');
    exit;
}

/* ------------------------------------------------------------------
 * 1) FILTERS: status tab, search, date, page
 * ------------------------------------------------------------------ */
$perPage   = 8;
$search    = trim((string)($_GET['q'] ?? ''));
$groups    = statusGroups(true);
$statusKey = (string)($_GET['status'] ?? '');
if (!isset($groups[$statusKey])) $statusKey = '';
$page      = max(1, (int)($_GET['page'] ?? 1));

$dateOptions = [
    ''      => 'Any date',
    'today' => 'Today',
    '7d'    => 'Last 7 days',
    '30d'   => 'Last 30 days',
    'month' => 'This month',
    'year'  => 'This year',
];
$dateKey = (string)($_GET['date'] ?? '');
if (!isset($dateOptions[$dateKey])) $dateKey = '';
$arrivalOptions = ['' => 'Any arrival date', 'arriving7d' => 'Arriving in 7 days', 'overdue' => 'Overdue delivery'];
$arrivalKey = (string)($_GET['arrival'] ?? '');
if (!isset($arrivalOptions[$arrivalKey])) $arrivalKey = '';

// $withStatus = false is used for the tab counts (each tab shows what it would hold for the current search / date)
function buildWhere(bool $withStatus, string $search, string $statusKey, array $groups, string $dateKey, string $arrivalKey): array
{
    $where  = ['s.archived_at IS NULL'];
    $params = [];

    if ($search !== '') {
        $like    = '%' . addcslashes($search, '%_\\') . '%';
        $where[] = "(s.reference_no LIKE :q1 OR c.client_name LIKE :q2 OR s.bl_awb_no LIKE :q3 OR s.invoice_no LIKE :q4
                     OR EXISTS (SELECT 1 FROM containers ct WHERE ct.shipment_id = s.shipment_id AND ct.container_no LIKE :q5))";
        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) $params[':' . $k] = $like;
    }

    if ($withStatus && $statusKey !== '') {
        $ph = [];
        foreach ($groups[$statusKey][2] as $i => $raw) {
            $ph[] = ':st' . $i;
            $params[':st' . $i] = $raw;
        }
        $where[] = 'LOWER(TRIM(s.current_status)) IN (' . implode(', ', $ph) . ')';
    }

    switch ($dateKey) {
        case 'today': $where[] = 'DATE(s.created_at) = CURDATE()'; break;
        case '7d':    $where[] = 's.created_at >= (NOW() - INTERVAL 7 DAY)'; break;
        case '30d':   $where[] = 's.created_at >= (NOW() - INTERVAL 30 DAY)'; break;
        case 'month': $where[] = "s.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"; break;
        case 'year':  $where[] = "s.created_at >= DATE_FORMAT(CURDATE(), '%Y-01-01')"; break;
    }
    if ($arrivalKey === 'arriving7d') $where[] = 's.eta_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND LOWER(TRIM(s.current_status)) NOT IN (\'delivered\',\'cancelled\')';
    if ($arrivalKey === 'overdue') $where[] = 's.delivery_due_date < CURDATE() AND LOWER(TRIM(s.current_status)) NOT IN (\'delivered\',\'cancelled\')';

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

[$whereSql, $params]      = buildWhere(true,  $search, $statusKey, $groups, $dateKey, $arrivalKey);
[$whereNoSt, $paramsNoSt] = buildWhere(false, $search, $statusKey, $groups, $dateKey, $arrivalKey);

$baseFrom = "FROM shipments s JOIN clients c ON c.client_id = s.client_id";
$lastUpd  = "COALESCE((SELECT MAX(x.updated_at) FROM shipment_status_updates x WHERE x.shipment_id = s.shipment_id), s.created_at)";

/* ------------------------------------------------------------------
 * 2) DATA
 * ------------------------------------------------------------------ */
$st = $conn->prepare("SELECT LOWER(TRIM(s.current_status)) AS st, COUNT(*) AS n $baseFrom $whereNoSt GROUP BY LOWER(TRIM(s.current_status))");
$st->execute($paramsNoSt);
$byStatus = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$tabCount = ['' => array_sum($byStatus)];
foreach ($groups as $key => $g) {
    $n = 0;
    foreach ($g[2] as $raw) $n += (int)($byStatus[$raw] ?? 0);
    $tabCount[$key] = $n;
}

$st = $conn->prepare("SELECT COUNT(*) $baseFrom $whereSql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$st = $conn->prepare(
    "SELECT s.shipment_id, s.reference_no, s.assigned_staff_id, c.client_name, s.current_status, s.created_at, s.transaction_type, s.mode, s.bl_awb_no, s.eta_date, s.first_free_day, s.delivery_due_date, $lastUpd AS last_updated
     $baseFrom $whereSql
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
    unset($q['page']);
    $q = array_merge($q, $extra);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return 'staff_shipment.php' . ($q ? '?' . http_build_query($q) : '');
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

$bellDot   = hasRecentUpdate($conn);
$filtering = ($search !== '' || $statusKey !== '' || $dateKey !== '' || $arrivalKey !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shipment Management | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff.css?v=20261024-3">
    <link rel="stylesheet" href="assets/css/staff_shipment.css?v=20261024-3">
</head>
<body class="fig">

<div class="layout">

    <?php renderSidebar($role, 'shipments', $fullName); ?>

    <div class="fig-main">

        <?php renderTopnav([['Dashboard', homePage($role)], ['Shipment Management', null]], $fullName, $bellDot); ?>

        <main class="page">

            <div class="page-head">
                <div>
                    <h1>Shipment Management</h1>
                    <p class="sub">View, monitor, and update shipment records.</p>
                </div>
                <a class="btn btn-primary" href="shipment_add.php"><?= icon('plus') ?>Add Shipment</a>
            </div>

            <section class="fcard">

                <nav class="tabs" aria-label="Filter by status">
                    <a class="tab <?= $statusKey === '' ? 'active' : '' ?>" href="<?= e(listUrl(['status' => ''])) ?>">All <span class="tab-count"><?= (int)$tabCount[''] ?></span></a>
                    <?php foreach ($groups as $key => $g): ?>
                        <a class="tab <?= $key === $statusKey ? 'active' : '' ?>" href="<?= e(listUrl(['status' => $key])) ?>"><?= e($g[0]) ?> <span class="tab-count"><?= (int)$tabCount[$key] ?></span></a>
                    <?php endforeach; ?>
                </nav>

                <form class="toolbar" method="get" action="staff_shipment.php" role="search">
                    <?php if ($statusKey !== ''): ?><input type="hidden" name="status" value="<?= e($statusKey) ?>"><?php endif; ?>
                    <label class="search-field">
                        <?= icon('search') ?>
                        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by shipment reference or client" aria-label="Search by shipment reference or client">
                    </label>
                    <select class="select" name="date" data-autosubmit aria-label="Filter by date">
                        <?php foreach ($dateOptions as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $val === $dateKey ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="select" name="arrival" data-autosubmit aria-label="Filter by shipment key dates">
                        <?php foreach ($arrivalOptions as $val => $label): ?><option value="<?= e($val) ?>" <?= $val === $arrivalKey ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="sr-only">Search</button>
                </form>

                <div class="table-wrap">
                    <table class="ship-table">
                        <thead>
                            <tr>
                                <th>Shipment Reference</th>
                                <th>Client Name</th>
                                <th>Shipment Date</th><th>ETA</th>
                                <th>Type</th><th>Mode</th><th>B/L or AWB No.</th><th>Current Status</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td class="empty" colspan="10">
                                    <?= $filtering ? 'No shipments match your search.' : 'No shipments yet.' ?>
                                    <?php if ($filtering): ?><a class="link" href="staff_shipment.php">Clear filters</a><?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): [$label, $class] = statusInfo($r['current_status']); ?>
                            <tr>
                                <td class="ref" data-label="Shipment Reference">
                                    <a href="shipment_view.php?id=<?= (int)$r['shipment_id'] ?>"><?= e($r['reference_no']) ?></a>
                                </td>
                                <td data-label="Client Name"><?= e($r['client_name']) ?></td>
                                <td class="soft" data-label="Shipment Date"><?= e(fmtDate($r['created_at'])) ?></td>
                                <td class="soft" data-label="ETA"><?= e(fmtDate($r['eta_date'])) ?><?php if ($r['first_free_day']): ?><small class="row-date-note">Free day · <?= e(fmtDate($r['first_free_day'])) ?></small><?php endif; ?><?php if ($r['delivery_due_date'] && $r['delivery_due_date'] < date('Y-m-d') && !in_array($r['current_status'], ['Delivered','Cancelled'], true)): ?><small class="row-overdue">Delivery overdue</small><?php endif; ?></td>
                                <td data-label="Type"><?= e(ucfirst((string)$r['transaction_type'])) ?></td>
                                <td data-label="Mode"><?= e(ucfirst((string)$r['mode'])) ?></td>
                                <td class="soft" data-label="B/L or AWB No."><?= e($r['bl_awb_no'] ?: '—') ?></td>
                                <td data-label="Current Status"><?= badge($label, $class) ?></td>
                                <td class="soft" data-label="Last Updated"><?= e(fmtDate($r['last_updated'])) ?></td>
                                <td data-label="Actions">
                                    <div class="row-actions">
                                        <a class="btn-sm btn-sm-view" href="shipment_view.php?id=<?= (int)$r['shipment_id'] ?>">View</a>
                                        <?php $editAllowed = !in_array($r['current_status'], ['Delivered', 'Cancelled'], true) && (empty($r['assigned_staff_id']) || (int)$r['assigned_staff_id'] === $userId); ?>
                                        <?php if ($editAllowed): ?><a class="btn-sm btn-sm-edit" href="shipment_edit.php?id=<?= (int)$r['shipment_id'] ?>">Edit</a><?php else: ?><span class="btn-sm btn-sm-edit is-disabled" aria-disabled="true" title="Only the assigned staff member or an administrator can edit this shipment">Edit</span><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="table-scroll-hint">Scroll horizontally to see all shipment columns.</p>
                <div class="ship-foot">
                    <p>Showing <?= $from ?>–<?= $to ?> of <?= $total ?> shipment<?= $total === 1 ? '' : 's' ?></p>
                    <?= shipPager($page, $pages) ?>
                </div>
            </section>

        </main>
    </div>
</div>

<script src="assets/js/staff_shipment.js"></script>
</body>
</html>
