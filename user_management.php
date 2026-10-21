<?php
/**
 * user_management.php — AstraTrack User Management (Admin only)
 * Files: user_management.php, assets/css/user_management.css, assets/js/user_management.js
 * Also needs: db.php, assets/css/admin.css (shared layout)
 * Tables: users (+ clients for client accounts) from astratrack_mysql.sql
 */
session_start();
require_once 'config/database.php';           // gives you $pdo
require_once 'function/log_activity.php'; // logActivity() -> Recent Activity on the dashboard

/* ---------- 1) ADMIN-ONLY ACCESS ----------
 * Set to true when login.php saves:
 *   $_SESSION['user'] = ['user_id' => ..., 'full_name' => ..., 'role' => 'admin'];
 */
$REQUIRE_LOGIN = false;
if ($REQUIRE_LOGIN && (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin')) {
    header('Location: login.php');
    exit;
}
$currentUser = $_SESSION['user'] ?? [];
// Accept the common key names your login page may have used, so a missing key never breaks the page.
// Best practice: login.php should save ['user_id' => ..., 'full_name' => ..., 'role' => ...]
$currentUser = [
    'user_id'   => (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0),
    'full_name' => $currentUser['full_name'] ?? $currentUser['name'] ?? 'Administrator',
    'role'      => $currentUser['role'] ?? 'admin',
];
$selfId = $currentUser['user_id'];   // 0 = unknown; then the "cannot change your own account" protection is skipped

/* ---------- 2) HELPERS ---------- */
function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }

function back($type, $message, $secret = null) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'secret' => $secret];
    header('Location: user_management.php');
    exit;
}
// Validation failed: reopen the Add/Edit popup with the typed values and error messages
function backWithForm($mode, $id, $origName, $values, $errors) {
    $_SESSION['form_state'] = compact('mode', 'id', 'origName', 'values', 'errors');
    header('Location: user_management.php');
    exit;
}
// True if this user is the only active admin (so they must not be removed/demoted/deactivated)
function isLastActiveAdmin(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT role, status FROM users WHERE user_id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u || $u['role'] !== 'admin' || $u['status'] !== 'active') return false;
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn() <= 1;
}

