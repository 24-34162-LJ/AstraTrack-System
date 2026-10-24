<?php
/** Stream one shipment document after checking the signed-in user's access. */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';

$sessionRole = $_SESSION['user']['role'] ?? '';
$isClient = $sessionRole === 'client';
if ($isClient) {
    [, $clientId] = requireClient($conn);
    $query = $conn->prepare('SELECT d.file_path FROM shipment_documents d JOIN shipments s ON s.shipment_id = d.shipment_id WHERE d.document_id = ? AND s.client_id = ?');
    $query->execute([(int)($_GET['id'] ?? 0), $clientId]);
} else {
    requireStaffOrAdmin($conn);
    $query = $conn->prepare('SELECT d.file_path FROM shipment_documents d JOIN shipments s ON s.shipment_id = d.shipment_id WHERE d.document_id = ?');
    $query->execute([(int)($_GET['id'] ?? 0)]);
}
$filePath = $query->fetchColumn();
$uploadsRoot = realpath(__DIR__ . '/uploads');
$file = $filePath ? realpath(__DIR__ . '/' . ltrim((string)$filePath, '/\\')) : false;
if (!$uploadsRoot || !$file || !is_file($file) || strpos($file, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('Document not found.');
}

$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $detector = new finfo(FILEINFO_MIME_TYPE);
    $detected = $detector->file($file);
    if (is_string($detected) && $detected !== '') $mime = $detected;
}
$inlineTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$inline = isset($_GET['view']) && in_array($mime, $inlineTypes, true);
$filename = str_replace(["\r", "\n", '"'], ['', '', '_'], basename($file));
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
header('Content-Length: ' . (string)filesize($file));
readfile($file);
exit;
