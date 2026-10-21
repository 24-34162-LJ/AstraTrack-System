<?php

require_once "config/database.php";


/*
$accounts = [
    'admin@astratrack.local'  => '123456',      // change these to stronger passwords
    'staff@astratrack.local'  => 'staff123',
    'client@astratrack.local' => 'client123',
];

$stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
foreach ($accounts as $email => $plain) {
    $stmt->execute([password_hash($plain, PASSWORD_DEFAULT), $email]);
}
echo "Done";
*/


$stmt = null;
$conn = null;
?>