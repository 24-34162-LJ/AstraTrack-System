<?php
/**
 * client_dashbroad.php — AstraTrack Client Dashboard
 * Files: client_dashbroad.php (this), assets/css/client_dashbroad.css, assets/js/client_dashbroad.js
 * Tables used: users, clients, shipments, shipment_status_updates, billings (view v_billing_balance)
 */
session_start();
require_once 'config/database.php';   // gives you $conn (PDO)

/* ------------------------------------------------------------------
 * 1) ACCESS CONTROL — only logged-in clients may open this page
 * ------------------------------------------------------------------ */
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: login.php');
    exit;
}
$currentUser = $_SESSION['user'];
$userId      = (int)($currentUser['id'] ?? $currentUser['user_id'] ?? 0);

// login.php only stores id / full_name / role, so look up which client company this user belongs to
$st = $conn->prepare(
    "SELECT u.client_id, c.client_name
     FROM users u
     LEFT JOIN clients c ON c.client_id = u.client_id
     WHERE u.user_id = ? AND u.status = 'active'
     LIMIT 1"
);
$st->execute([$userId]);
$account = $st->fetch();

if (!$account) {                       // account removed or deactivated while logged in
    session_destroy();
    header('Location: login.php');
    exit;
}
$clientId    = (int)$account['client_id'];            // 0 = user is not linked to a client yet
$displayName = $account['client_name'] ?: $currentUser['full_name'];

/* ------------------------------------------------------------------
 * 2) HELPERS
 * ------------------------------------------------------------------ */
function e($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }

function fmtDate($value) { return $value ? date('M d, Y', strtotime($value)) : '—'; }

function money($value) {
    $value = (float)$value;
    return 'PHP ' . number_format($value, floor($value) == $value ? 0 : 2);
}

/* shipments.current_status is free text, so it is grouped into the 4 labels of the design
 * (same grouping as admin.php). Edit the lists if you use other wording. */
function statusInfo($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'documents received')
        return ['Pending Documents', 'amber'];
    if (in_array($s, ['declaration filed', 'assessment', 'duties paid', 'examination', 'released/cleared'], true))
        return ['Customs Processing', 'blue'];
    if (in_array($s, ['in transit', 'trucking'], true))
        return ['In Transit', 'teal'];
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

// Link that changes one page number but keeps the other table's page
function pageUrl($key, $n, $anchor) {
    $q = $_GET;
    unset($q['ajax'], $q['q']);
    $q[$key] = $n;
    return '?' . http_build_query($q) . '#' . $anchor;
}

function pager($key, $page, $pages, $anchor) {
    if ($pages <= 1) return '';
    $html  = '<nav class="pager" aria-label="Pagination">';
    $html .= $page > 1
        ? '<a class="page-btn" href="' . e(pageUrl($key, $page - 1, $anchor)) . '" aria-label="Previous page">' . icon('left') . '</a>'
        : '<span class="page-btn disabled">' . icon('left') . '</span>';
    $from = max(1, min($page - 2, $pages - 4));
    $to   = min($pages, $from + 4);
    for ($i = $from; $i <= $to; $i++) {
        $html .= $i === $page
            ? '<span class="page-btn active" aria-current="page">' . $i . '</span>'
            : '<a class="page-btn" href="' . e(pageUrl($key, $i, $anchor)) . '">' . $i . '</a>';
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
        'billing' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M12 7v1.5m0 5V15m2-5.5c0-.8-.9-1.5-2-1.5s-2 .7-2 1.5 .9 1.3 2 1.5 2 .7 2 1.5-.9 1.5-2 1.5-2-.7-2-1.5"/>',
        'swap'    => '<path d="M4 8h16m-4-4l4 4-4 4M20 16H4m4-4l-4 4 4 4"/>',
        'user'    => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/>',
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
 * 3) TRACK / VIEW DETAILS (JSON endpoint used by client_dashbroad.js)
 * ------------------------------------------------------------------
 * GET client_dashbroad.php?ajax=track&q=<reference no or Bill of Lading>
 * Only shipments that belong to THIS client can be found.
 */
if (($_GET['ajax'] ?? '') === 'track') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)($_GET['q'] ?? ''));

    if ($q === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a Bill of Lading or shipment reference number.']);
        exit;
    }

    $st = $conn->prepare(
        "SELECT shipment_id, reference_no, transaction_type, mode, bl_awb_no, shipper_name, consignee_name,
                vessel_or_flight, port_of_loading, port_of_discharge, incoterm, current_status
         FROM shipments
         WHERE client_id = ? AND (reference_no = ? OR bl_awb_no = ?)
         LIMIT 1"
    );
    $st->execute([$clientId, $q, $q]);
    $s = $st->fetch();

    if (!$s) {
        http_response_code(404);
        echo json_encode(['error' => 'No shipment on your account matches "' . $q . '". Check the number and try again.']);
        exit;
    }

    [$label, $class] = statusInfo($s['current_status']);

    $fields = [
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
$stmt = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(LOWER(TRIM(current_status)) = 'delivered'), 0) AS delivered
                        FROM shipments WHERE client_id = ?");
