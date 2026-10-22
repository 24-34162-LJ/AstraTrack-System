<?php
/**
 * staff.php — AstraTrack Staff Dashboard (replaces the old staff.php stub)
 * Files: staff.php (this), assets/css/staff.css, assets/js/staff.js
 * Tables used: users, clients, shipments, shipment_status_updates, transactions, billings (view v_billing_balance)
 */
session_start();
require_once 'config/database.php';   // gives you $conn (PDO)

/* ------------------------------------------------------------------
 * 1) ACCESS CONTROL — only logged-in staff may open this page
 * ------------------------------------------------------------------ */
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header('Location: login.php');
    exit;
}
$currentUser = $_SESSION['user'];
$userId      = (int)($currentUser['id'] ?? $currentUser['user_id'] ?? 0);

// Make sure the account still exists and is active
$st = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? AND role = 'staff' AND status = 'active'");
$st->execute([$userId]);
$fullName = $st->fetchColumn();
if ($fullName === false) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* ------------------------------------------------------------------
 * 2) HELPERS
 * ------------------------------------------------------------------ */
function e($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }

function fmtDate($value, $withYear = true) {
    return $value ? date($withYear ? 'M d, Y' : 'M d', strtotime($value)) : '—';
}

function money($value) {
    $value = (float)$value;
    return 'PHP ' . number_format($value, floor($value) == $value ? 0 : 2);
}

/* shipments.current_status is free text, so it is grouped into the labels of the design
 * (same grouping as admin.php). Edit the lists if you use other wording. */
function statusInfo($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'documents received')
        return ['Pending Documents', 'amber'];
    if (in_array($s, ['declaration filed', 'assessment', 'duties paid', 'examination', 'released/cleared'], true))
        return ['Customs Processing', 'blue'];
    if (in_array($s, ['in transit', 'trucking'], true))
        return [$s === 'trucking' ? 'Trucking' : 'In Transit', 'teal'];
    if ($s === 'delivered')
        return ['Delivered', 'green'];
    return [$status !== '' ? $status : 'Unknown', 'blue'];
}

function paymentInfo($status) {
    switch ($status) {
        case 'paid':    return ['Paid', 'green'];
        case 'partial': return ['Partially Paid', 'amber'];
        default:        return ['Unpaid', 'red'];
    }
}

function txnInfo($status) {
    switch ($status) {
        case 'completed': return ['Completed', 'green'];
        case 'ongoing':   return ['In Progress', 'blue'];
        default:          return ['Pending', 'amber'];
    }
}

// Link that changes one page number but keeps the search text and the other table's page
function pageUrl($key, $n, $anchor) {
    $q = $_GET;
    unset($q['ajax'], $q['ref']);
    $q[$key] = $n;
    return '?' . http_build_query($q) . '#' . $anchor;
}

// 1 2 3 … 16 style pager
function pager($key, $page, $pages, $anchor) {
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
        ? '<a class="page-btn" href="' . e(pageUrl($key, $page - 1, $anchor)) . '" aria-label="Previous page">' . icon('left') . '</a>'
        : '<span class="page-btn disabled">' . icon('left') . '</span>';
    $prev = 0;
    foreach ($show as $p) {
        if ($p - $prev > 1) $html .= '<span class="page-gap">…</span>';
        $html .= $p === $page
            ? '<span class="page-btn active" aria-current="page">' . $p . '</span>'
            : '<a class="page-btn" href="' . e(pageUrl($key, $p, $anchor)) . '">' . $p . '</a>';
        $prev = $p;
    }
    $html .= $page < $pages
        ? '<a class="page-btn" href="' . e(pageUrl($key, $page + 1, $anchor)) . '" aria-label="Next page">' . icon('right') . '</a>'
        : '<span class="page-btn disabled">' . icon('right') . '</span>';
    return $html . '</nav>';
}

