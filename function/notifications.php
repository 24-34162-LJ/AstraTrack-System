<?php
/** Per-user in-app shipment notifications. Schema: config/migrations/2026_10_shipment_reference_features.sql. */

function createShipmentNotifications(PDO $pdo, array $shipment, string $eventType, string $title, string $message, int $actorUserId = 0): void
{
    $recipients = $pdo->prepare(
        "SELECT user_id FROM users
         WHERE status = 'active' AND (
             role = 'admin'
             OR (role = 'staff' AND user_id = ?)
             OR (role = 'client' AND client_id = ?)
         )"
    );
    $recipients->execute([(int)($shipment['assigned_staff_id'] ?? 0), (int)($shipment['client_id'] ?? 0)]);
    $insert = $pdo->prepare('INSERT INTO notifications (user_id, shipment_id, event_type, title, message) VALUES (?, ?, ?, ?, ?)');
    foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
        $recipientId = (int)$recipientId;
        if ($recipientId === $actorUserId) continue;
        $insert->execute([$recipientId, (int)$shipment['shipment_id'], mb_substr($eventType, 0, 40), mb_substr($title, 0, 120), mb_substr($message, 0, 255)]);
    }
}

function userNotificationSummary(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $st->execute([$userId]);
    $unread = (int)$st->fetchColumn();
    $st = $pdo->prepare(
        'SELECT n.notification_id, n.shipment_id, n.event_type, n.title, n.message, n.created_at, n.read_at, s.reference_no
         FROM notifications n LEFT JOIN shipments s ON s.shipment_id = n.shipment_id
         WHERE n.user_id = ? ORDER BY n.created_at DESC, n.notification_id DESC LIMIT 6'
    );
    $st->execute([$userId]);
    return ['unread' => $unread, 'items' => $st->fetchAll()];
}

function renderNotificationBell(PDO $pdo, int $userId, string $role): void
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $data = userNotificationSummary($pdo, $userId);
    ?>
    <div class="notification-menu" data-notification-menu data-csrf="<?= e($_SESSION['csrf']) ?>">
        <button class="icon-btn notification-toggle" type="button" aria-label="Notifications<?= $data['unread'] ? ' (' . (int)$data['unread'] . ' unread)' : '' ?>" aria-expanded="false" aria-controls="notificationPreview" data-notification-toggle><?= icon('bell') ?><?php if ($data['unread']): ?><span class="notification-count"><?= $data['unread'] > 99 ? '99+' : (int)$data['unread'] ?></span><?php endif; ?></button>
        <section class="notification-preview" id="notificationPreview" data-notification-preview hidden>
            <header><strong>Notifications</strong><a href="notifications.php">View all</a></header>
            <?php if ($data['items']): ?><ul><?php foreach ($data['items'] as $notice): $noticeHref = !empty($notice['shipment_id']) ? ($role === 'client' ? 'clint_shipment.php?id=' . (int)$notice['shipment_id'] : 'shipment_view.php?id=' . (int)$notice['shipment_id']) : 'notifications.php'; ?><li class="<?= empty($notice['read_at']) ? 'is-unread' : '' ?>"><a href="<?= e($noticeHref) ?>" data-notification-read="<?= (int)$notice['notification_id'] ?>"><strong><?= e($notice['title']) ?></strong><span><?= e($notice['message']) ?></span><small><?= e(fmtDateTime($notice['created_at'])) ?></small></a></li><?php endforeach; ?></ul><?php else: ?><p class="notification-preview-empty">You are all caught up.</p><?php endif; ?>
            <footer><a href="notifications.php">Open notification center</a></footer>
        </section>
    </div>
    <?php
}
