<?php
/**
 * shipments.php — Shipment Management (list) for Admin and Staff, rebuilt from the Figma design
 * Files: shipments.php (this), function/shipment_common.php, assets/css/shipments.css, assets/js/shipments.js
 * Tables used: shipments, clients, users, containers, shipment_status_updates
 *
 * Admin : Export + Add Shipment buttons, status tabs, search, All Staff + Any date filters,
 *         Assigned Staff column, View / Edit / "..." actions
 * Staff : Add Shipment button, status tabs, search, Any date filter, View / Edit actions
 * "Add Shipment" is NOT in the sidebar any more. It only exists as the button on this page.
 */
session_start();
require_once 'config/database.php';          // $conn (PDO)
require_once 'function/shipment_common.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

[$userId, $fullName, $role] = requireStaffOrAdmin($conn);
// Staff have their own page (staff_shipment.php); this one is the admin Shipment Management
if ($role !== 'admin') {
    header('Location: staff_shipment.php');
    exit;
}
$isAdmin = true;

/* ------------------------------------------------------------------
 * 1) FILTERS: status tab, search, staff (admin), date, page
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
$scope = (($_GET['scope'] ?? 'active') === 'archived') ? 'archived' : 'active';

// staff dropdown (admin only)
$staffList = [];
$staffLoad = [];
$staffKey  = '';
if ($isAdmin) {
    $staffList = $conn->query("SELECT user_id, full_name FROM users WHERE role = 'staff' AND status = 'active' ORDER BY full_name")->fetchAll();
    $loadQuery = $conn->query("SELECT u.user_id, COUNT(s.shipment_id) AS open_shipments
        FROM users u LEFT JOIN shipments s ON s.assigned_staff_id = u.user_id
          AND LOWER(TRIM(s.current_status)) NOT IN ('delivered','cancelled')
        WHERE u.role = 'staff' AND u.status = 'active' GROUP BY u.user_id");
    foreach ($loadQuery->fetchAll() as $load) $staffLoad[(int)$load['user_id']] = (int)$load['open_shipments'];
    $staffKey  = (string)($_GET['staff'] ?? '');
    $validStaff = ['' => true, 'unassigned' => true];
    foreach ($staffList as $sf) $validStaff[(string)$sf['user_id']] = true;
    if (!isset($validStaff[$staffKey])) $staffKey = '';
}

/**
 * Builds the WHERE clause. $withStatus = false is used for the tab counts,
 * so every tab shows how many shipments it would hold for the current search / staff / date.
 */
