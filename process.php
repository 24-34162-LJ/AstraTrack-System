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

/*

// email => new password (change these)
$accounts = [
    'admin@astratrack.local'  => 'Admin@12345',
    'staff@astratrack.local'  => 'Staff@12345',
    'client@astratrack.local' => 'Client@12345',
];

$stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = ?");

foreach ($accounts as $email => $plain) {
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $stmt->execute([$hash, $email]);
    echo $email . ' → updated (' . $stmt->rowCount() . ' row)<br>';
}

*/

$stmt = null;
$conn = null;
?>