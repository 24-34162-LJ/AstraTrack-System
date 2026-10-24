<?php
/**
 * shipment_common.php — helpers shared by shipments.php (list) and shipment_view.php (details)
 * Status wording comes from the shipment_stages table (see shipment_stages.php), grouped into the 4 stages of the Figma design.
 */

function e($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }

function fmtDate($value) { return $value ? date('M d, Y', strtotime((string)$value)) : '—'; }

function fmtDateTime($value) { return $value ? date('M d, Y · g:i A', strtotime((string)$value)) : '—'; }

// Pickup schedule: show the time only when one was entered (a plain date has 00:00)
function fmtSchedule($value, $time = null)
{
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    if ($time !== null && trim((string)$time) !== '' && substr((string)$time, 0, 5) !== '00:00') {
        return date('M d, Y', $ts) . ', ' . date('g:i A', strtotime((string)$time));
    }
    return date('M d, Y', $ts);
}

/* ---------- statuses ---------- */
// statusGroups(), statusKey(), statusInfo() and progressSteps() read the shipment_stages table.
require_once __DIR__ . '/shipment_stages.php';
require_once __DIR__ . '/notifications.php';

function badge(string $label, string $class): string
{
    return '<span class="badge badge-' . e($class) . '">' . e($label) . '</span>';
}

/* ---------- access, menu, layout ---------- */

// Active staff or admin only. Returns [user id, full name, role]
// $asJson = true is for AJAX endpoints: instead of redirecting to the login page it answers
// 401 (no valid session) or 403 (logged in but not staff/admin, e.g. a client) as JSON.
function requireStaffOrAdmin(PDO $conn, bool $asJson = false): array
{
    $sess   = $_SESSION['user'] ?? [];
    $userId = (int)($sess['id'] ?? $sess['user_id'] ?? 0);
    if ($userId > 0) {
        $st = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ? AND status = 'active' AND role IN ('staff','admin')");
        $st->execute([$userId]);
        $me = $st->fetch();
        if ($me) return [$userId, $me['full_name'], $me['role']];
    }
    if ($asJson) {
        $code = 401;
        $msg  = 'Your session expired. Please log in again.';
        if ($userId > 0) {
            $st = $conn->prepare("SELECT 1 FROM users WHERE user_id = ? AND status = 'active'");
            $st->execute([$userId]);
            if ($st->fetchColumn()) { $code = 403; $msg = 'Only staff and administrators can update a shipment status.'; }
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: login.php');
    exit;
}

function homePage(string $role): string { return $role === 'admin' ? 'admin.php' : 'staff.php'; }

// Shipment Management list: admin -> shipments.php, staff -> staff_shipment.php
function shipListPage(string $role): string { return $role === 'admin' ? 'shipments.php' : 'staff_shipment.php'; }

function sidebarMenu(string $role): array
{
    $menu = [
        'dashboard'    => ['Dashboard',              homePage($role),       'grid'],
        'shipments'    => ['Shipment Management',    shipListPage($role),   'box'],
        'clients'      => ['Client Management',      'clients.php',         'users'],
        'billing'      => ['Billing & Payments',     'billing.php',         'billing'],
        'transactions' => ['Transaction Management', 'transactions.php',    'swap'],
        'reports'      => ['Reports',                'reports.php',         'chart'],
    ];
    return $menu;
}

// Admin only: the ADMINISTRATION group under the main menu (Figma)
function adminMenu(string $role): array
{
    return $role === 'admin' ? ['users' => ['User Management', 'user_management.php', 'shield']] : [];
}

function initialsOf(string $fullName): string
{
    $words = preg_split('/\s+/', trim($fullName));
    return strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : substr($words[0], 1, 1)));
}

