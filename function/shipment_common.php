<?php
/**
 * shipment_common.php — helpers shared by shipments.php (list) and shipment_view.php (details)
 * Status wording is grouped into the 4 stages of the Figma design (same grouping as staff.php / admin.php).
 */

function e($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }

function fmtDate($value) { return $value ? date('M d, Y', strtotime((string)$value)) : '—'; }

function fmtDateTime($value) { return $value ? date('M d, Y · g:i A', strtotime((string)$value)) : '—'; }

// Pickup schedule: show the time only when one was entered (a plain date has 00:00)
function fmtSchedule($value)
{
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    return date('H:i', $ts) === '00:00' ? date('M d, Y', $ts) : date('M d, Y, g:i A', $ts);
}

function columnExists(PDO $conn, string $table, string $column): bool
{
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

/* ---------- statuses ---------- */

// key => [label, badge colour, lower-case raw values found in shipments.current_status]
function statusGroups(): array
{
    return [
        'pending'   => ['Pending Documents', 'amber', ['documents received', 'pending documents']],
        'customs'   => ['Customs Processing', 'blue',  ['customs processing', 'declaration filed', 'assessment', 'duties paid', 'examination', 'released/cleared']],
        'transit'   => ['In Transit', 'teal',  ['in transit', 'trucking']],
        'delivered' => ['Delivered', 'green', ['delivered']],
    ];
}

function statusKey($status): ?string
{
    $s = strtolower(trim((string)$status));
    foreach (statusGroups() as $key => $g) {
        if (in_array($s, $g[2], true)) return $key;
    }
    return null;
}

// [label, colour] for the badge
function statusInfo($status): array
{
    $s = strtolower(trim((string)$status));
    if ($s === 'trucking') return ['Trucking', 'teal'];
    $key = statusKey($status);
    if ($key !== null) {
        $g = statusGroups()[$key];
        return [$g[0], $g[1]];
    }
    return [trim((string)$status) !== '' ? (string)$status : 'Unknown', 'blue'];
}

function badge(string $label, string $class): string
{
    return '<span class="badge badge-' . e($class) . '">' . e($label) . '</span>';
}

/* ---------- access, menu, layout ---------- */

// Active staff or admin only. Returns [user id, full name, role]
function requireStaffOrAdmin(PDO $conn): array
{
    $sess   = $_SESSION['user'] ?? [];
    $userId = (int)($sess['id'] ?? $sess['user_id'] ?? 0);
    if ($userId > 0) {
        $st = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ? AND status = 'active' AND role IN ('staff','admin')");
        $st->execute([$userId]);
        $me = $st->fetch();
        if ($me) return [$userId, $me['full_name'], $me['role']];
    }
    header('Location: login.php');
    exit;
}

function homePage(string $role): string { return $role === 'admin' ? 'admin.php' : 'staff.php'; }

function sidebarMenu(string $role): array
{
    $menu = [
        'dashboard'    => ['Dashboard',              homePage($role),       'grid'],
        'shipments'    => ['Shipment Management',    'shipments.php',       'box'],
        'clients'      => ['Client Management',      'clients.php',         'users'],
        'billing'      => ['Billing & Payments',     'billing.php',         'billing'],
        'transactions' => ['Transaction Management', 'transactions.php',    'swap'],
        'reports'      => ['Reports',                'reports.php',         'chart'],
    ];
    if ($role === 'admin') {
        $menu = array_slice($menu, 0, 1, true)
              + ['users' => ['User Management', 'user_management.php', 'shield']]
              + array_slice($menu, 1, null, true);
    }
    return $menu;
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
        'download' => '<path d="M12 4v11M7 11l5 5 5-5M5 20h14"/>',
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
                <a href="<?= e($href) ?>" class="menu-item <?= $key === $active ? 'active' : '' ?>">
                    <?= icon($ico) ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

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
    $initials = initialsOf($fullName);
    $last = count($crumbs) - 1;
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
            <button class="icon-btn" type="button" aria-label="Notifications"><?= icon('bell') ?><?php if ($bellDot): ?><i class="dot"></i><?php endif; ?></button>
            <div class="profile">
                <span class="avatar"><?= e($initials) ?></span>
                <strong><?= e($fullName) ?></strong>
                <?= icon('chevron') ?>
            </div>
        </div>
    </header>
    <?php
}
