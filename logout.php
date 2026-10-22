<?php
session_start();          // required before you can destroy the session
session_destroy();        // wipe all session data
header('Location: login.php');
exit;                     // always exit after a header redirect
?>