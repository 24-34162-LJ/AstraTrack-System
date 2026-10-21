<?php
/**
 * admin.php — AstraTrack Admin Dashboard
 * Files: admin.php (this), assets/css/admin.css, assets/js/admin.js, db.php
 * Tables used: users, shipments, transactions, transaction_history, view v_billing_balance
 *              (all from astratrack_mysql.sql)
 */
session_start();
require_once 'config/database.php';   // gives you $pdo

/* ------------------------------------------------------------------
 * 1) ACCESS CONTROL — only admins may open this page
 * ------------------------------------------------------------------
 * login.php must save:
 *     $_SESSION['user'] = ['user_id' => $row['user_id'], 'full_name' => $row['full_name'], 'role' => $row['role']];
 * (role comes from users.role: 'admin', 'staff' or 'client')
 */
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
$currentUser = $_SESSION['user'];

/* ------------------------------------------------------------------
 * 2) DASHBOARD NUMBERS (real data from the database)
 * ------------------------------------------------------------------ */
$totalUsers     = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalShipments = (int)$conn->query("SELECT COUNT(*) FROM shipments")->fetchColumn();
$ongoingTxns    = (int)$conn->query("SELECT COUNT(*) FROM transactions WHERE status = 'ongoing'")->fetchColumn();

// Outstanding = billed minus VERIFIED payments (the view v_billing_balance already does this)
$outstandingAmount = (float)$conn->query("SELECT COALESCE(SUM(GREATEST(balance, 0)), 0) FROM v_billing_balance")->fetchColumn();

/* ------------------------------------------------------------------
 * 3) SHIPMENT OVERVIEW
 * ------------------------------------------------------------------
 * shipments.current_status is free text (VARCHAR), so the stages are grouped
 * into the 4 bars of the design. Edit the lists below if you use other wording.
 * Statuses not listed here are not counted in the bars.
 */
$statusGroups = [
    ['label' => 'Pending Documents',  'class' => 'amber', 'stages' => ['documents received']],
    ['label' => 'Customs Processing', 'class' => 'blue',  'stages' => ['declaration filed', 'assessment', 'duties paid', 'examination', 'released/cleared']],
    ['label' => 'In Transit',         'class' => 'teal',  'stages' => ['in transit', 'trucking']],
    ['label' => 'Delivered',          'class' => 'green', 'stages' => ['delivered']],
];

$perStatus = [];   // e.g. ['in transit' => 13, ...]
foreach ($conn->query("SELECT current_status, COUNT(*) AS c FROM shipments GROUP BY current_status") as $r) {
    $perStatus[strtolower(trim($r['current_status']))] = (int)$r['c'];
}

$shipmentStatus = [];
foreach ($statusGroups as $g) {
    $count = 0;
    foreach ($g['stages'] as $stage) {
        $count += $perStatus[$stage] ?? 0;
    }
    $shipmentStatus[] = ['label' => $g['label'], 'count' => $count, 'class' => $g['class']];
}

/* ------------------------------------------------------------------
 * 4) TRANSACTION OVERVIEW (transactions.status: pending | ongoing | completed)
 * ------------------------------------------------------------------ */
$txnCounts = ['pending' => 0, 'ongoing' => 0, 'completed' => 0];
foreach ($conn->query("SELECT status, COUNT(*) AS c FROM transactions GROUP BY status") as $r) {
    $txnCounts[$r['status']] = (int)$r['c'];
}
$txnStatus = [
    ['label' => 'Pending',   'count' => $txnCounts['pending'],   'class' => 'amber'],
    ['label' => 'Ongoing',   'count' => $txnCounts['ongoing'],   'class' => 'blue'],
    ['label' => 'Completed', 'count' => $txnCounts['completed'], 'class' => 'green'],
];

/* ------------------------------------------------------------------
 * 5) RECENT ACTIVITY (latest 5 rows of activity_log)
 * ------------------------------------------------------------------ */
$recentActivity = [];
$activityRows = $conn->query(
    "SELECT DATE_FORMAT(created_at, '%b %d, %h:%i %p') AS happened,
            activity_type, description, performed_by_name, performed_by_role
     FROM activity_log
     ORDER BY created_at DESC, activity_id DESC
     LIMIT 5"
)->fetchAll();
 
