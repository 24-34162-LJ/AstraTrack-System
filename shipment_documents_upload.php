<?php
/** Authenticated upload endpoint for shipment documents. */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';
require_once __DIR__ . '/function/shipment_documents.php';
require_once __DIR__ . '/function/log_activity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function documentUploadReply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

[$userId, $fullName, $role] = requireStaffOrAdmin($conn, true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') documentUploadReply(405, ['ok' => false, 'error' => 'Use POST to upload a document.']);
if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    documentUploadReply(403, ['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']);
}
$shipmentId = (int)($_POST['shipment_id'] ?? 0);
$docType = documentCanonicalType($_POST['doc_type'] ?? '');
$remarks = trim((string)($_POST['remarks'] ?? ''));
if (!$shipmentId || !$docType) documentUploadReply(422, ['ok' => false, 'error' => 'Choose a valid document type.']);
if (mb_strlen($remarks) > 255) documentUploadReply(422, ['ok' => false, 'error' => 'Remarks must be 255 characters or fewer.']);
if (empty($_FILES['file']) || !is_array($_FILES['file'])) documentUploadReply(422, ['ok' => false, 'error' => 'Choose a file to upload.']);
$file = $_FILES['file'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) documentUploadReply(422, ['ok' => false, 'error' => documentUploadError((int)$file['error'])]);
if (!is_uploaded_file((string)$file['tmp_name'])) documentUploadReply(422, ['ok' => false, 'error' => 'The uploaded file could not be verified.']);
if ((int)$file['size'] <= 0 || (int)$file['size'] > documentMaxBytes()) documentUploadReply(422, ['ok' => false, 'error' => 'File is larger than 10 MB or empty.']);
$originalName = basename(str_replace('\\', '/', (string)$file['name']));
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, documentAllowedExtensions(), true)) documentUploadReply(422, ['ok' => false, 'error' => 'This file type is not supported.']);
$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file((string)$file['tmp_name']);
    if (is_string($detected) && $detected !== '') $mime = $detected;
}
if (!documentMimeMatches($extension, $mime)) documentUploadReply(422, ['ok' => false, 'error' => 'The file contents do not match the selected file type.']);
$shipmentQuery = $conn->prepare('SELECT shipment_id, reference_no, client_id, assigned_staff_id FROM shipments WHERE shipment_id = ? AND archived_at IS NULL');
$shipmentQuery->execute([$shipmentId]);
$shipment = $shipmentQuery->fetch();
if (!$shipment) documentUploadReply(404, ['ok' => false, 'error' => 'Shipment not found.']);

$storedPath = null;
try {
    $storedPath = documentStoreUpload($file, (string)$shipment['reference_no'], $docType, $extension);
    $insert = $conn->prepare('INSERT INTO shipment_documents (shipment_id, doc_type, file_path, remarks, uploaded_by) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$shipmentId, $docType, $storedPath, $remarks !== '' ? $remarks : null, $userId]);
    logActivity($conn, 'Shipment Document', $shipment['reference_no'] . ' document uploaded: ' . $docType . ' (' . $originalName . ')');
    createShipmentNotifications($conn, $shipment, 'document', 'Document uploaded', $shipment['reference_no'] . ': ' . $docType . ' uploaded.', $userId);
    documentUploadReply(200, ['ok' => true, 'message' => 'Document uploaded.']);
} catch (Throwable $error) {
    if ($storedPath) {
        $storedFile = documentAbsolutePath($storedPath);
        if ($storedFile) @unlink($storedFile);
    }
    error_log('shipment_documents_upload failed: ' . $error->getMessage());
    documentUploadReply(500, ['ok' => false, 'error' => 'The document could not be saved. Please try again.']);
}
