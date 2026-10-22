<?php
/**
 * shipment_add.php — Add Shipment from shipping documents
 *
 * Flow:  upload PDF  ->  Gemini reads it  ->  staff reviews / edits  ->  Save writes to the database
 *
 * Files:  shipment_add.php (this), assets/js/shipment_add.js, assets/css/shipment_add.css (+ staff.css),
 *         config/gemini.php (API key, keep it OUT of git), function/log_activity.php
 * Tables written on Save: shipments, containers, shipment_status_updates, shipment_documents,
 *                         transactions, transaction_history, activity_log
 * AJAX:   POST ?ajax=extract  (PDF -> extracted fields as JSON)
 *         POST ?ajax=save     (reviewed fields + PDF -> database)
 */
session_start();
require_once 'config/database.php';        // $conn (PDO)
require_once 'function/log_activity.php';  // logActivity()

/* ------------------------------------------------------------------
 * 1) HELPERS
 * ------------------------------------------------------------------ */
function e($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }

function jsonOut(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

// Text: trims, collapses spaces, cuts to the column length, empty -> null
function cleanStr($v, int $max): ?string
{
    if ($v === null || is_array($v) || is_object($v)) return null;
    $v = trim((string)preg_replace('/\s+/u', ' ', (string)$v));
    if ($v === '') return null;
    return mb_substr($v, 0, $max, 'UTF-8');
}

// Number: accepts 1,234.50 or 1234.5; returns null when empty or not a number
function cleanNum($v): ?float
{
    if ($v === null || $v === '' || is_array($v)) return null;
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = str_replace([',', ' '], '', (string)$v);
    return is_numeric($s) ? (float)$s : null;
}

function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonOut(405, ['error' => 'Use POST.']);
    }
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
        jsonOut(403, ['error' => 'Your session expired. Reload the page and try again.']);
    }
}

// Validates the uploaded PDF and returns its temp path
function uploadedPdf(): string
{
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = $f['error'] ?? 'none';
        jsonOut(400, ['error' => "Upload failed (code $code). If the file is large, check upload_max_filesize and post_max_size in php.ini."]);
    }
    $tmp = $f['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        jsonOut(400, ['error' => 'Invalid upload.']);
    }
    if ($f['size'] > 25 * 1024 * 1024) {
        jsonOut(400, ['error' => 'The file is larger than 25 MB.']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $head = (string)file_get_contents($tmp, false, null, 0, 5);
    if ($mime !== 'application/pdf' || $head !== '%PDF-') {
        jsonOut(400, ['error' => 'Only PDF files are accepted.']);
    }
    return $tmp;
}

function safeFileName(string $name): string
{
    $base = (string)pathinfo($name, PATHINFO_FILENAME);
    $base = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $base), '._-');
    if ($base === '') $base = 'shipping_documents';
    return substr($base, 0, 80) . '.pdf';
}

function columnExists(PDO $conn, string $table, string $column): bool
{
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

// AST-2026-0001 style. Call inside a transaction (locks the rows it reads).
function nextReference(PDO $conn): string
{
    $prefix = 'AST-' . date('Y') . '-';
    $from   = strlen($prefix) + 1;
    $st = $conn->prepare(
        "SELECT MAX(CAST(SUBSTRING(reference_no, $from) AS UNSIGNED))
         FROM shipments WHERE reference_no LIKE ? FOR UPDATE"
    );
    $st->execute([$prefix . '%']);
    $n = (int)$st->fetchColumn() + 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------
 * 2) ACCESS CONTROL — active staff or admin only
 * ------------------------------------------------------------------ */
$ajax    = (string)($_GET['ajax'] ?? '');
$sess    = $_SESSION['user'] ?? [];
$userId  = (int)($sess['id'] ?? $sess['user_id'] ?? 0);
$me      = false;
if ($userId > 0) {
    $st = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ? AND status = 'active' AND role IN ('staff','admin')");
    $st->execute([$userId]);
    $me = $st->fetch();
}
if (!$me) {
    if ($ajax !== '') jsonOut(401, ['error' => 'Your session expired. Please log in again.']);
    header('Location: login.php');
    exit;
}
$fullName = $me['full_name'];
$role     = $me['role'];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ------------------------------------------------------------------
 * 3) GEMINI (document reading)
 * ------------------------------------------------------------------ */