// Small inline SVG icon set (stroke icons)
function icon($name) {
    $paths = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'     => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2 .7 3.5 2.6 3.5 5.5"/>',
        'billing' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M12 7v1.5m0 5V15m2-5.5c0-.8-.9-1.5-2-1.5s-2 .7-2 1.5 .9 1.3 2 1.5 2 .7 2 1.5-.9 1.5-2 1.5-2-.7-2-1.5"/>',
        'swap'    => '<path d="M4 8h16m-4-4l4 4-4 4M20 16H4m4-4l-4 4 4 4"/>',
        'chart'   => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'logout'  => '<path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9"/>',
        'search'  => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        'bell'    => '<path d="M6 16V11a6 6 0 1112 0v5l1.5 2h-15L6 16z"/><path d="M10 21h4"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'left'    => '<path d="M15 6l-6 6 6 6"/>',
        'right'   => '<path d="M9 6l6 6-6 6"/>',
        'truck'   => '<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'check'   => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.5"/>',
        'close'   => '<path d="M6 6l12 12M18 6L6 18"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/* ------------------------------------------------------------------
 * 3) TRACK / VIEW (JSON endpoint used by staff.js)
 * ------------------------------------------------------------------
 * GET staff.php?ajax=track&ref=<reference no or Bill of Lading>
 */
if (($_GET['ajax'] ?? '') === 'track') {
    header('Content-Type: application/json; charset=utf-8');
    $ref = trim((string)($_GET['ref'] ?? ''));

    if ($ref === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a shipment reference number.']);
        exit;
    }

    $st = $conn->prepare(
        "SELECT s.shipment_id, s.reference_no, c.client_name, s.transaction_type, s.mode, s.bl_awb_no,
                s.shipper_name, s.consignee_name, s.vessel_or_flight, s.port_of_loading, s.port_of_discharge,
                s.incoterm, s.current_status
         FROM shipments s
         JOIN clients c ON c.client_id = s.client_id
         WHERE s.reference_no = ? OR s.bl_awb_no = ?
         LIMIT 1"
    );
    $st->execute([$ref, $ref]);
    $s = $st->fetch();

    if (!$s) {
        http_response_code(404);
        echo json_encode(['error' => 'No shipment matches "' . $ref . '". Check the number and try again.']);
        exit;
    }

    [$label, $class] = statusInfo($s['current_status']);

    $fields = [
        'Client'             => $s['client_name'],
        'Type'               => ucfirst($s['transaction_type']),
        'Mode'               => ucfirst($s['mode']),
        'Bill of Lading/AWB' => $s['bl_awb_no'],
        'Shipper'            => $s['shipper_name'],
        'Consignee'          => $s['consignee_name'],
        'Vessel / Flight'    => $s['vessel_or_flight'],
        'Port of loading'    => $s['port_of_loading'],
        'Port of discharge'  => $s['port_of_discharge'],
        'Incoterm'           => $s['incoterm'],
    ];
    $details = [];
    foreach ($fields as $k => $v) {
        if ($v !== null && $v !== '') $details[] = [$k, $v];
    }

    $tl = $conn->prepare(
        "SELECT stage, remarks, updated_at FROM shipment_status_updates
         WHERE shipment_id = ? ORDER BY updated_at DESC, update_id DESC"
    );
    $tl->execute([$s['shipment_id']]);
    $timeline = [];
    foreach ($tl as $r) {
        [$stageLabel, $stageClass] = statusInfo($r['stage']);
        $timeline[] = [
            'title' => $stageLabel === 'Unknown' ? $r['stage'] : $stageLabel,
            'note'  => (string)$r['remarks'],
            'date'  => fmtDate($r['updated_at']),
            'class' => $stageClass,
        ];
    }

    echo json_encode([
        'reference' => $s['reference_no'],
        'status'    => ['label' => $label, 'class' => $class],
        'details'   => $details,
        'timeline'  => $timeline,
    ]);
    exit;
}

/* ------------------------------------------------------------------
 * 4) STAT CARDS
 * ------------------------------------------------------------------ */
$row = $conn->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(LOWER(TRIM(current_status)) = 'delivered'), 0) AS delivered
     FROM shipments"
)->fetch();
$totalShipments = (int)$row['total'];
$completed      = (int)$row['delivered'];
$ongoing        = $totalShipments - $completed;

// Outstanding = billed minus VERIFIED payments (the view v_billing_balance already does this)
$outstanding = (float)$conn->query("SELECT COALESCE(SUM(GREATEST(balance, 0)), 0) FROM v_billing_balance")->fetchColumn();

/* ------------------------------------------------------------------
 * 5) RECENT SHIPMENTS (8 per page, optional search from the top bar)
 * ------------------------------------------------------------------ */