$stmt->execute([$clientId]);
$counts         = $stmt->fetch();
$totalShipments = (int)$counts['total'];
$delivered      = (int)$counts['delivered'];
$inProgress     = $totalShipments - $delivered;

/* ------------------------------------------------------------------
 * 5) MY RECENT SHIPMENTS (5 per page)
 * ------------------------------------------------------------------ */
$perPage    = 5;
$shipPages  = max(1, (int)ceil($totalShipments / $perPage));
$shipPage   = min(max(1, (int)($_GET['sp'] ?? 1)), $shipPages);

$stmt = $conn->prepare(
    "SELECT s.reference_no, s.current_status,
            DATE(s.created_at) AS shipment_date,
            COALESCE((SELECT MAX(u.updated_at) FROM shipment_status_updates u WHERE u.shipment_id = s.shipment_id),
                     s.created_at) AS last_updated
     FROM shipments s
     WHERE s.client_id = :cid
     ORDER BY shipment_date DESC, s.shipment_id DESC
     LIMIT :lim OFFSET :off"
);
$stmt->bindValue(':cid', $clientId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', ($shipPage - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$shipments = $stmt->fetchAll();

/* ------------------------------------------------------------------
 * 6) RECENT UPDATES (latest 4 status updates)
 * ------------------------------------------------------------------ */
$stmt = $conn->prepare(
    "SELECT s.reference_no, u.stage, u.status, u.remarks, u.updated_at
     FROM shipment_status_updates u
     JOIN shipments s ON s.shipment_id = u.shipment_id
     WHERE s.client_id = ?
     ORDER BY u.updated_at DESC, u.update_id DESC
     LIMIT 4"
);
$stmt->execute([$clientId]);
$updates = $stmt->fetchAll();

$hasRecentUpdate = false;       // bell dot: any update in the last 7 days
foreach ($updates as $u) {
    if (strtotime($u['updated_at']) >= strtotime('-7 days')) { $hasRecentUpdate = true; break; }
}

/* ------------------------------------------------------------------
 * 7) MY BILLING & PAYMENTS (5 per page) — paid = VERIFIED payments only (v_billing_balance)
 * ------------------------------------------------------------------ */
$stmt = $conn->prepare("SELECT COUNT(*) FROM billings WHERE client_id = ?");
$stmt->execute([$clientId]);
$totalBills = (int)$stmt->fetchColumn();
$billPages  = max(1, (int)ceil($totalBills / $perPage));
$billPage   = min(max(1, (int)($_GET['bp'] ?? 1)), $billPages);

$stmt = $conn->prepare(
    "SELECT v.billing_no, s.reference_no, v.total_amount, v.amount_paid, v.payment_status
     FROM v_billing_balance v
     JOIN shipments s ON s.shipment_id = v.shipment_id
     WHERE v.client_id = :cid
     ORDER BY v.billing_id DESC
     LIMIT :lim OFFSET :off"
);
$stmt->bindValue(':cid', $clientId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', ($billPage - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$bills = $stmt->fetchAll();

/* ------------------------------------------------------------------
 * 8) SIDEBAR MENU — create these pages next; the links are ready
 * ------------------------------------------------------------------ */
$activePage = 'dashboard';
$menu = [
    'dashboard'    => ['Dashboard',            'client_dashbroad.php',  'grid'],
    'shipments'    => ['My Shipments',         'client_shipments.php',  'box'],
    'billing'      => ['My Billing & Payments','client_billing.php',    'billing'],
    'transactions' => ['My Transactions',      'client_transactions.php','swap'],
    'profile'      => ['My Profile',           'client_profile.php',    'user'],
];

$words    = preg_split('/\s+/', trim($displayName));
$initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : substr($words[0], 1, 1)));