function geminiConfig(): array
{
    $file = __DIR__ . '/config/gemini.php';
    if (!is_file($file)) {
        jsonOut(500, ['error' => 'config/gemini.php not found. Copy config/gemini.sample.php to config/gemini.php and add your API key.']);
    }
    $cfg = require $file;
    $key = is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
    if ($key === '' || stripos($key, 'PASTE_') !== false || stripos($key, 'xxxx') !== false) {
        jsonOut(500, ['error' => 'The Gemini API key is missing in config/gemini.php.']);
    }
    return $cfg;
}

function extractPrompt(): string
{
    return <<<'PROMPT'
You are a data-entry assistant for a customs brokerage in the Philippines.
The attached PDF may contain several shipping documents in one file (Bill of Lading / Sea Waybill,
commercial invoice, packing list, marine insurance certificate). Read ALL pages and extract the data below.

Rules:
- Respond with ONLY valid JSON. No markdown, no explanation.
- Copy values exactly as printed. Do not guess. Use null when a value is not in the document.
- Numbers must be JSON numbers (no commas, no currency symbols).
- "shipper" and "consignee": the company NAME only, without the street address.
- "incoterms": the 3-letter Incoterm code only (for example CIP), without the place.
- "currency": the 3-letter code (for example USD).
- "insurance" (in commercial_invoice) is only used for an automatic check; still copy it exactly.
- Ignore the insurance certificate: do not extract its fields.
- If the same field appears in different documents with different values, use the value from the
  document the field belongs to, and add a short note to "warnings".

JSON shape:
{
  "bill_of_lading": {
    "bl_number": null, "shipper": null, "consignee": null, "vessel_voyage": null,
    "port_of_loading": null, "port_of_discharge": null, "hs_code": null,
    "goods_description": null, "total_units": null, "gross_weight_kg": null, "measurement_cbm": null
  },
  "commercial_invoice": {
    "invoice_no": null, "incoterms": null, "quantity": null, "unit_cost": null,
    "total_amount": null, "insurance": null, "freight": null, "fob_value": null, "currency": null
  },
  "containers": [
    { "container_no": null, "seal_no": null, "size_type": null, "units": null }
  ],
  "warnings": []
}
PROMPT;
}

