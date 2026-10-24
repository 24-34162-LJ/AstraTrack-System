<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$role = (string)($_SESSION['user']['role'] ?? '');
if ($role === 'client') [$userId, $clientId, $fullName] = requireClient($conn);
else [$userId, $fullName, $role] = requireStaffOrAdmin($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'mark_all') {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Your session expired. Reload and try again.');
    }
    $conn->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE user_id = ?')->execute([$userId]);
    header('Location: notifications.php?filter=all&updated=1');
    exit;
}

$filter = (($_GET['filter'] ?? 'all') === 'unread') ? 'unread' : 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$where = $filter === 'unread' ? ' AND n.read_at IS NULL' : '';
$count = $conn->prepare('SELECT COUNT(*) FROM notifications n WHERE n.user_id = ?' . $where);
$count->execute([$userId]);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$itemsQuery = $conn->prepare(
    'SELECT n.notification_id, n.shipment_id, n.event_type, n.title, n.message, n.created_at, n.read_at, s.reference_no
     FROM notifications n LEFT JOIN shipments s ON s.shipment_id = n.shipment_id
     WHERE n.user_id = ?' . $where . ' ORDER BY n.created_at DESC, n.notification_id DESC LIMIT ? OFFSET ?'
);
$itemsQuery->bindValue(1, $userId, PDO::PARAM_INT);
$itemsQuery->bindValue(2, $perPage, PDO::PARAM_INT);
$itemsQuery->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
$itemsQuery->execute();
$items = $itemsQuery->fetchAll();
$unread = userNotificationSummary($conn, $userId)['unread'];
$home = $role === 'client' ? 'client_dashbroad.php' : homePage($role);
$crumbs = [['Dashboard', $home], ['Notifications', null]];
$bellDot = $unread > 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf" content="<?= e($_SESSION['csrf']) ?>">
    <title>Notifications | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff.css"><link rel="stylesheet" href="assets/css/shipments.css"><link rel="stylesheet" href="assets/css/notifications.css?v=20261024-1">
</head>
<body class="fig"><div class="layout">
    <?php if ($role === 'client') renderClientSidebar('shipments', $fullName); else renderSidebar($role, 'notifications', $fullName); ?>
    <div class="fig-main">
        <?php renderTopnav($crumbs, $fullName, $bellDot); ?>
        <main class="page notification-page">
            <header class="page-head"><div><h1>Notifications</h1><p class="sub">Shipment updates, document activity, and important dates.</p></div>
                <?php if ($unread): ?><form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="mark_all"><button class="btn btn-secondary" type="submit">Mark all as read</button></form><?php endif; ?>
            </header>
            <section class="notification-center-card fcard">
                <nav class="notification-tabs" aria-label="Notification filter"><a class="<?= $filter === 'all' ? 'active' : '' ?>" href="notifications.php?filter=all">All</a><a class="<?= $filter === 'unread' ? 'active' : '' ?>" href="notifications.php?filter=unread">Unread <span><?= $unread ?></span></a></nav>
                <?php if ($items): ?><ul class="notification-list"><?php foreach ($items as $item): $target = !empty($item['shipment_id']) ? (($role === 'client') ? 'clint_shipment.php?id=' . (int)$item['shipment_id'] : 'shipment_view.php?id=' . (int)$item['shipment_id']) : $home; ?><li class="notification-item <?= empty($item['read_at']) ? 'is-unread' : '' ?>"><span class="notification-type-icon" aria-hidden="true"><?= $item['event_type'] === 'document' ? '▤' : ($item['event_type'] === 'date' ? '◷' : '↗') ?></span><div class="notification-copy"><div class="notification-item-head"><strong><?= e($item['title']) ?></strong><time><?= e(fmtDateTime($item['created_at'])) ?></time></div><p><?= e($item['message']) ?></p><?php if (!empty($item['reference_no'])): ?><a href="<?= e($target) ?>" data-notification-read="<?= (int)$item['notification_id'] ?>">View shipment · <?= e($item['reference_no']) ?></a><?php endif; ?></div><?php if (empty($item['read_at'])): ?><span class="notification-unread-dot" aria-label="Unread"></span><?php endif; ?></li><?php endforeach; ?></ul>
                <?php else: ?><div class="notification-empty"><span aria-hidden="true">◉</span><strong><?= $filter === 'unread' ? 'You’re all caught up.' : 'No notifications yet.' ?></strong><p>New shipment updates will appear here.</p><?php if ($filter === 'unread'): ?><a href="notifications.php?filter=all">View all notifications</a><?php endif; ?></div><?php endif; ?>
                <?php if ($pages > 1): ?><nav class="notification-pagination" aria-label="Notification pages"><?php if ($page > 1): ?><a href="notifications.php?filter=<?= e($filter) ?>&amp;page=<?= $page - 1 ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="notifications.php?filter=<?= e($filter) ?>&amp;page=<?= $page + 1 ?>">Next</a><?php endif; ?></nav><?php endif; ?>
            </section>
        </main>
    </div>
</div>
</body></html>