$shipFrom = $totalShipments ? ($shipPage - 1) * $perPage + 1 : 0;
$shipTo   = ($shipPage - 1) * $perPage + count($shipments);
$billFrom = $totalBills ? ($billPage - 1) * $perPage + 1 : 0;
$billTo   = ($billPage - 1) * $perPage + count($bills);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Client Dashboard | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/client_dashbroad.css">
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
            <a href="logout.php" class="menu-item"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>

    <!-- ============ MAIN ============ -->
    <main class="main">

        <header class="topbar">
            <div>
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
                <h1>Welcome to AstraTrack</h1>
                <p>Monitor your shipments and review your transaction information.</p>
            </div>

            <div class="topbar-right">
                <button class="icon-btn" aria-label="Notifications"><?= icon('bell') ?><?php if ($hasRecentUpdate): ?><i class="dot"></i><?php endif; ?></button>
                <div class="profile">
                    <span class="avatar"><?= e($initials) ?></span>
                    <div>
                        <strong><?= e($displayName) ?></strong>
                        <small>Client</small>
                    </div>
                    <?= icon('chevron') ?>
                </div>
            </div>
        </header>

        <?php if ($clientId === 0): ?>
            <p class="notice">Your account is not linked to a client company yet. Please contact AstraTrack staff.</p>
        <?php endif; ?>

        <!-- ============ TRACK BANNER ============ -->
        <section class="track">
            <div class="track-text">
                <h2>Track your shipment</h2>
                <p>Enter your Bill of Lading or shipment reference number. Only shipments linked to your account can be tracked.</p>
            </div>
            <form class="track-form" id="trackForm" action="client_dashbroad.php" method="get" novalidate>
                <label class="track-input">
                    <?= icon('search') ?>
                    <input type="text" id="trackInput" name="q" placeholder="Bill of Lading or shipment reference no." autocomplete="off" maxlength="50">
                </label>
                <button type="submit" class="btn-track">Track</button>
            </form>
            <p class="track-error" id="trackError" role="alert" hidden></p>
        </section>

        <!-- ============ STAT CARDS ============ -->
        <section class="stats">
            <article class="card stat">
                <div class="stat-head"><span>Total Shipments</span><span class="tile tile-blue"><?= icon('box') ?></span></div>
                <strong><?= $totalShipments ?></strong>
                <small>All shipments on your account</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>In Progress</span><span class="tile tile-teal"><?= icon('truck') ?></span></div>
                <strong><?= $inProgress ?></strong>
                <small>Documents, customs, or transit</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Delivered</span><span class="tile tile-green"><?= icon('check') ?></span></div>
                <strong><?= $delivered ?></strong>
                <small>Completed deliveries</small>
            </article>
        </section>

        <!-- ============ SHIPMENTS + UPDATES ============ -->
        <section class="row">

            <article class="card" id="shipments">
                <div class="card-head">
                    <h2>My Recent Shipments</h2>
                    <a href="client_shipments.php" class="link">View all</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>Shipment Reference</th><th>Shipment Date</th><th>Current Status</th><th>Last Updated</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$shipments): ?>
                            <tr><td colspan="5" class="empty">No shipments yet. Shipments linked to your account will appear here.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($shipments as $s): [$label, $class] = statusInfo($s['current_status']); ?>
                            <tr>
                                <td data-label="Shipment Reference" class="strong"><?= e($s['reference_no']) ?></td>
                                <td data-label="Shipment Date" class="muted-cell"><?= e(fmtDate($s['shipment_date'])) ?></td>
                                <td data-label="Current Status"><span class="badge badge-<?= e($class) ?>"><?= e($label) ?></span></td>
                                <td data-label="Last Updated" class="muted-cell"><?= e(fmtDate($s['last_updated'])) ?></td>
                                <td data-label="Action"><button type="button" class="btn-outline" data-ref="<?= e($s['reference_no']) ?>">View Details</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-foot">
                    <span class="muted">Showing <?= $shipFrom ?>–<?= $shipTo ?> of <?= $totalShipments ?> shipments</span>
                    <?= pager('sp', $shipPage, $shipPages, 'shipments') ?>
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
        </section>

        <!-- ============ BILLING ============ -->
        <section class="card" id="billing">
            <div class="card-head">
                <h2>My Billing &amp; Payments</h2>
                <a href="client_billing.php" class="link">View all</a>
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
                <span class="muted">Showing <?= $billFrom ?>–<?= $billTo ?> of <?= $totalBills ?> records</span>
                <?= pager('bp', $billPage, $billPages, 'billing') ?>
            </div>
        </section>

    </main>
</div>

<!-- ============ SHIPMENT DETAILS (filled by client_dashbroad.js) ============ -->
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

<script src="assets/js/client_dashbroad.js"></script>
</body>
</html>