/* ---------- 3) FORM ACTIONS: add / edit / toggle / delete ---------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) back('error', 'Invalid request. Please try again.');

    $action   = $_POST['action'] ?? '';
    $userId   = (int)($_POST['user_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $role     = $_POST['role'] ?? '';
    $status   = $_POST['status'] ?? '';
    $password = $_POST['password'] ?? '';   // never saved in plain text, never sent back to the form
    $clientId = (int)($_POST['client_id'] ?? 0) ?: null;

    try {
        /* Activate / Deactivate (deactivating keeps all shipment, billing and transaction history) */
        if ($action === 'toggle') {
            if ($userId === $selfId) back('error', 'You cannot change the status of your own account.');
            if (isLastActiveAdmin($conn, $userId)) back('error', 'At least one active Admin account is required.');
            $t = userInfo($conn, $userId);
            $conn->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE user_id = ?")->execute([$userId]);
            if ($t) logActivity($conn, 'User Account', ($t['status'] === 'active' ? 'Account deactivated' : 'Account activated') . " for {$t['full_name']} ({$t['code']})");
            back('success', 'Account status updated.');
        }

        /* Delete (permanent) — the user must type DELETE in the confirmation popup */
        if ($action === 'delete') {
            if (($_POST['confirm_text'] ?? '') !== 'DELETE') back('error', 'Type DELETE to confirm account deletion.');
            if ($userId === $selfId) back('error', 'You cannot delete your own account.');
            if (isLastActiveAdmin($conn, $userId)) back('error', 'At least one active Admin account is required.');
            // FKs use ON DELETE SET NULL, so shipments, billings and history stay; only the user link is cleared.
            $t = userInfo($conn, $userId);
            $conn->prepare("DELETE FROM users WHERE user_id = ?")->execute([$userId]);
            if ($t) logActivity($conn, 'User Account', "Account deleted: {$t['full_name']} ({$t['code']}, " . ucfirst($t['role']) . ")");
            back('success', 'Account deleted.');
        }

        if (!in_array($action, ['add', 'edit'], true)) back('error', 'Unknown action.');

        /* Validation (same messages as the JavaScript checks) */
        $errors = [];
        if (mb_strlen($fullName) < 2) $errors['full_name'] = 'Enter a full name with at least 2 characters.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address, e.g. riley@logistics.example.';
        if (!in_array($role, ['admin', 'staff', 'client'], true)) $errors['role'] = 'Role is required. Choose Admin, Staff, or Client.';
        if (!in_array($status, ['active', 'inactive'], true)) $errors['status'] = 'Choose Active or Inactive.';
        // Password: required when adding; optional when editing (blank = keep the current one)
        if (($action === 'add' || $password !== '') && (strlen($password) < 8 || strlen($password) > 72))
            $errors['password'] = 'Password must be 8 to 72 characters.';
        if ($role === 'client' && !$clientId) $errors['client_id'] = 'Choose the client company for this account.';
        if ($role !== 'client') $clientId = null;   // users.client_id is for client users only

        $values = ['full_name' => $fullName, 'email' => $email, 'role' => $role, 'status' => $status, 'client_id' => (int)$clientId];
        $origName = $fullName;
        if ($action === 'edit') {
            $st = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
            $st->execute([$userId]);
            $origName = $st->fetchColumn() ?: $fullName;
            if (!$errors && ($role !== 'admin' || $status !== 'active')) {
                if ($userId === $selfId) $errors['role'] = 'You cannot remove your own Admin access.';
                elseif (isLastActiveAdmin($conn, $userId)) $errors['role'] = 'At least one active Admin account is required.';
            }
        }
        if ($errors) backWithForm($action, $userId, $origName, $values, $errors);

        if ($action === 'add') {
            $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, client_id, status) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $role, $clientId, $status]);
            logActivity($conn, 'User Account', "New $role account created for $fullName (USR-" . str_pad($conn->lastInsertId(), 4, '0', STR_PAD_LEFT) . ")");
            back('success', "Account created for $fullName.");
        }

        $before = userInfo($conn, $userId);
        $sql    = "UPDATE users SET full_name = ?, email = ?, role = ?, client_id = ?, status = ?";
        $params = [$fullName, $email, $role, $clientId, $status];
        if ($password !== '') {                       // only change the password when a new one was typed
            $sql .= ", password_hash = ?";
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $conn->prepare($sql . " WHERE user_id = ?")->execute(array_merge($params, [$userId]));
        $note = '';
        if ($before && $before['role'] !== $role)     $note .= ", role {$before['role']} to $role";
        if ($before && $before['status'] !== $status) $note .= ", status {$before['status']} to $status";
        if ($password !== '')                         $note .= ", password changed";
        logActivity($conn, 'User Account', "Account details updated for $fullName (USR-" . str_pad($userId, 4, '0', STR_PAD_LEFT) . ")$note");
        back('success', 'User account updated.');

    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000' && in_array($action, ['add', 'edit'], true)) {   // users.email is UNIQUE
            backWithForm($action, $userId, $fullName, $values ?? [], ['email' => 'That email address is already in use.']);
        }
        back('error', 'Database error. Please try again.');
    }
}

/* ---------- 4) LOAD DATA ---------- */
$flash     = $_SESSION['flash'] ?? null;      unset($_SESSION['flash']);
$formState = $_SESSION['form_state'] ?? null; unset($_SESSION['form_state']);

$users = $conn->query(
    "SELECT u.user_id, u.full_name, u.email, u.role, u.client_id, u.status,
            CONCAT('USR-', LPAD(u.user_id, 4, '0')) AS code,
            DATE_FORMAT(u.created_at, '%b %d, %Y')  AS created,
            c.client_name
     FROM users u LEFT JOIN clients c ON c.client_id = u.client_id
     ORDER BY u.user_id"
)->fetchAll();
$clientList = $conn->query("SELECT client_id, client_name FROM clients ORDER BY client_name")->fetchAll();

$total    = count($users);
$active   = count(array_filter($users, fn($u) => $u['status'] === 'active'));
$inactive = $total - $active;
$admins   = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
$staff    = count(array_filter($users, fn($u) => $u['role'] === 'staff'));
$clients  = count(array_filter($users, fn($u) => $u['role'] === 'client'));

/* ---------- 5) SIDEBAR ---------- */
$activePage = 'users';
$menu = [
    'dashboard'    => ['Dashboard',              'admin.php',           'grid'],
    'users'        => ['User Management',        'user_management.php', 'users'],
    'shipments'    => ['Shipment Management',    'shipments.php',       'box'],
    'clients'      => ['Client Management',      'clients.php',         'user'],
    'billing'      => ['Billing & Payments',     'billing.php',         'receipt'],
    'transactions' => ['Transaction Management', 'transactions.php',    'swap'],
    'reports'      => ['Reports',                'reports.php',         'chart'],
];

