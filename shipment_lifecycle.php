<?php
/** Admin/staff shipment hold, cancellation, archive and reopen actions. */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';
require_once __DIR__ . '/function/log_activity.php';

function lifecycleJson(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') lifecycleJson(405, ['error' => 'Use POST.']);
[$userId, $fullName, $role] = requireStaffOrAdmin($conn, true);
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) lifecycleJson(403, ['error' => 'Your session expired. Reload and try again.']);
$id = (int)($_POST['shipment_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$allowed = ['hold', 'cancel', 'archive', 'reopen'];
if (!$id || !in_array($action, $allowed, true)) lifecycleJson(422, ['error' => 'Choose a valid shipment action.']);
if (mb_strlen($remarks) > 255 || mb_strlen($reason) > 255) lifecycleJson(422, ['error' => 'Reason and remarks must be 255 characters or fewer.']);
if (in_array($action, ['cancel', 'hold'], true) && $reason === '') lifecycleJson(422, ['error' => 'Enter a reason for this action.']);
if ($role !== 'admin' && in_array($action, ['cancel', 'archive', 'reopen'], true)) lifecycleJson(403, ['error' => 'Only an administrator can perform this action.']);

try {
    $conn->beginTransaction();
    $query = $conn->prepare('SELECT shipment_id, reference_no, client_id, assigned_staff_id, current_status, archived_at FROM shipments WHERE shipment_id = ? FOR UPDATE');
    $query->execute([$id]);
    $ship = $query->fetch();
    if (!$ship) { $conn->rollBack(); lifecycleJson(404, ['error' => 'Shipment not found.']); }
    if ($role === 'staff' && !empty($ship['assigned_staff_id']) && (int)$ship['assigned_staff_id'] !== $userId) {
        $conn->rollBack(); lifecycleJson(403, ['error' => 'Only the assigned staff member or an administrator can update this shipment.']);
    }
    $current = stageByName($ship['current_status']);
    if (!$current) { $conn->rollBack(); lifecycleJson(422, ['error' => 'The current shipment status is not recognized.']); }
    if ($action === 'archive') {
        if ($role !== 'admin') { $conn->rollBack(); lifecycleJson(403, ['error' => 'Only an administrator can archive shipments.']); }
        if (!empty($ship['archived_at'])) { $conn->rollBack(); lifecycleJson(409, ['error' => 'This shipment is already archived.']); }
        if (empty($current['is_closed'])) { $conn->rollBack(); lifecycleJson(422, ['error' => 'Only a delivered or cancelled shipment can be archived.']); }
        $conn->prepare('UPDATE shipments SET archived_at = NOW(), archived_by = ?, archive_reason = ? WHERE shipment_id = ?')->execute([$userId, $remarks !== '' ? $remarks : null, $id]);
        logActivity($conn, 'Shipment Update', $ship['reference_no'] . ' archived' . ($remarks !== '' ? ': ' . $remarks : ''));
        createShipmentNotifications($conn, $ship, 'archive', 'Shipment archived', $ship['reference_no'] . ' was archived.', $userId);
        $conn->commit(); lifecycleJson(200, ['ok' => true, 'message' => 'Shipment archived.']);
    }

    if ($action === 'reopen') {
        if ($role !== 'admin') { $conn->rollBack(); lifecycleJson(403, ['error' => 'Only an administrator can reopen shipments.']); }
        $wasArchived = !empty($ship['archived_at']);
        if (!$wasArchived && !in_array($ship['current_status'], ['On Hold', 'Cancelled', 'Delivered'], true)) {
            $conn->rollBack(); lifecycleJson(409, ['error' => 'This shipment is already active.']);
        }
        $previousQuery = $conn->prepare("SELECT stage FROM shipment_status_updates WHERE shipment_id = ? AND stage NOT IN ('On Hold','Cancelled','Delivered') ORDER BY updated_at DESC, update_id DESC LIMIT 1");
        $previousQuery->execute([$id]);
        $resumeStage = (string)($previousQuery->fetchColumn() ?: 'Documents received');
        $resume = stageByName($resumeStage);
        if (!$resume || !empty($resume['is_closed'])) $resume = stageByName('Documents received');
        $oldStatus = (string)$ship['current_status'];
        $conn->prepare('UPDATE shipments SET archived_at = NULL, archived_by = NULL, archive_reason = NULL, current_status = ? WHERE shipment_id = ?')->execute([$resume['stage_name'], $id]);
        if ($oldStatus !== $resume['stage_name']) {
            $at = (string)$conn->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn();
            $conn->prepare("INSERT INTO shipment_status_updates (shipment_id, stage, status, remarks, updated_by, updated_at) VALUES (?, ?, 'ongoing', ?, ?, ?)")->execute([$id, $resume['stage_name'], $remarks !== '' ? 'Reopened: ' . $remarks : 'Reopened by administrator.', $userId, $at]);
            $transactionQuery = $conn->prepare('SELECT transaction_id, status FROM transactions WHERE shipment_id = ? ORDER BY transaction_id DESC LIMIT 1 FOR UPDATE');
            $transactionQuery->execute([$id]);
            $transaction = $transactionQuery->fetch();
            if ($transaction) {
                $trxStatus = transactionStatusFor($resume, (string)$transaction['status']);
                $conn->prepare('UPDATE transactions SET status = ?, completed_at = NULL WHERE transaction_id = ?')->execute([$trxStatus, (int)$transaction['transaction_id']]);
                $conn->prepare("INSERT INTO transaction_history (transaction_id, event_type, description, performed_by) VALUES (?, 'Shipment reopened', ?, ?)")->execute([(int)$transaction['transaction_id'], mb_substr($ship['reference_no'] . ' reopened at ' . $resume['stage_name'], 0, 255), $userId]);
            }
        }
        logActivity($conn, 'Shipment Update', $ship['reference_no'] . ' reopened at ' . $resume['stage_name'] . ($wasArchived ? ' and restored from archive' : ''));
        createShipmentNotifications($conn, $ship, 'status', 'Shipment reopened', $ship['reference_no'] . ' was reopened at ' . $resume['stage_name'] . '.', $userId);
        $conn->commit(); lifecycleJson(200, ['ok' => true, 'message' => 'Shipment reopened at ' . $resume['stage_name'] . '.']);
    }

    if (!empty($ship['archived_at']) || !empty($current['is_closed'])) { $conn->rollBack(); lifecycleJson(409, ['error' => 'Reopen this shipment before changing its status.']); }
    if ($action === 'hold' && $ship['current_status'] === 'On Hold') { $conn->rollBack(); lifecycleJson(409, ['error' => 'This shipment is already on hold.']); }
    $targetName = $action === 'hold' ? 'On Hold' : 'Cancelled';
    $target = stageByName($targetName);
    $timelineRemark = ($action === 'hold' ? 'Reason: ' : 'Cancellation reason: ') . $reason . ($remarks !== '' ? ' — ' . $remarks : '');
    $at = (string)$conn->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn();
    $conn->prepare("INSERT INTO shipment_status_updates (shipment_id, stage, status, remarks, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$id, $targetName, updateRowStatus($target, false), mb_substr($timelineRemark, 0, 255), $userId, $at]);
    $conn->prepare('UPDATE shipments SET current_status = ? WHERE shipment_id = ?')->execute([$targetName, $id]);
    $trxQuery = $conn->prepare('SELECT transaction_id, status FROM transactions WHERE shipment_id = ? ORDER BY transaction_id DESC LIMIT 1 FOR UPDATE');
    $trxQuery->execute([$id]);
    $trx = $trxQuery->fetch();
    if ($trx) {
        $trxStatus = transactionStatusFor($target, (string)$trx['status']);
        $conn->prepare('UPDATE transactions SET status = ?, completed_at = ? WHERE transaction_id = ?')->execute([$trxStatus, $trxStatus === 'completed' ? $at : null, (int)$trx['transaction_id']]);
        $conn->prepare("INSERT INTO transaction_history (transaction_id, event_type, description, performed_by) VALUES (?, 'Status update', ?, ?)")->execute([(int)$trx['transaction_id'], mb_substr($ship['reference_no'] . ' changed to ' . $targetName . ': ' . $timelineRemark, 0, 255), $userId]);
    } else {
        $conn->prepare('INSERT INTO transactions (client_id, shipment_id, status, completed_at) VALUES (?, ?, ?, ?)')->execute([(int)$ship['client_id'], $id, $targetName === 'Cancelled' ? 'completed' : 'ongoing', $targetName === 'Cancelled' ? $at : null]);
        $newTransactionId = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO transaction_history (transaction_id, event_type, description, performed_by) VALUES (?, 'Status update', ?, ?)")->execute([$newTransactionId, mb_substr($ship['reference_no'] . ' changed to ' . $targetName . ': ' . $timelineRemark, 0, 255), $userId]);
    }
    logActivity($conn, 'Shipment Update', $ship['reference_no'] . ' changed to ' . $targetName . ' (' . $reason . ')');
    createShipmentNotifications($conn, $ship, 'status', 'Shipment ' . strtolower($targetName), $ship['reference_no'] . ' is now ' . $targetName . ': ' . $reason, $userId);
    $conn->commit();
    lifecycleJson(200, ['ok' => true, 'message' => $ship['reference_no'] . ' is now ' . $targetName . '.']);
} catch (Throwable $error) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('shipment_lifecycle failed: ' . $error->getMessage());
    lifecycleJson(500, ['error' => 'The shipment action could not be saved. Please try again.']);
}