function callGemini(array $cfg, string $pdfBytes): array
{
    $model = (string)($cfg['model'] ?? 'gemini-3.6-flash');
    $payload = [
        'contents' => [[
            'parts' => [
                ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($pdfBytes)]],
                ['text' => extractPrompt()],
            ],
        ]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'temperature'      => 0,
            'maxOutputTokens'  => 8000,
        ],
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 170,
        CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . $cfg['api_key'], 'content-type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    // Local testing only: some XAMPP installs lack SSL certificates (curl error 60)
    if (!empty($cfg['insecure_ssl_for_local_testing'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    $body    = curl_exec($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        jsonOut(502, ['error' => 'Could not reach the Gemini API: ' . $curlErr]);
    }
    $data = json_decode($body, true);
    if ($status !== 200) {
        jsonOut(502, ['error' => 'Gemini: ' . ($data['error']['message'] ?? 'API error (HTTP ' . $status . ').')]);
    }

    $text = '';
    foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (isset($part['text'])) $text .= $part['text'];
    }
    if ($text === '') {
        jsonOut(502, ['error' => 'Gemini returned no text (the answer may have been blocked or cut off).']);
    }
    $extracted = json_decode(trim((string)preg_replace('/```json|```/', '', $text)), true);
    if (!is_array($extracted)) {
        jsonOut(502, ['error' => 'The model did not return valid JSON. Try again.']);
    }
    return $extracted;
}

function isNum($v): bool { return is_int($v) || is_float($v); }
function val($a, $k) { return is_array($a) ? ($a[$k] ?? null) : null; }

// Arithmetic cross-checks on the raw extraction, so a misread number is caught before saving
function runChecks(array $d): array
{
    $checks = [];
    $inv = $d['commercial_invoice'] ?? [];
    $bl  = $d['bill_of_lading'] ?? [];

    if (isNum(val($inv, 'total_amount')) && isNum(val($inv, 'insurance')) && isNum(val($inv, 'freight')) && isNum(val($inv, 'fob_value'))) {
        $diff = abs($inv['total_amount'] - $inv['insurance'] - $inv['freight'] - $inv['fob_value']);
        $checks[] = ['name' => 'Invoice total - insurance - freight = FOB value', 'ok' => $diff < 0.02];
    }
    if (isNum(val($inv, 'quantity')) && isNum(val($inv, 'unit_cost')) && isNum(val($inv, 'total_amount'))) {
        $diff = abs($inv['quantity'] * $inv['unit_cost'] - $inv['total_amount']);
        $checks[] = ['name' => 'Quantity x unit cost = invoice total', 'ok' => $diff < 0.02];
    }
    $containerUnits = 0;
    foreach ((is_array($d['containers'] ?? null) ? $d['containers'] : []) as $c) {
        if (isNum(val($c, 'units'))) $containerUnits += $c['units'];
    }
    if (isNum(val($bl, 'total_units')) && $containerUnits > 0) {
        $checks[] = ['name' => 'Container units add up to B/L total units', 'ok' => $containerUnits == $bl['total_units']];
    }
    if (isNum(val($bl, 'total_units')) && isNum(val($inv, 'quantity'))) {
        $checks[] = ['name' => 'B/L units match invoice quantity', 'ok' => $bl['total_units'] == $inv['quantity']];
    }
    return $checks;
}

// Raw Gemini JSON -> flat fields named like the shipments columns (nothing the client dropped is passed on)
function normalizeExtraction(array $d): array
{
    $bl  = is_array($d['bill_of_lading'] ?? null) ? $d['bill_of_lading'] : [];
    $inv = is_array($d['commercial_invoice'] ?? null) ? $d['commercial_invoice'] : [];

    $incoterm = null;   // column is VARCHAR(10): keep only the 3-letter code
    if (!empty($inv['incoterms']) && preg_match('/\b(EXW|FCA|FAS|FOB|CFR|CIF|CPT|CIP|DAP|DPU|DDP|DAT)\b/i', (string)$inv['incoterms'], $m)) {
        $incoterm = strtoupper($m[1]);
    }
    $currency = cleanStr($inv['currency'] ?? null, 3);
    if ($currency !== null) $currency = strtoupper($currency);

    $containers = [];
    foreach ((is_array($d['containers'] ?? null) ? $d['containers'] : []) as $c) {
        if (!is_array($c)) continue;
        $no = cleanStr($c['container_no'] ?? null, 20);
        if ($no === null) continue;
        $containers[] = [
            'container_no' => strtoupper(str_replace(' ', '', $no)),
            'seal_no'      => cleanStr($c['seal_no'] ?? null, 30),
            'size_type'    => cleanStr($c['size_type'] ?? null, 20),
        ];
    }

    $packages = cleanNum($bl['total_units'] ?? null);

    return [
        'bl_awb_no'          => cleanStr($bl['bl_number'] ?? null, 50),
        'shipper_name'       => cleanStr($bl['shipper'] ?? null, 150),
        'consignee_name'     => cleanStr($bl['consignee'] ?? null, 150),
        'vessel_or_flight'   => cleanStr($bl['vessel_voyage'] ?? null, 100),
        'port_of_loading'    => cleanStr($bl['port_of_loading'] ?? null, 100),
        'port_of_discharge'  => cleanStr($bl['port_of_discharge'] ?? null, 100),
        'hs_code'            => cleanStr($bl['hs_code'] ?? null, 20),
        'total_packages'     => $packages === null ? null : (int)round($packages),
        'total_gross_weight' => cleanNum($bl['gross_weight_kg'] ?? null),
        'measurement_cbm'    => cleanNum($bl['measurement_cbm'] ?? null),
        'incoterm'           => $incoterm,
        'invoice_no'         => cleanStr($inv['invoice_no'] ?? null, 50),
        'currency'           => $currency,
        'invoice_value'      => cleanNum($inv['total_amount'] ?? null),
        'freight_amount'     => cleanNum($inv['freight'] ?? null),
        'fob_value'          => cleanNum($inv['fob_value'] ?? null),
        'remarks'            => cleanStr($bl['goods_description'] ?? null, 1000),
        'containers'         => $containers,
    ];
}

/* Helps staff: guess the client / import-export from the document so they only confirm it */
function normName(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = (string)preg_replace('/\b(inc|incorporated|corp|corporation|co|company|ltd|limited|llc|opc|the)\b/u', ' ', $s);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

function matchClient(array $clients, ?string $name): ?int
{
    if ($name === null) return null;
    $n = normName($name);
    if (strlen($n) < 3) return null;
    $best = null;
    $bestLen = 0;
    foreach ($clients as $c) {
        $cn = normName((string)$c['client_name']);
        if (strlen($cn) < 3) continue;
        if ((strpos($n, $cn) !== false || strpos($cn, $n) !== false) && strlen($cn) > $bestLen) {
            $best    = (int)$c['client_id'];
            $bestLen = strlen($cn);
        }
    }
    return $best;
}

function isPhPlace(?string $t): bool
{
    return $t !== null && (bool)preg_match('/philippines|manila|cebu|davao|subic|batangas|iloilo|cagayan|clark|\bph\b/i', $t);
}

/* ------------------------------------------------------------------
 * 4) AJAX: extract
 * ------------------------------------------------------------------ */
if ($ajax === 'extract') {
    requirePost();
    set_time_limit(180);
    $cfg = geminiConfig();
    $tmp = uploadedPdf();

    $raw  = callGemini($cfg, (string)file_get_contents($tmp));
    $form = normalizeExtraction($raw);

    $clients = $conn->query("SELECT client_id, client_name FROM clients WHERE status = 'active'")->fetchAll();

    $inPh  = isPhPlace($form['port_of_discharge']);
    $outPh = isPhPlace($form['port_of_loading']);
    $type  = '';
    if ($inPh && !$outPh)      $type = 'import';
    elseif ($outPh && !$inPh)  $type = 'export';

    // Import: the client is the consignee. Export: the client is the shipper.
    if ($type === 'export') {
        $clientId = matchClient($clients, $form['shipper_name']);
    } else {
        $clientId = matchClient($clients, $form['consignee_name']);
        if ($clientId === null && $type === '') $clientId = matchClient($clients, $form['shipper_name']);
    }
    $form['client_id']        = $clientId;
    $form['transaction_type'] = $type;
    $form['mode']             = 'sea';

    $warnings = [];
    foreach ((is_array($raw['warnings'] ?? null) ? $raw['warnings'] : []) as $w) {
        if (is_scalar($w) && trim((string)$w) !== '') $warnings[] = (string)$w;
    }

    jsonOut(200, ['form' => $form, 'checks' => runChecks($raw), 'warnings' => $warnings]);
}

/* ------------------------------------------------------------------
 * 5) AJAX: save
 * ------------------------------------------------------------------ */
if ($ajax === 'save') {
    requirePost();

    $in = json_decode((string)($_POST['data'] ?? ''), true);
    if (!is_array($in)) {
        jsonOut(400, ['error' => 'No shipment data was received.']);
    }
    $tmp = uploadedPdf();
    $errors = [];

    // --- client / type / mode ---
    $clientId = (int)($in['client_id'] ?? 0);
    $st = $conn->prepare("SELECT client_name FROM clients WHERE client_id = ? AND status = 'active'");
    $st->execute([$clientId]);
    $clientName = $st->fetchColumn();
    if ($clientName === false) $errors[] = 'Choose a client.';

    $type = (string)($in['transaction_type'] ?? '');
    if (!in_array($type, ['import', 'export'], true)) $errors[] = 'Choose Import or Export.';
    $mode = (string)($in['mode'] ?? '');
    if (!in_array($mode, ['sea', 'air', 'land'], true)) $errors[] = 'Choose the mode of transport.';

    // --- text fields (cut to the column length) ---
    $bl = cleanStr($in['bl_awb_no'] ?? null, 50);
    if ($bl === null) $errors[] = 'The B/L or AWB number is required.';
    $shipper   = cleanStr($in['shipper_name'] ?? null, 150);
    $consignee = cleanStr($in['consignee_name'] ?? null, 150);
    $vessel    = cleanStr($in['vessel_or_flight'] ?? null, 100);
    $pol       = cleanStr($in['port_of_loading'] ?? null, 100);
    $pod       = cleanStr($in['port_of_discharge'] ?? null, 100);
    $hs        = cleanStr($in['hs_code'] ?? null, 20);
    $incoterm  = cleanStr($in['incoterm'] ?? null, 10);
    if ($incoterm !== null) $incoterm = strtoupper($incoterm);
    $invoiceNo = cleanStr($in['invoice_no'] ?? null, 50);
    $remarks   = cleanStr($in['remarks'] ?? null, 2000);

    $currency = cleanStr($in['currency'] ?? null, 10);
    if ($currency === null) {
        $currency = 'USD';
    } else {
        $currency = strtoupper($currency);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) $errors[] = 'Currency must be a 3-letter code such as USD or PHP.';
    }

    // --- numbers ---
    $num = function (string $key, string $label, float $max) use ($in, &$errors): ?float {
        $raw = $in[$key] ?? null;
        if ($raw === null || trim((string)$raw) === '') return null;
        $n = cleanNum($raw);
        if ($n === null || $n < 0 || $n >= $max) {
            $errors[] = "$label must be a positive number.";
            return null;
        }
        return $n;
    };
    $packages = $num('total_packages', 'Total packages', 2000000000);
    $weight   = $num('total_gross_weight', 'Gross weight', 1000000000);
    $cbm      = $num('measurement_cbm', 'Measurement (CBM)', 1000000000);
    $invValue = $num('invoice_value', 'Invoice total', 10000000000000);
    $freight  = $num('freight_amount', 'Ocean freight', 10000000000000);
    $fob      = $num('fob_value', 'FOB value', 10000000000000);

    // --- containers ---
    $containers = [];
    $seen = [];
    $rows = is_array($in['containers'] ?? null) ? array_slice($in['containers'], 0, 200) : [];
    foreach ($rows as $i => $c) {
        if (!is_array($c)) continue;
        $no   = cleanStr($c['container_no'] ?? null, 20);
        $seal = cleanStr($c['seal_no'] ?? null, 30);
        $size = cleanStr($c['size_type'] ?? null, 20);
        if ($no === null && $seal === null && $size === null) continue;   // empty row
        if ($no === null) { $errors[] = 'Container row ' . ($i + 1) . ' has no container number.'; continue; }
        $no = strtoupper(str_replace(' ', '', $no));
        if (isset($seen[$no])) { $errors[] = "Container $no is listed twice."; continue; }
        $seen[$no] = true;
        $containers[] = [$no, $seal, $size];
    }

    // --- B/L must not be saved twice ---
    if ($bl !== null) {
        $st = $conn->prepare("SELECT reference_no FROM shipments WHERE bl_awb_no = ? LIMIT 1");
        $st->execute([$bl]);
        $dup = $st->fetchColumn();
        if ($dup !== false) $errors[] = "B/L $bl is already saved as $dup.";
    }

    if ($errors) {
        jsonOut(422, ['error' => implode(' ', $errors), 'errors' => $errors]);
    }

    $dec = function (?float $n, int $d): ?string { return $n === null ? null : number_format($n, $d, '.', ''); };
    $hasRemarks = columnExists($conn, 'shipments', 'remarks');
    $staffId    = $role === 'staff' ? $userId : null;

    $ref = '';
    $shipmentId = 0;
    $destDir = null;
    $destAbs = null;

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $conn->beginTransaction();
            $ref = nextReference($conn);

            // shipments
            $cols = ['reference_no', 'client_id', 'assigned_staff_id', 'transaction_type', 'mode',
                     'bl_awb_no', 'shipper_name', 'consignee_name', 'vessel_or_flight',
                     'port_of_loading', 'port_of_discharge', 'hs_code',
                     'total_packages', 'total_gross_weight', 'measurement_cbm', 'incoterm',
                     'invoice_no', 'currency', 'invoice_value', 'freight_amount', 'fob_value', 'current_status'];
            $vals = [$ref, $clientId, $staffId, $type, $mode,
                     $bl, $shipper, $consignee, $vessel,
                     $pol, $pod, $hs,
                     $packages === null ? null : (int)round($packages), $dec($weight, 3), $dec($cbm, 3), $incoterm,
                     $invoiceNo, $currency, $dec($invValue, 2), $dec($freight, 2), $dec($fob, 2), 'Documents received'];
            if ($hasRemarks) {
                $cols[] = 'remarks';
                $vals[] = $remarks;
            }
            $sql = 'INSERT INTO shipments (' . implode(', ', $cols) . ') VALUES ('
                 . implode(', ', array_fill(0, count($cols), '?')) . ')';
            $conn->prepare($sql)->execute($vals);
            $shipmentId = (int)$conn->lastInsertId();

            // containers
            $ins = $conn->prepare("INSERT INTO containers (shipment_id, container_no, seal_no, size_type) VALUES (?, ?, ?, ?)");
            foreach ($containers as [$no, $seal, $size]) {
                $ins->execute([$shipmentId, $no, $seal, $size]);
            }

            // first timeline entry
            $conn->prepare("INSERT INTO shipment_status_updates (shipment_id, stage, status, remarks, updated_by) VALUES (?, ?, ?, ?, ?)")
                 ->execute([$shipmentId, 'Documents received', 'Completed', 'Shipment created from the uploaded shipping documents.', $userId]);

            // keep the PDF: uploads/<reference>/<file>.pdf
            $destDir = __DIR__ . '/uploads/' . $ref;
            if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
                throw new RuntimeException('Could not create the uploads folder.');
            }
            $fileName = safeFileName((string)($_FILES['file']['name'] ?? ''));
            $destAbs  = $destDir . '/' . $fileName;
            if (!copy($tmp, $destAbs)) {
                throw new RuntimeException('Could not store the PDF file.');
            }
            $conn->prepare("INSERT INTO shipment_documents (shipment_id, doc_type, file_path, uploaded_by) VALUES (?, ?, ?, ?)")
                 ->execute([$shipmentId, 'B/L or AWB', 'uploads/' . $ref . '/' . $fileName, $userId]);

            // transaction record + its first history line
            $conn->prepare("INSERT INTO transactions (client_id, shipment_id, status) VALUES (?, ?, 'pending')")
                 ->execute([$clientId, $shipmentId]);
            $transactionId = (int)$conn->lastInsertId();
            $conn->prepare("INSERT INTO transaction_history (transaction_id, event_type, description, performed_by) VALUES (?, ?, ?, ?)")
                 ->execute([$transactionId, 'Shipment created', "Shipment $ref created from uploaded documents (B/L $bl).", $userId]);

            $conn->commit();
            break;

        } catch (Throwable $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            if ($destAbs !== null && is_file($destAbs)) @unlink($destAbs);
            if ($destDir !== null && is_dir($destDir)) @rmdir($destDir);
            $destAbs = $destDir = null;

            // Two staff saving at the same second can pick the same reference number: try again
            $dupKey = ($ex instanceof PDOException) && isset($ex->errorInfo[1]) && (int)$ex->errorInfo[1] === 1062;
            if ($dupKey && $attempt < 3) continue;

            error_log('shipment_add save failed: ' . $ex->getMessage());
            jsonOut(500, ['error' => 'The shipment could not be saved.', 'detail' => $ex->getMessage()]);
        }
    }

    logActivity($conn, 'Shipment Update', "Shipment $ref created from uploaded documents ($clientName, B/L $bl)");

    jsonOut(200, ['ok' => true, 'reference_no' => $ref, 'shipment_id' => $shipmentId]);
}