function icon($name) {
    $p = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'     => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2 .7 3.5 2.6 3.5 5.5"/>',
        'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c0-4 3.4-6.5 7.5-6.5s7.5 2.5 7.5 6.5"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
        'swap'    => '<path d="M4 8h16m-4-4l4 4-4 4M20 16H4m4-4l-4 4 4 4"/>',
        'chart'   => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'shield'  => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'logout'  => '<path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9"/>',
        'search'  => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        'searchx' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M9 9l4 4M13 9l-4 4"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'close'   => '<path d="M6 6l12 12M18 6L6 18"/>',
        'trash'   => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'alert'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.5"/>',
        'warning' => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18v.5"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
$initials = strtoupper(substr($currentUser['full_name'], 0, 1) . substr(strrchr(' ' . $currentUser['full_name'], ' '), 1, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/user_management.css">
</head>
<body>
<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="brand-logo"><?= icon('box') ?></span>
            <span class="brand-name">Astra<span>Track</span></span>
        </div>
        <nav class="menu">
            <?php foreach ($menu as $key => [$label, $href, $ico]): ?>
                <a href="<?= e($href) ?>" class="menu-item <?= $key === $activePage ? 'active' : '' ?>"><?= icon($ico) ?><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <span class="avatar"><?= e($initials) ?></span>
                <div><strong><?= e($currentUser['full_name']) ?></strong><small><?= e(ucfirst($currentUser['role'])) ?></small></div>
            </div>
            <a href="logout.php" class="menu-item logout"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
                <h1>User Management</h1>
                <p>Manage user accounts, roles, and access permissions</p>
            </div>
            <div class="profile">
                <span class="avatar"><?= e($initials) ?></span>
                <div><strong><?= e($currentUser['full_name']) ?></strong><small><?= e(ucfirst($currentUser['role'])) ?></small></div>
                <?= icon('chevron') ?>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>" role="status">
                <?= e($flash['message']) ?>
                <?php if (!empty($flash['secret'])): ?>
                    Temporary password: <code><?= e($flash['secret']) ?></code>
                    <small>Copy it now. It will not be shown again. Ask the user to change it after signing in.</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Summary cards (show "No accounts yet" when empty) -->
        <section class="stats">
            <article class="card stat">
                <div class="stat-head"><span>Total Users</span><?= icon('users') ?></div>
                <strong><?= $total ?></strong>
                <small><?= $total ? "$active active &middot; $inactive inactive" : 'No accounts yet' ?></small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Administrators</span><?= icon('shield') ?></div>
                <strong><?= $admins ?></strong>
                <small><?= $total ? 'Roles and access management' : 'No accounts yet' ?></small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Staff Accounts</span><?= icon('users') ?></div>
                <strong><?= $staff ?></strong>
                <small><?= $total ? 'Shipment and billing operations' : 'No accounts yet' ?></small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Client Accounts</span><?= icon('user') ?></div>
                <strong><?= $clients ?></strong>
                <small><?= $total ? 'Client shipment monitoring' : 'No accounts yet' ?></small>
            </article>
        </section>

        <div class="notice">
            <?= icon('shield') ?>
            <p><b>Admin-only area.</b> Only Admin users may manage accounts, assign roles, and change account status.</p>
        </div>

        <section class="card">
            <div class="filters">
                <label class="f-search">
                    <span>Search users</span>
                    <div class="input-icon"><?= icon('search') ?><input type="text" id="searchInput" placeholder="Search by name, email, or user ID."></div>
                </label>
                <label class="f-select">
                    <span>Role</span>
                    <select id="roleFilter">
                        <option value="">All Roles</option>
                        <option value="admin">Admin</option><option value="staff">Staff</option><option value="client">Client</option>
                    </select>
                </label>
                <label class="f-select f-wide">
                    <span>Account Status</span>
                    <select id="statusFilter">
                        <option value="">All</option>
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                </label>
                <button type="button" class="btn-add" id="addUserBtn">+ Add User</button>
            </div>

            <div class="table-wrap">
                <table class="users-table" id="usersTable">
                    <thead>
                        <tr><th>User ID</th><th>Full Name</th><th>Email Address</th><th>Role</th><th>Account Status</th><th>Date Created</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): $self = (int)$u['user_id'] === $selfId; ?>
                            <tr data-id="<?= (int)$u['user_id'] ?>" data-code="<?= e($u['code']) ?>" data-name="<?= e($u['full_name']) ?>"
                                data-email="<?= e($u['email']) ?>" data-role="<?= e($u['role']) ?>" data-status="<?= e($u['status']) ?>"
                                data-created="<?= e($u['created']) ?>" data-client-id="<?= (int)$u['client_id'] ?>"
                                data-client-name="<?= e($u['client_name']) ?>" data-self="<?= $self ? 1 : 0 ?>">
                                <td class="uid"><?= e($u['code']) ?></td>
                                <td><?= e($u['full_name']) ?><?php if ($u['client_name']): ?><small class="sub"><?= e($u['client_name']) ?></small><?php endif; ?></td>
                                <td class="muted-cell"><?= e($u['email']) ?></td>
                                <td><span class="pill role-<?= e($u['role']) ?>"><?= e(ucfirst($u['role'])) ?></span></td>
                                <td><span class="pill status-<?= e($u['status']) ?>"><?= e(ucfirst($u['status'])) ?></span></td>
                                <td class="muted-cell"><?= e($u['created']) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <button type="button" class="act act-view" data-do="view">View</button>
                                        <button type="button" class="act" data-do="edit">Edit</button>
                                        <button type="button" class="act act-toggle" data-do="status" <?= $self ? 'disabled title="You cannot change your own status"' : '' ?>>
                                            <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                        <button type="button" class="act act-del" data-do="delete" aria-label="Delete account"
                                                <?= $self ? 'disabled title="You cannot delete your own account"' : 'title="Delete account"' ?>><?= icon('trash') ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Empty state: no accounts in the database -->
                <?php if ($total === 0): ?>
                    <div class="empty-state">
                        <span class="empty-ico"><?= icon('users') ?></span>
                        <h3>No user accounts yet</h3>
                        <p>Add your first user to start assigning roles and managing access to AstraTrack.</p>
                        <button type="button" class="btn-add js-add">+ Add User</button>
                        <small>Choose Admin, Staff, or Client for each new account.</small>
                    </div>
                <?php endif; ?>

                <!-- Empty state: filters match nothing (shown by JavaScript) -->
                <div class="empty-state" id="emptyFilter" hidden>
                    <span class="empty-ico"><?= icon('searchx') ?></span>
                    <h3>No users match your filters</h3>
                    <p>Try a different name, email, or user ID, or clear your filters to see all <?= $total ?> users.</p>
                    <button type="button" class="act" id="clearFilters">Clear filters</button>
                    <small>Roles: All Roles, Admin, Staff, Client &middot; Status: All, Active, Inactive</small>
                </div>
            </div>

            <div class="table-foot">
                <span class="muted" id="countText"><?= $total ? "Showing 1&ndash;$total of $total users" : '0 users' ?></span>
                <div class="pager">
                    <button type="button" disabled aria-label="Previous">&lsaquo;</button>
                    <button type="button" class="current">1</button>
                    <button type="button" disabled aria-label="Next">&rsaquo;</button>
                </div>
            </div>
        </section>
    </main>
</div>

<!-- ================= ADD / EDIT POPUP ================= -->
<dialog id="formDialog" class="dialog">
    <form method="post" id="userForm" novalidate>
        <div class="dlg-head">
            <div><h2 id="formTitle">Add User</h2><p class="dlg-sub" id="formSub"></p></div>
            <button type="button" class="x" data-close aria-label="Close"><?= icon('close') ?></button>
        </div>
        <p class="req-note" id="reqNote"></p>
        <div class="form-alert" id="formAlert" hidden><?= icon('alert') ?><span id="formAlertText"></span></div>

        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="user_id" id="fId">

        <div class="field" data-field="full_name">
            <label for="fName">Full Name *</label>
            <input type="text" name="full_name" id="fName" placeholder="Enter full name" maxlength="100">
            <p class="hint" data-hint="Use the name displayed on the user account."></p>
        </div>
        <div class="field" data-field="email">
            <label for="fEmail">Email Address *</label>
            <input type="email" name="email" id="fEmail" placeholder="name@company.example" maxlength="120">
            <p class="hint" data-hint="Use a unique email address for this account."></p>
        </div>
        <div class="field" data-field="password">
            <label for="fPassword" id="pwLabel">Password *</label>
            <div class="pw-wrap">
                <input type="password" name="password" id="fPassword" placeholder="Enter password" maxlength="72" autocomplete="new-password">
                <button type="button" class="pw-toggle" id="pwToggle">Show</button>
            </div>
            <p class="hint" data-hint="Use at least 8 characters."></p>
        </div>
        <div class="two">
            <div class="field" data-field="role">
                <label for="fRole">Role *</label>
                <select name="role" id="fRole">
                    <option value="">Select role</option>
                    <option value="admin">Admin</option><option value="staff">Staff</option><option value="client">Client</option>
                </select>
                <p class="hint" data-hint="Available roles: Admin, Staff, Client."></p>
            </div>
            <div class="field" data-field="status">
                <label for="fStatus">Account Status *</label>
                <select name="status" id="fStatus">
                    <option value="active">Active</option><option value="inactive">Inactive</option>
                </select>
                <p class="hint" data-hint="Options: Active, Inactive."></p>
            </div>
        </div>
        <!-- Client accounts only: saved to users.client_id -->
        <div class="field" data-field="client_id" id="clientRow" hidden>
            <label for="fClient">Client Company *</label>
            <select name="client_id" id="fClient">
                <option value="">Select client company</option>
                <?php foreach ($clientList as $c): ?>
                    <option value="<?= (int)$c['client_id'] ?>"><?= e($c['client_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint" data-hint="The company this client account belongs to."></p>
        </div>

        <div class="note-box">Only Admin users may assign roles and manage status. Deactivating an account disables access but retains shipment, billing, and transaction history.</div>

        <div class="dlg-foot">
            <button type="button" class="act" data-close>Cancel</button>
            <button type="submit" class="btn-add">Save User</button>
        </div>
    </form>
</dialog>

<!-- ================= VIEW POPUP ================= -->
<dialog id="viewDialog" class="dialog dialog-md">
    <div class="dlg-head">
        <div><h2>User Details</h2><p class="dlg-sub">Review identity, role, and current account access.</p></div>
        <button type="button" class="x" data-close aria-label="Close"><?= icon('close') ?></button>
    </div>
    <div class="who">
        <span class="who-avatar" id="vAvatar"></span>
        <div><h3 id="vName"></h3><p id="vEmail"></p><div class="pills" id="vPills"></div></div>
    </div>
    <dl class="kv">
        <div><dt>User ID</dt><dd id="vCode"></dd></div>
        <div><dt>Date Created</dt><dd id="vCreated"></dd></div>
        <div><dt>Role</dt><dd id="vRole"></dd></div>
        <div><dt>Account Status</dt><dd id="vStatus"></dd></div>
    </dl>
    <div class="acct-box">
        <h4>Account management</h4>
        <p>Admins can edit details, assign roles, or deactivate access. Deactivation retains all shipment, billing, and transaction history.</p>
        <div class="acct-actions">
            <button type="button" class="btn-add" id="vEdit">Edit User</button>
            <button type="button" class="act act-lg" id="vToggle">Deactivate</button>
            <button type="button" class="act act-lg act-danger" id="vDelete">Delete</button>
        </div>
    </div>
    <div class="dlg-foot"><button type="button" class="act" data-close>Close</button></div>
</dialog>

<!-- ================= DEACTIVATE / ACTIVATE / DELETE POPUP ================= -->
<dialog id="confirmDialog" class="dialog dialog-sm">
    <form method="post" id="confirmForm">
        <div class="dlg-head">
            <div><h2 id="cTitle"></h2><p class="dlg-sub" id="cSub"></p></div>
            <button type="button" class="x" data-close aria-label="Close"><?= icon('close') ?></button>
        </div>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" id="cAction">
        <input type="hidden" name="user_id" id="cId">

        <div class="user-card">
            <strong id="cName"></strong>
            <p id="cMeta"></p>
            <div class="pills" id="cPills"></div>
        </div>
        <div class="alert-box" id="cAlert"><?= icon('warning') ?><span id="cAlertText"></span></div>
        <p class="c-body" id="cBody"></p>

        <div class="field" id="cConfirmRow" hidden>
            <label for="cConfirm">Type <b>DELETE</b> to confirm</label>
            <input type="text" name="confirm_text" id="cConfirm" autocomplete="off" placeholder="DELETE">
        </div>

        <div class="dlg-foot">
            <button type="button" class="act" data-close>Cancel</button>
            <button type="submit" class="btn-add btn-danger" id="cSubmit"></button>
        </div>
    </form>
</dialog>

<script>
    window.UM = {
        formState: <?= json_encode($formState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        total: <?= (int)$total ?>
    };
</script>
<script src="assets/js/user_management.js"></script>
</body>
</html>
