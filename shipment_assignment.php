<?php
/** Admin-only assignment endpoint. Only currently unassigned shipments can be batch-assigned here. */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';
require_once __DIR__ . '/function/log_activity.php';
[$userId, $fullName, $role] = requireStaffOrAdmin($conn, true);
header('Content-Type: application/json; charset=utf-8');
function assignmentJson(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($role !== 'admin') assignmentJson(403, ['error' => 'Only administrators can assign shipments.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') assignmentJson(405, ['error' => 'Use POST to assign shipments.']);
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) assignmentJson(403, ['error' => 'Your session expired. Reload the page and try again.']);

$staffId = (int)($_POST['staff_id'] ?? 0);
$rawIds = $_POST['shipment_ids'] ?? [];
if (!is_array($rawIds)) $rawIds = [$rawIds];
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn($id) => $id > 0)));
if (!$staffId || !$ids || count($ids) > 100) assignmentJson(422, ['error' => 'Choose an active staff member and at least one shipment.']);

$staffQuery = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? AND role = 'staff' AND status = 'active'");
$staffQuery->execute([$staffId]);
$staff = $staffQuery->fetch();
if (!$staff) assignmentJson(422, ['error' => 'Choose an active staff member.']);

try {
    $conn->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $shipmentQuery = $conn->prepare("SELECT shipment_id, reference_no, client_id, assigned_staff_id FROM shipments WHERE shipment_id IN ($placeholders) FOR UPDATE");
    $shipmentQuery->execute($ids);
    $shipments = $shipmentQuery->fetchAll();
    if (count($shipments) !== count($ids)) {
        $conn->rollBack();
        assignmentJson(404, ['error' => 'One or more shipments could not be found. Refresh the list and try again.']);
    }
    foreach ($shipments as $shipment) {
        if ($shipment['assigned_staff_id'] !== null) {
            $conn->rollBack();
            assignmentJson(409, ['error' => $shipment['reference_no'] . ' has already been assigned. Refresh the list and try again.']);
        }
    }
    $update = $conn->prepare('UPDATE shipments SET assigned_staff_id = ? WHERE shipment_id = ? AND assigned_staff_id IS NULL');
    foreach ($shipments as $shipment) {
        $update->execute([$staffId, $shipment['shipment_id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('An assignment changed while saving.');
        logActivity($conn, 'Shipment Update', $shipment['reference_no'] . ' assigned to ' . $staff['full_name']);
        createShipmentNotifications($conn, array_merge($shipment, ['assigned_staff_id' => $staffId]), 'assignment', 'Shipment assigned', $shipment['reference_no'] . ' was assigned to ' . $staff['full_name'] . '.', $userId);
    }
    $conn->commit();
    assignmentJson(200, ['success' => true, 'staff' => $staff['full_name'], 'count' => count($shipments), 'items' => array_map(static fn($s) => ['id' => (int)$s['shipment_id'], 'reference' => $s['reference_no']], $shipments)]);
} catch (Throwable $ex) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('shipment_assignment failed: ' . $ex->getMessage());
    assignmentJson(500, ['error' => 'The shipment assignment could not be saved. Please try again.']);
}