function buildWhere(bool $withStatus, string $search, string $statusKey, array $groups, string $staffKey, string $dateKey, string $arrivalKey, string $scope): array
{
    $where  = [$scope === 'archived' ? 's.archived_at IS NOT NULL' : 's.archived_at IS NULL'];
    $params = [];

    if ($search !== '') {
        // reference, client, B/L number, invoice number or a container number
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

    if ($staffKey === 'unassigned') {
        $where[] = 's.assigned_staff_id IS NULL';
    } elseif ($staffKey !== '') {
        $where[] = 's.assigned_staff_id = :staff';
        $params[':staff'] = (int)$staffKey;
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

[$whereSql, $params]     = buildWhere(true,  $search, $statusKey, $groups, $staffKey, $dateKey, $arrivalKey, $scope);
[$whereNoSt, $paramsNoSt] = buildWhere(false, $search, $statusKey, $groups, $staffKey, $dateKey, $arrivalKey, $scope);

$baseFrom = "FROM shipments s
             JOIN clients c ON c.client_id = s.client_id
             LEFT JOIN users u ON u.user_id = s.assigned_staff_id";
$lastUpd  = "COALESCE((SELECT MAX(x.updated_at) FROM shipment_status_updates x WHERE x.shipment_id = s.shipment_id), s.created_at)";

/* ------------------------------------------------------------------
 * 2) EXPORT (admin): the filtered list as a CSV file
 * ------------------------------------------------------------------ */
if ($isAdmin && isset($_GET['export'])) {
    $st = $conn->prepare(
        "SELECT s.reference_no, c.client_name, s.created_at, s.current_status, u.full_name AS staff_name, $lastUpd AS last_updated
         $baseFrom $whereSql
         ORDER BY s.created_at DESC, s.shipment_id DESC"
    );
    $st->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="shipments_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads UTF-8 correctly
    fputcsv($out, ['Shipment Reference', 'Client Name', 'Shipment Date', 'Current Status', 'Assigned Staff', 'Last Updated']);
    // a leading = + - @ would be run as a formula by Excel, so it gets a ' in front
    $safe = function ($v) { $v = (string)$v; return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v; };
    while ($r = $st->fetch()) {
        [$label] = statusInfo($r['current_status']);
        fputcsv($out, [
            $safe($r['reference_no']), $safe($r['client_name']), fmtDate($r['created_at']), $label,
            $r['staff_name'] ? $safe($r['staff_name']) : 'Unassigned', fmtDate($r['last_updated']),
        ]);
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------------
 * 3) DATA
 * ------------------------------------------------------------------ */
// tab counts
$st = $conn->prepare("SELECT LOWER(TRIM(s.current_status)) AS st, COUNT(*) AS n $baseFrom $whereNoSt GROUP BY LOWER(TRIM(s.current_status))");
$st->execute($paramsNoSt);
$byStatus = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$tabCount = ['' => array_sum($byStatus)];
foreach ($groups as $key => $g) {
    $n = 0;
    foreach ($g[2] as $raw) $n += (int)($byStatus[$raw] ?? 0);
    $tabCount[$key] = $n;
}
$archivedCount = (int)$conn->query('SELECT COUNT(*) FROM shipments WHERE archived_at IS NOT NULL')->fetchColumn();

// rows
$st = $conn->prepare("SELECT COUNT(*) $baseFrom $whereSql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$st = $conn->prepare(
    "SELECT s.shipment_id, s.reference_no, s.client_id, s.assigned_staff_id, c.client_name, s.current_status, s.created_at, s.transaction_type, s.mode, s.bl_awb_no, s.eta_date, s.first_free_day, s.delivery_due_date, s.archived_at,
            u.full_name AS staff_name, $lastUpd AS last_updated
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

// keeps every filter when you change tab / page
function listUrl(array $extra = []): string
{
    $q = $_GET;
    unset($q['page'], $q['export']);
    $q = array_merge($q, $extra);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return 'shipments.php' . ($q ? '?' . http_build_query($q) : '');
}

// « 1 2 3 … 16 » style pager
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
$filtering = ($search !== '' || $statusKey !== '' || $staffKey !== '' || $dateKey !== '' || $arrivalKey !== '' || $scope === 'archived');
$colCount  = 12;
$searchPh  = $isAdmin ? 'Search by reference, client, B/L or container no.' : 'Search by shipment reference or client';
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
    <link rel="stylesheet" href="assets/css/shipments.css?v=20261024-7">
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
                    <p class="sub"><?= $isAdmin ? 'View, assign, monitor, and update all shipment records.' : 'View, monitor, and update shipment records.' ?></p>
                </div>
                <div class="head-buttons">
                    <?php if ($isAdmin): ?>
                        <a class="btn btn-secondary" href="<?= e(listUrl(['export' => 1])) ?>"><?= icon('download') ?>Export</a>
                    <?php endif; ?>
                    <a class="btn btn-primary" href="shipment_add.php"><?= icon('plus') ?>Add Shipment</a>
                </div>
            </div>

            <section class="fcard">

                <nav class="tabs" aria-label="Filter by status">
                    <a class="tab <?= $statusKey === '' ? 'active' : '' ?>" href="<?= e(listUrl(['status' => ''])) ?>">All <span class="tab-count"><?= (int)$tabCount[''] ?></span></a>
                    <?php foreach ($groups as $key => $g): ?>
                        <a class="tab <?= $key === $statusKey ? 'active' : '' ?>" href="<?= e(listUrl(['status' => $key])) ?>"><?= e($g[0]) ?> <span class="tab-count"><?= (int)$tabCount[$key] ?></span></a>
                    <?php endforeach; ?>
                    <a class="tab <?= $scope === 'archived' ? 'active' : '' ?>" href="<?= e(listUrl(['scope' => 'archived', 'status' => ''])) ?>">Archived <span class="tab-count"><?= $archivedCount ?></span></a>
                </nav>

                <form class="toolbar" method="get" action="shipments.php" role="search">
                    <?php if ($statusKey !== ''): ?><input type="hidden" name="status" value="<?= e($statusKey) ?>"><?php endif; ?>
                    <input type="hidden" name="scope" value="<?= e($scope) ?>">
                    <label class="search-field">
                        <?= icon('search') ?>
                        <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e($searchPh) ?>" aria-label="<?= e($searchPh) ?>">
                    </label>
                    <?php if ($isAdmin): ?>
                        <select class="select" name="staff" data-autosubmit aria-label="Filter by staff">
                            <option value="">All Staff</option>
                            <option value="unassigned" <?= $staffKey === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                            <?php foreach ($staffList as $sf): ?>
                                <option value="<?= (int)$sf['user_id'] ?>" <?= (string)$sf['user_id'] === $staffKey ? 'selected' : '' ?>><?= e($sf['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
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

                <?php if ($isAdmin): ?><div class="bulk-assign-bar" id="bulkAssignBar" hidden><strong><span id="bulkSelectedCount">0</span> selected</strong><button type="button" class="btn-sm" id="bulkAssignOpen">♙ Assign to…</button><button type="button" class="bulk-clear" id="bulkClear">Clear selection</button><span>Unassigned shipments only</span></div><?php endif; ?>
                <div class="table-wrap">
                    <table class="ship-table">
                        <thead>
                            <tr>
                                <?php if ($isAdmin): ?><th class="select-col"><input type="checkbox" id="selectVisible" aria-label="Select all unassigned shipments on this page"></th><?php endif; ?>
                                <th>Shipment Reference</th>
                                <th>Client Name</th>
                                <th>Shipment Date</th>
                                <th>ETA</th>
                                <th>Type</th><th>Mode</th><th>B/L or AWB No.</th><th>Current Status</th>
                                <th>Assigned Staff</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td class="empty" colspan="<?= $colCount ?>">
                                    <?= $filtering ? 'No shipments match your search.' : 'No shipments yet.' ?>
                                    <?php if ($filtering): ?><a class="link" href="shipments.php">Clear filters</a><?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): [$label, $class] = statusInfo($r['current_status']); ?>
                            <tr data-shipment-row="<?= (int)$r['shipment_id'] ?>" data-reference="<?= e($r['reference_no']) ?>">
                                <?php if ($isAdmin): ?><td class="select-col" data-label="Select"><?php if (empty($r['staff_name'])): ?><input type="checkbox" class="shipment-select" value="<?= (int)$r['shipment_id'] ?>" aria-label="Select <?= e($r['reference_no']) ?> for assignment"><?php endif; ?></td><?php endif; ?>
                                <td class="ref" data-label="Shipment Reference">
                                    <a href="shipment_view.php?id=<?= (int)$r['shipment_id'] ?>"><?= e($r['reference_no']) ?></a>
                                </td>
                                <td data-label="Client Name"><?= e($r['client_name']) ?></td>
                                <td class="soft" data-label="Shipment Date"><?= e(fmtDate($r['created_at'])) ?></td>
                                <td class="soft" data-label="ETA"><?= e(fmtDate($r['eta_date'])) ?><?php if ($r['first_free_day']): ?><small class="row-date-note">Free day · <?= e(fmtDate($r['first_free_day'])) ?></small><?php endif; ?><?php if ($r['delivery_due_date'] && $r['delivery_due_date'] < date('Y-m-d') && !in_array($r['current_status'], ['Delivered','Cancelled'], true)): ?><small class="row-overdue">Delivery overdue</small><?php endif; ?><?php if ($scope === 'archived'): ?><small class="row-archived">Archived <?= e(fmtDate($r['archived_at'])) ?></small><?php endif; ?></td>
                                <td data-label="Type"><?= e(ucfirst((string)$r['transaction_type'])) ?></td>
                                <td data-label="Mode"><?= e(ucfirst((string)$r['mode'])) ?></td>
                                <td class="soft" data-label="B/L or AWB No."><?= e($r['bl_awb_no'] ?: '—') ?></td>
                                <td data-label="Current Status"><?= badge($label, $class) ?></td>
                                <?php if ($isAdmin): ?>
                                    <td data-label="Assigned Staff">
                                        <?php if ($r['staff_name']): ?>
                                            <span class="staff-chip"><span class="chip-av"><?= e(initialsOf($r['staff_name'])) ?></span><?= e($r['staff_name']) ?></span>
                                        <?php else: ?>
                                            <button type="button" class="assign-row" data-assign-one="<?= (int)$r['shipment_id'] ?>" data-reference="<?= e($r['reference_no']) ?>">● Unassigned <span>Assign</span></button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="soft" data-label="Last Updated"><?= e(fmtDate($r['last_updated'])) ?></td>
                                <td data-label="Actions">
                                    <div class="row-actions">
                                        <a class="btn-sm btn-sm-view" href="shipment_view.php?id=<?= (int)$r['shipment_id'] ?>">View</a>
                                        <?php $editAllowed = empty($r['archived_at']) && ($isAdmin || !in_array($r['current_status'], ['Delivered', 'Cancelled'], true)); ?>
                                        <?php if ($editAllowed): ?><a class="btn-sm btn-sm-edit" href="shipment_edit.php?id=<?= (int)$r['shipment_id'] ?>">Edit</a><?php else: ?><span class="btn-sm btn-sm-edit is-disabled" aria-disabled="true" title="Archived shipments are read-only">Edit</span><?php endif; ?>
                                        <?php if ($isAdmin): ?>
                                            <div class="more">
                                                <button type="button" class="btn-sm btn-sm-more" data-more aria-haspopup="true" aria-expanded="false" aria-label="More actions for <?= e($r['reference_no']) ?>">&#8943;</button>
                                                <div class="more-menu" role="menu" hidden>
                                                    <a role="menuitem" href="shipment_view.php?id=<?= (int)$r['shipment_id'] ?>">View details</a>
                                                    <button role="menuitem" type="button" data-copy="<?= e($r['reference_no']) ?>">Copy reference</button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
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

            <?php if ($isAdmin): ?>
            <dialog class="assignment-dialog" id="assignmentDialog" aria-labelledby="assignmentTitle" data-endpoint="shipment_assignment.php" data-csrf="<?= e($_SESSION['csrf'] ?? '') ?>">
                <header class="assignment-head"><div><h2 id="assignmentTitle">Assign staff</h2><p id="assignmentSubtitle">Active staff only</p></div><button type="button" data-assignment-close aria-label="Close">&times;</button></header>
                <label class="assignment-search"><span class="sr-only">Search active staff</span><input id="assignmentSearch" type="search" placeholder="Search staff"></label>
                <div class="assignment-staff-list" id="assignmentStaffList">
                    <?php foreach ($staffList as $sf): ?><label class="assignment-staff" data-staff-card data-staff-name="<?= e(mb_strtolower($sf['full_name'])) ?>"><input type="radio" name="assignment_staff" value="<?= (int)$sf['user_id'] ?>"><span class="assignment-avatar"><?= e(initialsOf($sf['full_name'])) ?></span><span class="assignment-staff-info"><strong><?= e($sf['full_name']) ?></strong><small><?= (int)($staffLoad[(int)$sf['user_id']] ?? 0) ?> open shipments</small></span><span class="assignment-selected">Selected</span></label><?php endforeach; ?>
                </div>
                <div class="assignment-no-staff" id="assignmentNoStaff" <?= $staffList ? 'hidden' : '' ?>><strong>No active staff</strong><p>Activate a staff account in User Management to make an assignment.</p></div>
                <p class="assignment-preview" id="assignmentPreview" hidden></p>
                <footer class="assignment-foot"><button type="button" class="btn btn-secondary" data-assignment-close>Cancel</button><button type="button" class="btn btn-primary" id="assignmentSubmit" disabled>Assign</button></footer>
            </dialog>
            <div class="assignment-toast" id="assignmentToast" role="status" hidden></div>
            <?php endif; ?>

        </main>
    </div>
</div>

<script src="assets/js/shipments.js?v=20261024-7"></script>
</body>
</html>
