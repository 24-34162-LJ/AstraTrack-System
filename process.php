<?php

require_once "config/database.php";


try {
  $conn->exec("USE astra_track");
  // $sql = "CREATE DATABASE IF NOT EXISTS astra_track";

  /*
  $sql = "CREATE TABLE user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    password VARCHAR(255)
  )";

  */


  /*$sql = "INSERT INTO user (
    username,
    password
  ) VALUES (
    :username,
    :password
  )";

  $stmt = $conn->prepare($sql);

  $stmt->bindParam(":username", $username, PDO::PARAM_STR);
  $stmt->bindParam(":password", $password, PDO::PARAM_STR);

  $username = 'ash';
  $password = "12345";
  $stmt->execute();
  */
   
  $sql = "UPDATE user SET username = 'lance' WHERE id = 1";

  $conn->exec($sql);

  $sql = "DELETE FROM user WHERE username = 'lance' ";

  $conn->exec($sql);


  $sql = "SELECT * FROM user";

  $result = $conn->query($sql);

  if ($result->rowCount() > 0) {

    echo "<table style='width: 10%; border: 1px solid black;'>";
    echo "<tr>";
    echo "<th>id</th>";
    echo "<th>username</th>";
    echo "<th>password</th>";
    echo "</tr>";

    while($row = $result->fetch()) {
      echo "<tr>";
      echo "<td>". $row["id"] ."</td>";
      echo "<td>". $row["username"] . "</td>";
      echo "<td>" . $row["password"] . "</td>";
      echo "</tr>";
    }

    echo "</table>";
  }

  

} catch (PDOException $e) {
  die("connection failed: " . $e->getMessage());
}

$stmt = null;
$conn = null;
?>