$search = trim((string)($_GET['q'] ?? ''));
$where  = '';
$params = [];
if ($search !== '') {
    $like  = '%' . addcslashes($search, '%_\\') . '%';
    // a named placeholder can only be used once when real prepared statements are on, so each gets its own name
    $where = "WHERE s.reference_no LIKE :q1 OR c.client_name LIKE :q2 OR s.bl_awb_no LIKE :q3";
    $params = [':q1' => $like, ':q2' => $like, ':q3' => $like];
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM shipments s JOIN clients c ON c.client_id = s.client_id $where");
$stmt->execute($params);
$shipCount = (int)$stmt->fetchColumn();

$shipPerPage = 8;
$shipPages   = max(1, (int)ceil($shipCount / $shipPerPage));
$shipPage    = min(max(1, (int)($_GET['sp'] ?? 1)), $shipPages);

$stmt = $conn->prepare(
    "SELECT s.reference_no, c.client_name, s.current_status,
            COALESCE((SELECT MAX(u.updated_at) FROM shipment_status_updates u WHERE u.shipment_id = s.shipment_id),
                     s.created_at) AS last_updated
     FROM shipments s
     JOIN clients c ON c.client_id = s.client_id
     $where
     ORDER BY last_updated DESC, s.shipment_id DESC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $shipPerPage, PDO::PARAM_INT);
$stmt->bindValue(':off', ($shipPage - 1) * $shipPerPage, PDO::PARAM_INT);
$stmt->execute();
$shipments = $stmt->fetchAll();

/* ------------------------------------------------------------------
 * 6) RECENT TRANSACTIONS (latest 5) — transactions.status: pending | ongoing | completed
 * ------------------------------------------------------------------ */
$transactions = $conn->query(
    "SELECT t.transaction_id, t.status, t.created_at, s.reference_no
     FROM transactions t
     JOIN shipments s ON s.shipment_id = t.shipment_id
     ORDER BY t.created_at DESC, t.transaction_id DESC
     LIMIT 5"
)->fetchAll();

/* ------------------------------------------------------------------
 * 7) RECENT UPDATES (latest 4 status updates)
 * ------------------------------------------------------------------ */
$updates = $conn->query(
    "SELECT s.reference_no, u.stage, u.status, u.remarks, u.updated_at
     FROM shipment_status_updates u
     JOIN shipments s ON s.shipment_id = u.shipment_id
     ORDER BY u.updated_at DESC, u.update_id DESC
     LIMIT 4"
)->fetchAll();

$hasRecentUpdate = false;       // bell dot: any update in the last 7 days
foreach ($updates as $u) {
    if (strtotime($u['updated_at']) >= strtotime('-7 days')) { $hasRecentUpdate = true; break; }
}

/* ------------------------------------------------------------------
 * 8) BILLING & PAYMENTS (5 per page) — paid = VERIFIED payments only
 * ------------------------------------------------------------------ */
$billPerPage = 5;
$billCount   = (int)$conn->query("SELECT COUNT(*) FROM billings")->fetchColumn();
$billPages   = max(1, (int)ceil($billCount / $billPerPage));
$billPage    = min(max(1, (int)($_GET['bp'] ?? 1)), $billPages);

$stmt = $conn->prepare(
    "SELECT v.billing_no, s.reference_no, v.total_amount, v.amount_paid, v.payment_status
     FROM v_billing_balance v
     JOIN shipments s ON s.shipment_id = v.shipment_id
     ORDER BY v.billing_id DESC
     LIMIT :lim OFFSET :off"
);
$stmt->bindValue(':lim', $billPerPage, PDO::PARAM_INT);
$stmt->bindValue(':off', ($billPage - 1) * $billPerPage, PDO::PARAM_INT);
$stmt->execute();
$bills = $stmt->fetchAll();

/* ------------------------------------------------------------------
 * 9) SIDEBAR MENU — same page names as admin.php, so one page can serve both roles
 *    (allow 'staff' as well as 'admin' in those pages' access check)
 * ------------------------------------------------------------------ */
$activePage = 'dashboard';
$menu = [
    'dashboard'    => ['Dashboard',              'staff.php',        'grid'],
    'shipments'    => ['Shipment Management',    'shipments.php',    'box'],
    'clients'      => ['Client Management',      'clients.php',      'users'],
    'billing'      => ['Billing & Payments',     'billing.php',      'billing'],
    'transactions' => ['Transaction Management', 'transactions.php', 'swap'],
    'reports'      => ['Reports',                'reports.php',      'chart'],
];

$words    = preg_split('/\s+/', trim($fullName));
$initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : substr($words[0], 1, 1)));

