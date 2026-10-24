<?php
// Credentials come from config/database.local.php (git-ignored) or environment variables.
// Never put a real password in this file. See config/database.local.sample.php.

$cfg = [];
$local = __DIR__ . "/database.local.php";
if (is_file($local)) {
  $cfg = require $local;
}

$servername = $cfg["host"] ?? getenv("ASTRATRACK_DB_HOST") ?: "localhost";
$dbname     = $cfg["name"] ?? getenv("ASTRATRACK_DB_NAME") ?: "astratrack";
$username   = $cfg["user"] ?? getenv("ASTRATRACK_DB_USER") ?: null;
$password   = $cfg["pass"] ?? getenv("ASTRATRACK_DB_PASS");
if ($password === false) $password = null;

// Only a developer machine (XAMPP) may fall back to root with no password.
if ($username === null) {
  $host = strtolower((string)($_SERVER["SERVER_NAME"] ?? "localhost"));
  if (PHP_SAPI !== "cli" && !in_array($host, ["localhost", "127.0.0.1", "::1"], true)) {
    error_log("AstraTrack: no database user configured (config/database.local.php or ASTRATRACK_DB_USER).");
    http_response_code(500);
    die("Database is not configured.");
  }
  $username = "root";
  $password = $password ?? "";
}

try {
  $conn = new PDO(
    "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
    $username,
    (string)$password,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
  );

} catch (PDOException $e){
  error_log("AstraTrack DB connection failed: " . $e->getMessage());
  http_response_code(500);
  die("Database connection failed.");
}

?>