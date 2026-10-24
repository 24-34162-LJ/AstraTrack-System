<?php
/**
 * shipment_documents_download.php — every document of one shipment as a ZIP
 * (a single file is streamed as-is). Clients may download only their own shipments.
 *
 * GET: shipment_id
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';
require_once __DIR__ . '/function/shipment_documents.php';

$isClient = (($_SESSION['user']['role'] ?? '') === 'client');
if ($isClient) {
    [$userId, $clientId] = requireClient($conn);
} else {
    requireStaffOrAdmin($conn);
    $clientId = null;
}

$shipmentId = (int)($_GET['shipment_id'] ?? 0);
$sql = 'SELECT shipment_id, reference_no FROM shipments WHERE shipment_id = ?';
$params = [$shipmentId];
if ($isClient) {
    $sql .= ' AND client_id = ?';
    $params[] = $clientId;
}
$st = $conn->prepare($sql);
$st->execute($params);
$shipment = $st->fetch();
if (!$shipment) {
    http_response_code(404);
    exit('Shipment not found.');
}

$st = $conn->prepare('SELECT document_id, doc_type, file_path FROM shipment_documents WHERE shipment_id = ? ORDER BY uploaded_at, document_id');
$st->execute([$shipmentId]);

$files = [];
$usedNames = [];
foreach ($st->fetchAll() as $row) {
    $path = documentAbsolutePath($row['file_path']);
    if (!$path) continue;

    // readable, unique name inside the archive: "1_Commercial-Invoice_INV-2026-1042.pdf"
    $typeSlug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$row['doc_type']), '-');
    $name = (count($files) + 1) . '_' . ($typeSlug !== '' ? $typeSlug . '_' : '') . basename($path);
    $name = str_replace(["\r", "\n", '"'], ['', '', '_'], $name);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $suffix = 2;
    while (isset($usedNames[strtolower($name)])) {
        $name = $base . '-' . $suffix++ . ($ext !== '' ? '.' . $ext : '');
    }
    $usedNames[strtolower($name)] = true;
    $files[] = ['path' => $path, 'name' => $name];
}

if (!$files) {
    http_response_code(404);
    exit('No documents available for this shipment.');
}

$safeRef = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$shipment['reference_no']);
$zipName = $safeRef . '_documents.zip';

if (count($files) === 1 || !class_exists('ZipArchive')) {
    $file = $files[0];
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
    header('Content-Length: ' . filesize($file['path']));
    readfile($file['path']);
    exit;
}

$tmp = tempnam(sys_get_temp_dir(), 'ast_docs_');
$zip = new ZipArchive();
if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('The documents could not be packaged for download.');
}
foreach ($files as $file) {
    $zip->addFile($file['path'], $file['name']);
}
$zip->close();

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
exit;
