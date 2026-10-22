<?php

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "astratrack";

try {
  $conn = new PDO(
    "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
    "root",
    "",
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
  );

} catch (PDOException $e){
  die("connection failed: " . $e->getMessage());
}

?>