// Bell dot: any status update in the last 7 days (same rule as staff.php)
function hasRecentUpdate(PDO $conn): bool
{
    return (int)$conn->query("SELECT COUNT(*) FROM shipment_status_updates WHERE updated_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn() > 0;
}

function icon($name)
{
    $paths = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'     => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2 .7 3.5 2.6 3.5 5.5"/>',
        'billing' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
        'swap'    => '<path d="M4 8h16m-4-4l4 4-4 4M20 16H4m4-4l-4 4 4 4"/>',
        'chart'   => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'logout'  => '<path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9"/>',
        'search'  => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        'bell'    => '<path d="M6 16V11a6 6 0 1112 0v5l1.5 2h-15L6 16z"/><path d="M10 21h4"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'left'    => '<path d="M15 6l-6 6 6 6"/>',
        'right'   => '<path d="M9 6l6 6-6 6"/>',
        'shield'  => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'check'   => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'user'    => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/>',
        'download' => '<path d="M12 4v11M7 11l5 5 5-5M5 20h14"/>',
        'edit'    => '<path d="M4 20h4L19 9a2.1 2.1 0 00-3-3L5 17v3z"/><path d="M14.5 7.5l3 3"/>',
        'refresh' => '<path d="M20 11a8 8 0 10-2.3 5.7"/><path d="M20 4v7h-7"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function renderSidebar(string $role, string $active, string $fullName): void
{
    $menu = sidebarMenu($role);
    $initials = initialsOf($fullName);
    ?>
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="brand-logo"><?= icon('box') ?></span>
            <span class="brand-name">Astra<span>Track</span></span>
        </div>

        <p class="menu-title">MENU</p>
        <nav class="menu">
            <?php foreach ($menu as $key => [$label, $href, $ico]): ?>
                <a href="<?= e($href) ?>" class="menu-item <?= $key === $active ? 'active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                    <?= icon($ico) ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (adminMenu($role)): ?>
            <p class="menu-title menu-title-gap">ADMINISTRATION</p>
            <nav class="menu">
                <?php foreach (adminMenu($role) as $key => [$label, $href, $ico]): ?>
                    <a href="<?= e($href) ?>" class="menu-item <?= $key === $active ? 'active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                        <?= icon($ico) ?><span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <span class="avatar"><?= e($initials) ?></span>
                <div>
                    <strong><?= e($fullName) ?></strong>
                    <small><?= e(ucfirst($role)) ?></small>
                </div>
            </div>
            <a href="logout.php" class="menu-item"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>
    <?php
}

// $crumbs: [[label, href|null], ...]. The last one is the current page.
function renderTopnav(array $crumbs, string $fullName, bool $bellDot): void
{
    global $conn;
    $initials = initialsOf($fullName);
    $last = count($crumbs) - 1;
    $sessionUserId = (int)($_SESSION['user']['id'] ?? $_SESSION['user']['user_id'] ?? 0);
    ?>
    <header class="topnav">
        <div class="topnav-left">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
            <nav class="crumbs" aria-label="Breadcrumb">
                <?php foreach ($crumbs as $i => [$label, $href]): ?>
                    <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
                    <?php if ($i === $last): ?>
                        <span class="here" aria-current="page"><?= e($label) ?></span>
                    <?php else: ?>
                        <a href="<?= e($href) ?>"><?= e($label) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="topbar-right">
            <?php if ($sessionUserId > 0): renderNotificationBell($conn, $sessionUserId, (string)($_SESSION['user']['role'] ?? '')); endif; ?>
            <div class="profile">
                <span class="avatar"><?= e($initials) ?></span>
                <strong><?= e($fullName) ?></strong>
                <?= icon('chevron') ?>
            </div>
        </div>
    </header>
    <script src="assets/js/notifications.js?v=20261024-2" defer></script>
    <?php
}

/* ---------- shipment progress (stepper + timeline), shared by staff, admin and client pages ---------- */

/**
 * Items for the stepper and the vertical timeline.
 * Returns ['items' => [...], 'lastUpdated' => datetime]. Each item:
 *   kind  created | stage | exception      state  done | current | upcoming | hold | cancelled
 *   label, date (long, with time and stage name), short (date only, for the stepper), note (remarks), colour
 * Reads shipment_status_updates, so every saved Update Status row shows up here with its remarks.
 * $createdNote is the line under "Shipment Record Created" (it differs for staff and clients).
 */
function buildProgress(PDO $conn, array $ship, string $createdNote): array
{
    $st = $conn->prepare(
        "SELECT stage, remarks, updated_at FROM shipment_status_updates
         WHERE shipment_id = ? ORDER BY updated_at ASC, update_id ASC"
    );
    $st->execute([(int)$ship['shipment_id']]);
    $updates = $st->fetchAll();

    $lastUpdated = $ship['created_at'];
    foreach ($updates as $u) {
        if (strtotime($u['updated_at']) > strtotime($lastUpdated)) $lastUpdated = $u['updated_at'];
    }

    $stages = progressSteps();                       // created + the 4 groups, from shipment_stages
    $order  = array_flip(array_keys($stages));
    $latest = [];                                    // newest update per group key (incl. hold / cancelled)
    $lastProgressKey = null;                         // group of the newest update that is a normal stage
    foreach ($updates as $u) {
        $k = statusKey($u['stage']);
        if ($k === null) continue;
        $latest[$k] = $u;
        if (isset($order[$k]) && $k !== 'created') $lastProgressKey = $k;
    }

    $curKey      = statusKey($ship['current_status']);
    $exceptionKey = ($curKey === 'hold' || $curKey === 'cancelled') ? $curKey : null;
    if ($curKey === null || !isset($order[$curKey])) {   // On Hold / Cancelled: stay on the last normal stage reached
        $curKey = $lastProgressKey ?? array_keys($order)[1];
    }
    $curIdx = $order[$curKey];

    $items = [];
    foreach ($stages as $key => $label) {
        $i = $order[$key];
        if ($key === 'created') {
            $items[] = ['kind' => 'created', 'label' => $label, 'state' => 'done',
                        'date' => fmtDateTime($ship['created_at']), 'short' => fmtDate($ship['created_at']),
                        'note' => $createdNote, 'colour' => 'blue'];
            continue;
        }
        if ($i < $curIdx || ($i === $curIdx && $key === 'delivered')) $state = 'done';
        elseif ($i === $curIdx)                                       $state = 'current';
        else                                                          $state = 'upcoming';

        if ($state === 'upcoming') {
            $items[] = ['kind' => 'stage', 'label' => $label, 'state' => $state,
                        'date' => 'Awaiting update', 'short' => 'Awaiting update', 'note' => '', 'colour' => 'blue'];
            continue;
        }
        $u = $latest[$key] ?? null;
        $suffix = ($u && strcasecmp($u['stage'], $label) !== 0) ? ' · ' . $u['stage'] : '';
        $items[] = ['kind' => 'stage', 'label' => $label, 'state' => $state,
                    'date'  => $u ? fmtDateTime($u['updated_at']) . $suffix : 'Date not recorded',
                    'short' => $u ? fmtDate($u['updated_at']) : 'Date not recorded',
                    'note'  => $u ? (string)$u['remarks'] : '', 'colour' => 'blue'];
    }

    // On Hold / Cancelled: one extra entry at the end so its (required) remarks are visible
    if ($exceptionKey !== null) {
        $u = $latest[$exceptionKey] ?? null;
        [, $colour] = statusInfo($ship['current_status']);
        $items[] = ['kind' => 'exception', 'label' => (string)$ship['current_status'], 'state' => $exceptionKey,
                    'date' => $u ? fmtDateTime($u['updated_at']) : 'Date not recorded',
                    'short' => $u ? fmtDate($u['updated_at']) : '', 'note' => $u ? (string)$u['remarks'] : '',
                    'colour' => $colour];
    }
    return ['items' => $items, 'lastUpdated' => $lastUpdated];
}

// Line under "Shipment Record Created" on the staff / admin pages
function staffCreatedNote(array $ship): string
{
    return 'Record created' . (!empty($ship['staff_name']) ? ' by ' . $ship['staff_name'] : '') . '.';
}

// <li> items of the 4-step stepper (the exception entry is not a step)
function renderStepperItems(array $items): string
{
    $h = '';
    foreach ($items as $t) {
        if ($t['kind'] !== 'stage') continue;
        $h .= '<li class="step step-' . e($t['state']) . '"><span class="step-dot">' . ($t['state'] === 'done' ? icon('check') : '') . '</span>'
            . '<span class="step-text"><span class="step-label">' . e($t['label']) . '</span><span class="step-date">' . e($t['short']) . '</span></span></li>';
    }
    return $h;
}

// <li> items of the vertical timeline
function renderTimelineItems(array $items): string
{
    $h = '';
    foreach ($items as $t) {
        $cur = ($t['state'] === 'current' || $t['kind'] === 'exception');
        $h .= '<li class="tl-item tl-' . e($t['state']) . '"><div class="tl-indicator"><span class="tl-dot">'
            . ($t['state'] === 'done' ? icon('check') : '') . '</span><span class="tl-line"></span></div>'
            . '<div class="tl-text"><div class="tl-label"><span>' . e($t['label']) . '</span>'
            . ($cur ? badge('Current', $t['kind'] === 'exception' ? $t['colour'] : 'blue') : '') . '</div>'
            . '<div class="tl-date">' . e($t['date']) . '</div>'
            . ($t['note'] !== '' ? '<p class="tl-note">' . e($t['note']) . '</p>' : '')
            . '</div></li>';
    }
    return $h;
}

// Admin Audit Trail: the latest activity-log lines that mention this shipment
function auditRows(PDO $conn, string $reference): array
{
    $st = $conn->prepare(
        "SELECT performed_by_name, description, created_at FROM activity_log
         WHERE description LIKE ? ORDER BY created_at DESC, activity_id DESC LIMIT 4"
    );
    $st->execute(['%' . addcslashes($reference, '%_\\') . '%']);
    return $st->fetchAll();
}

function renderAuditBody(array $audit): string
{
    if (!$audit) return '<p class="soft-text">No activity recorded for this shipment yet.</p>';
    $h = '<ul class="audit-list">';
    foreach ($audit as $a) {
        $h .= '<li><time datetime="' . e($a['created_at']) . '">' . e(date('M d · g:i A', strtotime((string)$a['created_at']))) . '</time>'
            . '<span>' . e(($a['performed_by_name'] ? $a['performed_by_name'] . ': ' : '') . $a['description']) . '</span></li>';
    }
    return $h . '</ul>';
}

/* ---------- Update Status modal data (shipment_view.php first load, shipment_status.php after a save) ---------- */

// Everything the modal's JS needs, in the person's own role: the valid stages only.
function statusModalState(PDO $conn, array $ship, string $role): array
{
    $id  = (int)$ship['shipment_id'];
    $cur = stageByName($ship['current_status'])
        ?? ['stage_name' => (string)$ship['current_status'], 'stage_group' => '', 'sort_order' => 0, 'is_closed' => 0];
    $pos = shipmentPosition($conn, $id, $cur);

    $map = function (array $s): array {
        $ex = isExceptionStage($s);
        $helper = '';
        if ($s['stage_name'] === 'Trucking')                                 $helper = 'delivery';
        elseif (statusKey($s['stage_name']) === 'customs')                   $helper = 'customs';
        return ['name' => $s['stage_name'], 'group' => $s['stage_group'], 'exception' => $ex,
                'confirm' => (!$ex && !empty($s['is_closed'])), 'helper' => $helper];
    };

    [$label, $colour] = statusInfo($ship['current_status']);
    $st = $conn->prepare("SELECT MAX(updated_at) FROM shipment_status_updates WHERE shipment_id = ?");
    $st->execute([$id]);

    return [
        'shipmentId'       => $id,
        'reference'        => (string)$ship['reference_no'],
        'current'          => ['name' => $cur['stage_name'], 'group' => $cur['stage_group'], 'closed' => !empty($cur['is_closed']),
                               'badgeLabel' => $label, 'badgeClass' => $colour],
        'stages'           => array_map($map, allowedStages($cur, $pos, $role, false)),
        'canCorrect'       => ($role === 'admin'),
        'correctionStages' => $role === 'admin' ? array_map($map, allowedStages($cur, $pos, 'admin', true)) : [],
        'now'              => (string)$conn->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn(),
        'lastAt'           => $st->fetchColumn() ?: null,
        'actualArrival'    => $ship['actual_arrival_date'] ?? null,
        'links'            => ['customs'  => 'shipment_view.php?id=' . $id . '#customs',
                               'delivery' => 'shipment_view.php?id=' . $id . '#delivery'],
    ];
}

/* ---------- client side (clint_shipment.php) ---------- */

// Active client only. Returns [user id, client id (0 = not linked yet), display name]
function requireClient(PDO $conn): array
{
    $sess   = $_SESSION['user'] ?? [];
    $userId = (int)($sess['id'] ?? $sess['user_id'] ?? 0);
    if ($userId > 0 && ($sess['role'] ?? '') === 'client') {
        $st = $conn->prepare(
            "SELECT u.client_id, u.full_name, c.client_name
             FROM users u LEFT JOIN clients c ON c.client_id = u.client_id
             WHERE u.user_id = ? AND u.status = 'active' AND u.role = 'client' LIMIT 1"
        );
        $st->execute([$userId]);
        $me = $st->fetch();
        if ($me) return [$userId, (int)$me['client_id'], $me['client_name'] ?: $me['full_name']];
    }
    header('Location: login.php');
    exit;
}

function money($value): string
{
    $value = (float)$value;
    return 'PHP ' . number_format($value, floor($value) == $value ? 0 : 2);
}

function clientSidebarMenu(): array
{
    return [
        'dashboard'    => ['Dashboard',             'client_dashbroad.php',    'grid'],
        'shipments'    => ['My Shipments',          'clint_shipment.php',      'box'],
        'billing'      => ['My Billing & Payments', 'client_billing.php',      'billing'],
        'transactions' => ['My Transactions',       'client_transactions.php', 'swap'],
        'profile'      => ['My Profile',            'client_profile.php',      'user'],
    ];
}

// Same look as renderSidebar(), but with the client menu. "Add Shipment" does not exist here: clients cannot add shipments.
function renderClientSidebar(string $active, string $displayName): void
{
    $initials = initialsOf($displayName);
    ?>
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="brand-logo"><?= icon('box') ?></span>
            <span class="brand-name">Astra<span>Track</span></span>
        </div>

        <p class="menu-title">MENU</p>
        <nav class="menu">
            <?php foreach (clientSidebarMenu() as $key => [$label, $href, $ico]): ?>
                <a href="<?= e($href) ?>" class="menu-item <?= $key === $active ? 'active' : '' ?>">
                    <?= icon($ico) ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <span class="avatar"><?= e($initials) ?></span>
                <div>
                    <strong><?= e($displayName) ?></strong>
                    <small>Client</small>
                </div>
            </div>
            <a href="logout.php" class="menu-item"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>
    <?php
}