$shipFrom = $shipCount ? ($shipPage - 1) * $shipPerPage + 1 : 0;
$shipTo   = ($shipPage - 1) * $shipPerPage + count($shipments);
$billFrom = $billCount ? ($billPage - 1) * $billPerPage + 1 : 0;
$billTo   = ($billPage - 1) * $billPerPage + count($bills);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Dashboard | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff.css">
</head>
<body>

<div class="layout">

    <!-- ============ SIDEBAR ============ -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="brand-logo"><?= icon('box') ?></span>
            <span class="brand-name">Astra<span>Track</span></span>
        </div>

        <p class="menu-title">MENU</p>
        <nav class="menu">
            <?php foreach ($menu as $key => [$label, $href, $ico]): ?>
                <a href="<?= e($href) ?>" class="menu-item <?= $key === $activePage ? 'active' : '' ?>">
                    <?= icon($ico) ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <span class="avatar"><?= e($initials) ?></span>
                <div>
                    <strong><?= e($fullName) ?></strong>
                    <small>Staff</small>
                </div>
            </div>
            <a href="logout.php" class="menu-item"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>

    <!-- ============ MAIN ============ -->
    <main class="main">

        <header class="topbar">
            <div>
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
                <h1>Dashboard</h1>
                <p>Monitor shipments, billing, and transactions in one place.</p>
            </div>

            <div class="topbar-right">
                <form class="search" action="staff.php#shipments" method="get" role="search">
                    <?= icon('search') ?>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search shipments or clients" aria-label="Search shipments or clients">
                </form>
                <button class="icon-btn" aria-label="Notifications"><?= icon('bell') ?><?php if ($hasRecentUpdate): ?><i class="dot"></i><?php endif; ?></button>
                <div class="profile">
                    <span class="avatar"><?= e($initials) ?></span>
                    <strong><?= e($fullName) ?></strong>
                    <?= icon('chevron') ?>
                </div>
            </div>
        </header>

        <!-- ============ STAT CARDS ============ -->
        <section class="stats">
            <article class="card stat">
                <div class="stat-head"><span>Total Shipments</span><span class="tile tile-blue"><?= icon('box') ?></span></div>
                <strong><?= $totalShipments ?></strong>
                <small>All recorded shipments</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Ongoing Shipments</span><span class="tile tile-teal"><?= icon('truck') ?></span></div>
                <strong><?= $ongoing ?></strong>
                <small>Customs, trucking, or delivery</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Completed Shipments</span><span class="tile tile-green"><?= icon('check') ?></span></div>
                <strong><?= $completed ?></strong>
                <small>Delivered shipments</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Outstanding Balances</span><span class="tile tile-amber"><?= icon('billing') ?></span></div>
                <strong><?= e(money($outstanding)) ?></strong>
                <small>Unpaid billing records</small>
            </article>
        </section>

        <div class="dash">

            <!-- ============ LEFT COLUMN ============ -->
            <div class="col">

                <article class="card" id="shipments">
                    <div class="card-head">
                        <h2>Recent Shipments</h2>
                        <a href="shipments.php" class="link">View all</a>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>Shipment Reference</th><th>Client</th><th>Current Status</th><th>Last Updated</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!$shipments): ?>
                                <tr><td colspan="5" class="empty"><?= $search !== '' ? 'No shipments or clients match “' . e($search) . '”.' : 'No shipments recorded yet.' ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($shipments as $s): [$label, $class] = statusInfo($s['current_status']); ?>
                                <tr>
                                    <td data-label="Shipment Reference" class="strong"><?= e($s['reference_no']) ?></td>
                                    <td data-label="Client" class="muted-cell"><?= e($s['client_name']) ?></td>
                                    <td data-label="Current Status"><span class="badge badge-<?= e($class) ?>"><?= e($label) ?></span></td>
                                    <td data-label="Last Updated" class="muted-cell"><?= e(fmtDate($s['last_updated'], false)) ?></td>
                                    <td data-label="Action"><button type="button" class="btn-outline" data-ref="<?= e($s['reference_no']) ?>">View</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-foot">
                        <span class="muted">Showing <?= $shipFrom ?>–<?= $shipTo ?> of <?= $shipCount ?> shipments</span>
                        <?= pager('sp', $shipPage, $shipPages, 'shipments') ?>
                    </div>
                </article>

                <article class="card" id="billing">
                    <div class="card-head">
                        <h2>Billing &amp; Payments</h2>
                        <a href="billing.php" class="link">View all</a>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>Billing Reference</th><th>Related Shipment</th><th>Amount Due</th><th>Amount Paid</th><th>Payment Status</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!$bills): ?>
                                <tr><td colspan="5" class="empty">No billing records yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($bills as $b): [$pLabel, $pClass] = paymentInfo($b['payment_status']); ?>
                                <tr>
                                    <td data-label="Billing Reference" class="strong"><?= e($b['billing_no']) ?></td>
                                    <td data-label="Related Shipment" class="muted-cell"><?= e($b['reference_no']) ?></td>
                                    <td data-label="Amount Due" class="strong"><?= e(money($b['total_amount'])) ?></td>
                                    <td data-label="Amount Paid" class="muted-cell"><?= e(money($b['amount_paid'])) ?></td>
                                    <td data-label="Payment Status"><span class="badge badge-<?= e($pClass) ?>"><?= e($pLabel) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-foot">
                        <span class="muted">Showing <?= $billFrom ?>–<?= $billTo ?> of <?= $billCount ?> records</span>
                        <?= pager('bp', $billPage, $billPages, 'billing') ?>
                    </div>
                </article>

            </div>

            <!-- ============ RIGHT COLUMN ============ -->
            <div class="col">

                <article class="card">
                    <h2>Shipment Tracking</h2>
                    <p class="muted track-hint">Check any shipment by its reference number.</p>
                    <form class="track-form" id="trackForm" action="staff.php" method="get" novalidate>
                        <label class="track-input">
                            <?= icon('search') ?>
                            <input type="text" id="trackInput" placeholder="Enter shipment reference number" autocomplete="off" maxlength="50">
                        </label>
                        <button type="submit" class="btn-track">Track</button>
                    </form>
                    <p class="track-error" id="trackError" role="alert" hidden></p>
                </article>

                <article class="card compact">
                    <div class="card-head">
                        <h2>Recent Transactions</h2>
                        <a href="transactions.php" class="link">View all</a>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Reference</th><th>Shipment</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (!$transactions): ?>
                                <tr><td colspan="3" class="empty">No transactions yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($transactions as $t): [$tLabel, $tClass] = txnInfo($t['status']); ?>
                                <tr>
                                    <td data-label="Reference" class="strong"><span>TXN-<?= e(str_pad((string)$t['transaction_id'], 4, '0', STR_PAD_LEFT)) ?><small><?= e(fmtDate($t['created_at'], false)) ?></small></span></td>
                                    <td data-label="Shipment" class="muted-cell"><?= e($t['reference_no']) ?></td>
                                    <td data-label="Status"><span class="badge badge-<?= e($tClass) ?>"><?= e($tLabel) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="card">
                    <div class="card-head"><h2>Recent Updates</h2></div>
                    <?php if (!$updates): ?>
                        <p class="empty">No updates yet. New shipment activity will show up here.</p>
                    <?php else: ?>
                    <ol class="updates">
                        <?php foreach ($updates as $u):
                            [$uLabel, $uClass] = statusInfo($u['stage']);
                            $note = trim((string)$u['remarks']) !== '' ? $u['remarks'] : $u['status'];
                        ?>
                            <li class="dot-<?= e($uClass) ?>">
                                <time><?= e(fmtDate($u['updated_at'])) ?></time>
                                <strong><?= e($u['reference_no']) ?> · <?= e($uLabel) ?></strong>
                                <p><?= e($note) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php endif; ?>
                </article>

            </div>
        </div>

    </main>
</div>

<!-- ============ SHIPMENT DETAILS (filled by staff.js) ============ -->
<dialog class="modal" id="detailModal" aria-labelledby="modalTitle">
    <div class="modal-head">
        <div>
            <h2 id="modalTitle"></h2>
            <span class="badge" id="modalStatus"></span>
        </div>
        <button type="button" class="modal-close" id="modalClose" aria-label="Close"><?= icon('close') ?></button>
    </div>
    <dl class="detail-grid" id="modalDetails"></dl>
    <h3>Progress</h3>
    <ol class="updates" id="modalTimeline"></ol>
</dialog>

<script src="assets/js/staff.js"></script>
</body>
</html>
