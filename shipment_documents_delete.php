<?php
/** Admin-only removal endpoint for a shipment document. */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';
require_once __DIR__ . '/function/shipment_documents.php';
require_once __DIR__ . '/function/log_activity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function documentDeleteReply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

[$userId, $fullName, $role] = requireStaffOrAdmin($conn, true);
if ($role !== 'admin') documentDeleteReply(403, ['ok' => false, 'error' => 'Only an administrator can delete shipment documents.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') documentDeleteReply(405, ['ok' => false, 'error' => 'Use POST to delete a document.']);
if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    documentDeleteReply(403, ['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']);
}
$documentId = (int)($_POST['document_id'] ?? 0);
$query = $conn->prepare('SELECT d.file_path, d.doc_type, s.shipment_id, s.reference_no, s.client_id, s.assigned_staff_id FROM shipment_documents d JOIN shipments s ON s.shipment_id = d.shipment_id WHERE d.document_id = ?');
$query->execute([$documentId]);
$document = $query->fetch();
if (!$document) documentDeleteReply(404, ['ok' => false, 'error' => 'Document not found.']);

try {
    $conn->prepare('DELETE FROM shipment_documents WHERE document_id = ?')->execute([$documentId]);
    $filePath = documentAbsolutePath($document['file_path']);
    if ($filePath) @unlink($filePath);
    logActivity($conn, 'Shipment Document', $document['reference_no'] . ' document deleted: ' . $document['doc_type']);
    createShipmentNotifications($conn, $document, 'document', 'Document removed', $document['reference_no'] . ': ' . $document['doc_type'] . ' was removed.', $userId);
    documentDeleteReply(200, ['ok' => true, 'message' => 'Document deleted.']);
} catch (Throwable $error) {
    error_log('shipment_documents_delete failed: ' . $error->getMessage());
    documentDeleteReply(500, ['ok' => false, 'error' => 'The document could not be deleted. Please try again.']);
}
