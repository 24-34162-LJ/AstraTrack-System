<?php


session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'client') {
    header('Location: login.php');
    exit;
}


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Destroy the session and redirect to login page
    session_start();
    session_destroy();
    header("Location: login.php");
    exit();
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Document</title>
</head>
<body>
  <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
    <button type="submit">logout</button>
  </form>
</body>
</html>