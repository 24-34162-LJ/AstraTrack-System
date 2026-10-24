<?php
/**
 * shipment_documents.php — shared rules for the shipment Documents card.
 * Used by shipment_documents_download.php and legacy document access routes
 * and shipment_documents_download.php.
 *
 * The checklist is fixed (6 required + 1 optional) because customs processing always asks for the
 * same set. The labels below are also the values saved in shipment_documents.doc_type, so a new
 * upload always matches its checklist slot. Older rows keep their own wording and are matched by
 * the aliases in documentCatalog().
 */

function documentCatalog(): array
{
    return [
        'bl' => [
            'label'    => 'B/L or AWB',
            'required' => true,
            'aliases'  => ['b/l or awb', 'bl or awb', 'b/l', 'bil of lading', 'bill of lading', 'awb', 'bl', 'b/l or awb no.'],
        ],
        'invoice' => [
            'label'    => 'Commercial Invoice',
            'required' => true,
            'aliases'  => ['commercial invoice', 'commercial invoice (ci)', 'invoice'],
        ],
        'packing' => [
            'label'    => 'Packing List',
            'required' => true,
            'aliases'  => ['packing list', 'packing'],
        ],
        'entry' => [
            'label'    => 'Import Entry',
            'required' => true,
            'aliases'  => ['import entry', 'import declaration', 'entry', 'single administrative document'],
        ],
        'assessment' => [
            'label'    => 'Assessment Notice',
            'required' => true,
            'aliases'  => ['assessment notice', 'assessment', 'notice of assessment'],
        ],
        'duties' => [
            'label'    => 'Duties Receipt',
            'required' => true,
            'aliases'  => ['duties receipt', 'duties and taxes receipt', 'duties', 'receipt'],
        ],
        'permit' => [
            'label'    => 'Permit (if needed)',
            'required' => false,
            'aliases'  => ['permit (if needed)', 'permit', 'permit if needed'],
        ],
    ];
}

function documentNormalizeType($type): string
{
    $type = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$type)));
    return rtrim($type, " .\t\n\r\0\x0B");
}

// Checklist key ('bl', 'invoice', ...) for a saved doc_type, or null for anything unknown
function documentSlotFor($type): ?string
{
    $normalized = documentNormalizeType($type);
    if ($normalized === '') return null;

    foreach (documentCatalog() as $key => $slot) {
        if (in_array($normalized, $slot['aliases'], true)) return $key;
    }
    // Tolerate extra wording such as "Commercial Invoice - final"
    foreach (documentCatalog() as $key => $slot) {
        foreach ($slot['aliases'] as $alias) {
            if ($alias !== '' && strpos($normalized, $alias) !== false) return $key;
        }
    }
    return null;
}

// Canonical doc_type for an upload, or null when the posted value is not one of ours
function documentCanonicalType($type): ?string
{
    $slot = documentSlotFor($type);
    if ($slot === null) return null;
    $catalog = documentCatalog();
    return $catalog[$slot]['label'];
}

/**
 * Checklist rows for the Documents card.
 * Returns ['slots' => [...], 'requiredTotal' => 6, 'requiredReceived' => n]
 * Each slot: key, label, required, received, count, documents (matching rows).
 */
function documentChecklist(array $documents): array
{
    $catalog = documentCatalog();
    $slots = [];
    foreach ($catalog as $key => $meta) {
        $slots[$key] = [
            'key'       => $key,
            'label'     => $meta['label'],
            'required'  => (bool)$meta['required'],
            'received'  => false,
            'count'     => 0,
            'documents' => [],
        ];
    }
    foreach ($documents as $document) {
        $slot = documentSlotFor($document['doc_type'] ?? '');
        if ($slot === null) continue;
        $slots[$slot]['received'] = true;
        $slots[$slot]['count']++;
        $slots[$slot]['documents'][] = $document;
    }
    $requiredTotal = 0;
    $requiredReceived = 0;
    foreach ($slots as $slot) {
        if (!$slot['required']) continue;
        $requiredTotal++;
        if ($slot['received']) $requiredReceived++;
    }
    return ['slots' => array_values($slots), 'requiredTotal' => $requiredTotal, 'requiredReceived' => $requiredReceived];
}

// "1.8 MB", "842 KB", "96 KB", "512 B"
function formatFileSize($bytes): string
{
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '—';
    if ($bytes >= 1048576) {
        $value = rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
        return $value . ' MB';
    }
    if ($bytes >= 1024) return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

function documentAllowedExtensions(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'];
}

function documentMaxBytes(): int
{
    return 10 * 1024 * 1024; // 10 MB, same wording as the upload dialog
}

// The browser label for an extension ("PDF", "JPG", "XLSX")
function documentExtensionLabel(string $extension): string
{
    return strtoupper($extension === 'jpeg' ? 'jpg' : $extension);
}

function documentMimeMatches(string $extension, string $mime): bool
{
    $map = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'xls'  => ['application/vnd.ms-excel', 'application/msexcel', 'application/x-msexcel', 'application/x-cdf', 'application/cdfv2', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
    return in_array(strtolower($mime), $map[$extension] ?? [], true);
}

// Absolute path of a stored document when it really sits inside /uploads, otherwise false
function documentAbsolutePath($relativePath)
{
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if (!$uploadsRoot) return false;
    $file = realpath(__DIR__ . '/../' . ltrim((string)$relativePath, '/\\'));
    if (!$file || !is_file($file)) return false;
    if (strpos($file, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0) return false;
    return $file;
}

function documentFileSize($relativePath): int
{
    $file = documentAbsolutePath($relativePath);
    return $file ? (int)filesize($file) : 0;
}

/**
 * Moves an uploaded file into uploads/<reference>/ and returns the relative path.
 * Throws RuntimeException with a message safe to show to the user.
 */
function documentStoreUpload(array $file, string $reference, string $docType, string $extension): string
{
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if (!$uploadsRoot) throw new RuntimeException('The uploads folder is not available.');

    $folderName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $reference);
    $folderName = trim($folderName, '_') ?: 'shipment';
    $folder = $uploadsRoot . DIRECTORY_SEPARATOR . $folderName;
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        throw new RuntimeException('The uploads folder could not be created.');
    }

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $docType));
    $slug = trim($slug, '-') ?: 'document';
    $name = $slug . '_' . date('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($extension);
    $target = $folder . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('The file could not be saved. Try again.');
    }
    @chmod($target, 0644);
    return 'uploads/' . $folderName . '/' . $name;
}

// Upload errors in the wording of the dialog
function documentUploadError(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'File is larger than 10 MB. Compress or replace this file.';
        case UPLOAD_ERR_PARTIAL:   return 'The upload did not finish. Replace this file and try again.';
        case UPLOAD_ERR_NO_TMP_DIR:return 'The server has no temporary folder for uploads.';
        case UPLOAD_ERR_CANT_WRITE:return 'The server could not write the file to disk.';
        case UPLOAD_ERR_EXTENSION: return 'A server extension stopped the upload.';
        default:                   return 'The file could not be uploaded. Replace or remove it.';
    }
}