foreach ($activityRows as $r) {
    if ($r['performed_by_name'] === null) {
        $by = 'System';
    } elseif ($r['performed_by_role'] === 'admin') {
        $by = $r['performed_by_name'];
    } else {
        $by = $r['performed_by_name'] . ' (' . ucfirst((string)$r['performed_by_role']) . ')';
    }
    $recentActivity[] = [$r['happened'], $r['activity_type'], (string)$r['description'], $by];
}


/* ------------------------------------------------------------------
 * 6) SIDEBAR MENU
 * ------------------------------------------------------------------
 * HOW TO CONNECT USER MANAGEMENT:
 *   - Create a file named user_management.php (list / add / edit / delete users).
 *   - Point the 'href' below to it. The "Manage Users" button in Quick Actions
 *     uses the same link.
 *   - In user_management.php, copy the same sidebar and set
 *     $activePage = 'users' so the right menu item is highlighted.
 *   - Do the same for the other pages (shipments.php, clients.php, ...).
 */
$activePage = 'dashboard';

$menu = [
    'dashboard'    => ['Dashboard',              'admin.php',             'grid'],
    'shipments'    => ['Shipment Management',    'shipments.php',         'box'],
    'clients'      => ['Client Management',      'clients.php',           'users'],
    'billing'      => ['Billing & Payments',     'billing.php',           'receipt'],
    'transactions' => ['Transaction Management', 'transactions.php',      'swap'],
    'reports'      => ['Reports',                'reports.php',           'chart'],
    'users'        => ['User Management',        'user_management.php',   'shield'],   // <-- connect here
];

/* ------------------------------------------------------------------
 * 7) HELPERS
 * ------------------------------------------------------------------ */
function e($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }

function pct($value, $total) { return $total > 0 ? round($value / $total * 100, 1) : 0; }

function badgeClass($type) {
    $t = strtolower($type);
    if (strpos($t, 'bill') !== false || strpos($t, 'pay') !== false) return 'badge-amber';
    if (strpos($t, 'user') !== false || strpos($t, 'account') !== false) return 'badge-teal';
    return 'badge-blue';   // shipment updates and everything else
}