/* ------------------------------------------------------------------
 * 6) PAGE
 * ------------------------------------------------------------------ */
$clients    = $conn->query("SELECT client_id, client_name FROM clients WHERE status = 'active' ORDER BY client_name")->fetchAll();
$hasRemarks = columnExists($conn, 'shipments', 'remarks');

function icon($name) {
    $paths = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'     => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2 .7 3.5 2.6 3.5 5.5"/>',
        'billing' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
        'swap'    => '<path d="M4 8h16m-4-4l4 4-4 4M20 16H4m4-4l-4 4 4 4"/>',
        'chart'   => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'logout'  => '<path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9"/>',
        'bell'    => '<path d="M6 16V11a6 6 0 1112 0v5l1.5 2h-15L6 16z"/><path d="M10 21h4"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'shield'  => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'close'   => '<path d="M6 6l12 12M18 6L6 18"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

$home = $role === 'admin' ? 'admin.php' : 'staff.php';
$activePage = 'shipments';
if ($role === 'admin') {
    $menu = [
        'dashboard'    => ['Dashboard',              'admin.php',           'grid'],
        'users'        => ['User Management',        'user_management.php', 'shield'],
        'shipments'    => ['Shipment Management',    'shipments.php',       'box'],
        'clients'      => ['Client Management',      'clients.php',         'users'],
        'billing'      => ['Billing & Payments',     'billing.php',         'billing'],
        'transactions' => ['Transaction Management', 'transactions.php',    'swap'],
        'reports'      => ['Reports',                'reports.php',         'chart'],
    ];
} else {
    $menu = [
        'dashboard'    => ['Dashboard',              'staff.php',           'grid'],
        'shipments'    => ['Shipment Management',    'shipments.php',       'box'],
        'clients'      => ['Client Management',      'clients.php',         'users'],
        'billing'      => ['Billing & Payments',     'billing.php',         'billing'],
        'transactions' => ['Transaction Management', 'transactions.php',    'swap'],
        'reports'      => ['Reports',                'reports.php',         'chart'],
    ];
}

$words    = preg_split('/\s+/', trim($fullName));
$initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : substr($words[0], 1, 1)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= e($_SESSION['csrf']) ?>">
    <title>Add Shipment | AstraTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff.css">
    <link rel="stylesheet" href="assets/css/shipment_add.css">
</head>
<body>

<div class="layout">

    <!-- ============ SIDEBAR ============ -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="brand-logo"><?= icon('box') ?></span>
            <span class="brand-name">Astra<span>Track</span></span>
        </div>

        <p class="menu-title">MENU</p>
        <nav class="menu">
            <?php foreach ($menu as $key => [$label, $href, $ico]): ?>
                <a href="<?= e($href) ?>" class="menu-item <?= $key === $activePage ? 'active' : '' ?>">
                    <?= icon($ico) ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <span class="avatar"><?= e($initials) ?></span>
                <div>
                    <strong><?= e($fullName) ?></strong>
                    <small><?= e(ucfirst($role)) ?></small>
                </div>
            </div>
            <a href="logout.php" class="menu-item"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>

    <!-- ============ MAIN ============ -->
    <main class="main">

        <header class="topbar">
            <div>
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
                <h1>Add Shipment</h1>
                <p>Upload the shipping documents, review the extracted data, then save.</p>
            </div>
            <div class="topbar-right">
                <div class="profile">
                    <span class="avatar"><?= e($initials) ?></span>
                    <strong><?= e($fullName) ?></strong>
                    <?= icon('chevron') ?>
                </div>
            </div>
        </header>

        <div class="stack">

            <!-- Step 1: upload -->
            <section class="card" id="uploadCard">
                <div class="card-head"><h2>1. Upload shipping documents</h2></div>
                <p class="muted">One PDF with the Bill of Lading, commercial invoice and packing list. The file is kept with the shipment.</p>
                <div class="upload-row">
                    <input type="file" id="pdfFile" accept="application/pdf" aria-label="Choose a PDF file">
                    <button type="button" class="btn-primary" id="extractBtn">Extract data</button>
                </div>
                <p class="status" id="status" role="status"></p>
            </section>

            <!-- Step 2: review and save -->
            <form id="shipForm" hidden data-has-remarks="<?= $hasRemarks ? '1' : '0' ?>" autocomplete="off">

                <section class="card">
                    <div class="card-head"><h2>2. Review and edit</h2></div>
                    <p class="muted" id="clientHint"></p>

                    <div class="form-grid">
                        <div class="field">
                            <label for="f_client_id">Client *</label>
                            <select id="f_client_id" name="client_id" required>
                                <option value="">Select client…</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= (int)$c['client_id'] ?>"><?= e($c['client_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="f_transaction_type">Transaction type *</label>
                            <select id="f_transaction_type" name="transaction_type" required>
                                <option value="">Select…</option>
                                <option value="import">Import</option>
                                <option value="export">Export</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="f_mode">Mode *</label>
                            <select id="f_mode" name="mode" required>
                                <option value="sea">Sea</option>
                                <option value="air">Air</option>
                                <option value="land">Land</option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="f_bl_awb_no">B/L or AWB number *</label>
                            <input id="f_bl_awb_no" name="bl_awb_no" maxlength="50" required>
                        </div>
                        <div class="field">
                            <label for="f_shipper_name">Shipper</label>
                            <input id="f_shipper_name" name="shipper_name" maxlength="150">
                        </div>
                        <div class="field">
                            <label for="f_consignee_name">Consignee</label>
                            <input id="f_consignee_name" name="consignee_name" maxlength="150">
                        </div>
                        <div class="field">
                            <label for="f_vessel_or_flight">Vessel / voyage or flight</label>
                            <input id="f_vessel_or_flight" name="vessel_or_flight" maxlength="100">
                        </div>
                        <div class="field">
                            <label for="f_port_of_loading">Port of loading</label>
                            <input id="f_port_of_loading" name="port_of_loading" maxlength="100">
                        </div>
                        <div class="field">
                            <label for="f_port_of_discharge">Port of discharge</label>
                            <input id="f_port_of_discharge" name="port_of_discharge" maxlength="100">
                        </div>
                        <div class="field">
                            <label for="f_hs_code">HS code</label>
                            <input id="f_hs_code" name="hs_code" maxlength="20">
                        </div>
                        <div class="field">
                            <label for="f_incoterm">Incoterm</label>
                            <input id="f_incoterm" name="incoterm" maxlength="10">
                        </div>
                        <div class="field">
                            <label for="f_total_packages">Total packages</label>
                            <input id="f_total_packages" name="total_packages" inputmode="numeric">
                        </div>
                        <div class="field">
                            <label for="f_total_gross_weight">Gross weight (kg)</label>
                            <input id="f_total_gross_weight" name="total_gross_weight" inputmode="decimal">
                        </div>
                        <div class="field">
                            <label for="f_measurement_cbm">Measurement (CBM)</label>
                            <input id="f_measurement_cbm" name="measurement_cbm" inputmode="decimal">
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card-head"><h2>Invoice</h2></div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="f_invoice_no">Invoice no.</label>
                            <input id="f_invoice_no" name="invoice_no" maxlength="50">
                        </div>
                        <div class="field">
                            <label for="f_currency">Currency</label>
                            <input id="f_currency" name="currency" maxlength="3" placeholder="USD">
                        </div>
                        <div class="field">
                            <label for="f_invoice_value">Invoice total</label>
                            <input id="f_invoice_value" name="invoice_value" inputmode="decimal">
                        </div>
                        <div class="field">
                            <label for="f_freight_amount">Ocean freight</label>
                            <input id="f_freight_amount" name="freight_amount" inputmode="decimal">
                        </div>
                        <div class="field">
                            <label for="f_fob_value">FOB value</label>
                            <input id="f_fob_value" name="fob_value" inputmode="decimal">
                        </div>
                    </div>
                    <?php if ($hasRemarks): ?>
                        <div class="field field-wide">
                            <label for="f_remarks">Description / remarks</label>
                            <textarea id="f_remarks" name="remarks" rows="3" maxlength="2000"></textarea>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <div class="card-head">
                        <h2>Containers</h2>
                        <button type="button" class="btn-outline" id="addContainer">+ Add container</button>
                    </div>
                    <div class="table-wrap">
                        <table id="containerTable">
                            <thead><tr><th>Container no.</th><th>Seal no.</th><th>Type</th><th></th></tr></thead>
                            <tbody id="containerBody"></tbody>
                        </table>
                    </div>
                </section>

                <section class="card" id="checkCard" hidden>
                    <div class="card-head"><h2>Automatic checks</h2></div>
                    <ul class="checks" id="checkList"></ul>
                    <ul class="checks" id="warningList"></ul>
                </section>

                <div class="form-actions">
                    <p class="status" id="saveStatus" role="status"></p>
                    <button type="button" class="btn-outline" id="cancelBtn">Start over</button>
                    <button type="submit" class="btn-primary" id="saveBtn">Save shipment</button>
                </div>
            </form>

            <!-- Step 3: done -->
            <section class="card success" id="doneCard" hidden>
                <div class="card-head"><h2>Shipment saved</h2></div>
                <p>Reference number <strong id="doneRef"></strong> was created with status <em>Documents received</em>.</p>
                <div class="form-actions left">
                    <a class="btn-primary" href="shipments.php" id="doneOpen">View shipment</a>
                    <a class="btn-outline" href="shipment_add.php">Add another shipment</a>
                </div>
            </section>

        </div>
    </main>
</div>

<script src="assets/js/shipment_add.js"></script>
</body>
</html>
