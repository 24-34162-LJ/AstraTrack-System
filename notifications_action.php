<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';

function notificationActionReply(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') notificationActionReply(405, ['error' => 'Use POST.']);
$role = (string)($_SESSION['user']['role'] ?? '');
if ($role === 'client') [$userId] = requireClient($conn); else [$userId] = requireStaffOrAdmin($conn, true);
$csrf = (string)($_SESSION['csrf'] ?? '');
$sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, $sent)) notificationActionReply(403, ['error' => 'Your session expired. Reload and try again.']);
$action = (string)($_POST['action'] ?? 'read');
if ($action === 'all') {
    $st = $conn->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE user_id = ?');
    $st->execute([$userId]);
} elseif (in_array($action, ['read', 'unread'], true)) {
    $id = (int)($_POST['notification_id'] ?? 0);
    if ($id < 1) notificationActionReply(422, ['error' => 'Notification not found.']);
    $readAt = $action === 'read' ? date('Y-m-d H:i:s') : null;
    $st = $conn->prepare('UPDATE notifications SET read_at = ? WHERE notification_id = ? AND user_id = ?');
    $st->execute([$readAt, $id, $userId]);
    if (!$st->rowCount() && $action === 'read') {
        $check = $conn->prepare('SELECT 1 FROM notifications WHERE notification_id = ? AND user_id = ?');
        $check->execute([$id, $userId]);
        if (!$check->fetchColumn()) notificationActionReply(404, ['error' => 'Notification not found.']);
    }
} else notificationActionReply(422, ['error' => 'Invalid notification action.']);
$count = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
$count->execute([$userId]);
notificationActionReply(200, ['ok' => true, 'unread' => (int)$count->fetchColumn()]);
