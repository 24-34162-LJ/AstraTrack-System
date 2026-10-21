<?php
/**
 * log_activity.php — records who did what, for the dashboard's Recent Activity.
 * Use:  require_once 'log_activity.php';
 *       logActivity($pdo, 'Shipment Update', 'Status of AST-2026-0001 updated to Customs Processing');
 * Types used for the badge colours: 'Shipment Update', 'Billing Change', 'User Account' (anything else = blue)
 * Needs the activity_log table (activity_log.sql) and $_SESSION['user'] from your login.
 */
function logActivity(PDO $pdo, string $type, string $description): void
{
    $u    = $_SESSION['user'] ?? [];
    $id   = (int)($u['user_id'] ?? $u['id'] ?? 0) ?: null;
    $name = $u['full_name'] ?? $u['name'] ?? null;
    $role = $u['role'] ?? null;

    try {
        $pdo->prepare("INSERT INTO activity_log (activity_type, description, performed_by, performed_by_name, performed_by_role)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$type, mb_substr($description, 0, 255), $id, $name, $role]);
    } catch (PDOException $ex) {
        // Never break the real action just because logging failed
    }
}

// Small lookup used when logging user changes (returns name, code, role, status or null)
function userInfo(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT full_name, role, status, CONCAT('USR-', LPAD(user_id, 4, '0')) AS code FROM users WHERE user_id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

?>