// Small inline SVG icon set (stroke icons)
function icon($name) {
    $paths = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'     => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2 .7 3.5 2.6 3.5 5.5"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
        'swap'    => '<path d="M4 8h16m-4-4l4 4-4 4M20 16H4m4-4l-4 4 4 4"/>',
        'chart'   => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'shield'  => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'logout'  => '<path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9"/>',
        'search'  => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        'bell'    => '<path d="M6 16V11a6 6 0 1112 0v5l1.5 2h-15L6 16z"/><path d="M10 21h4"/>',
        'alert'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.5"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

$initials = strtoupper(substr($currentUser['full_name'], 0, 1) . substr(strrchr(' ' . $currentUser['full_name'], ' '), 1, 1));
$txnTotal = array_sum(array_column($txnStatus, 'count'));
$shipTotal = array_sum(array_column($shipmentStatus, 'count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>

<div class="layout">

    <!-- ============ SIDEBAR ============ -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="brand-logo"><?= icon('box') ?></span>
            <span class="brand-name">Astra<span>Track</span></span>
        </div>

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
                    <strong><?= e($currentUser['full_name']) ?></strong>
                    <small><?= e(ucfirst($currentUser['role'])) ?></small>
                </div>
            </div>
            <!-- logout.php should run: session_destroy(); header('Location: login.php'); -->
            <a href="logout.php" class="menu-item logout"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>

    <!-- ============ MAIN ============ -->
    <main class="main">

        <header class="topbar">
            <div>
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
                <h1>Admin Dashboard</h1>
                <p>Oversee system records, user accounts, and operational activity.</p>
            </div>

            <div class="topbar-right">
                <!-- Connect search: point action to search.php (reads $_GET['q']) -->
                <form class="search" action="search.php" method="get">
                    <?= icon('search') ?>
                    <input type="text" name="q" placeholder="Search records or users">
                </form>

                <button class="icon-btn" aria-label="Notifications"><?= icon('bell') ?><i class="dot"></i></button>

                <div class="profile">
                    <span class="avatar"><?= e($initials) ?></span>
                    <div>
                        <strong><?= e($currentUser['full_name']) ?></strong>
                        <small><?= e(ucfirst($currentUser['role'])) ?></small>
                    </div>
                    <?= icon('chevron') ?>
                </div>
            </div>
        </header>

        <!-- ============ STAT CARDS ============ -->
        <section class="stats">
            <article class="card stat">
                <div class="stat-head"><span>Total Users</span><?= icon('users') ?></div>
                <strong data-count="<?= (int)$totalUsers ?>"><?= (int)$totalUsers ?></strong>
                <small>Admin, staff, and client accounts</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Total Shipments</span><?= icon('box') ?></div>
                <strong data-count="<?= (int)$totalShipments ?>"><?= (int)$totalShipments ?></strong>
                <small>All recorded shipments</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Ongoing Transactions</span><?= icon('swap') ?></div>
                <strong data-count="<?= (int)$ongoingTxns ?>"><?= (int)$ongoingTxns ?></strong>
                <small>Currently in progress</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Outstanding Balances</span><?= icon('alert') ?></div>
                <strong>PHP <?= number_format($outstandingAmount) ?></strong>
                <small>Billed minus payments received</small>
            </article>
        </section>

        <!-- ============ OVERVIEW ROW ============ -->
        <section class="overview">

            <article class="card">
                <div class="card-head">
                    <h2>Shipment Overview</h2>
                    <span class="muted"><?= (int)$shipTotal ?> shipments by status</span>
                </div>
                <?php foreach ($shipmentStatus as $s): ?>
                    <div class="bar-row">
                        <span class="bar-label"><?= e($s['label']) ?></span>
                        <div class="bar-track">
                            <div class="bar-fill <?= e($s['class']) ?>" data-width="<?= pct($s['count'], $shipTotal) ?>"></div>
                        </div>
                        <span class="bar-count"><?= (int)$s['count'] ?></span>
                    </div>
                <?php endforeach; ?>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2>Transaction Overview</h2>
                    <span class="muted"><?= (int)$txnTotal ?> total</span>
                </div>
                <div class="stack">
                    <?php foreach ($txnStatus as $t): ?>
                        <div class="<?= e($t['class']) ?>" style="width: <?= pct($t['count'], $txnTotal) ?>%"></div>
                    <?php endforeach; ?>
                </div>
                <ul class="legend">
                    <?php foreach ($txnStatus as $t): ?>
                        <li>
                            <i class="swatch <?= e($t['class']) ?>"></i>
                            <span class="legend-name"><?= e($t['label']) ?></span>
                            <b><?= (int)$t['count'] ?></b>
                            <small><?= pct($t['count'], $txnTotal) ?>%</small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>

            <article class="card">
                <div class="card-head"><h2>Quick Actions</h2></div>
                <p class="muted">Jump to common administrator tasks.</p>
                <div class="actions">
                    <!-- USER MANAGEMENT LINK: change this href to your real page -->
                    <a href="user_management.php" class="btn btn-primary">Manage Users</a>
                    <a href="reports.php" class="btn">View Reports</a>
                    <a href="shipments.php" class="btn">View Shipments</a>
                </div>
            </article>
        </section>

        <!-- ============ RECENT ACTIVITY ============ -->
        <section class="card activity">
            <div class="card-head">
                <h2>Recent Activity</h2>
                <a href="activity.php" class="link">View all</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Date &amp; Time</th><th>Activity Type</th><th>Description</th><th>Performed By</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentActivity): ?>
                            <tr><td colspan="4" class="muted-cell">No activity recorded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentActivity as [$when, $type, $desc, $by]): ?>
                            <tr>
                                <td><?= e($when) ?></td>
                                <td><span class="badge <?= badgeClass($type) ?>"><?= e($type) ?></span></td>
                                <td><?= e($desc) ?></td>
                                <td class="muted-cell"><?= e($by) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<script src="assets/js/admin.js"></script>
</body>
</html>
