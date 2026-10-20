<?php

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "";

try {
  $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);

  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  echo "connection succsess";
} catch (PDOException $e){
  die("connection failed: " . $e->getMessage());
}

?>