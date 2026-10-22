<?php

require_once "config/database.php";
/**
 * AstraTrack - Login page
 * Files: login.php + css/login.css + js/login.js
 * Requires: PHP 7.4+
 */
session_start();

// Already logged in? Go straight to the dashboard.
if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}


$error = '';
$old   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $old      = $login;

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($login === '' || $password === '') {
        $error = 'Enter your email or username and password.';
    } else {
        $stmt = $conn->prepare(
                "SELECT user_id, full_name, email, password_hash, role 
                FROM users 
                WHERE (full_name = ? OR email = ?) AND status = 'active'
                LIMIT 1"
        );
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => $user['user_id'], 
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                ];

            if (!empty($_POST['remember'])) {
                // Keeps the session cookie for 30 days
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + 60 * 60 * 24 * 30,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            switch ($user['role']) {
                case 'admin':
                    header('Location: admin.php');
                    break;
                case 'staff':
                    header('Location: staff.php');
                    break;
                case 'client':
                    header('Location: client_dashbroad.php');
                    break;
                default:
                     // safety fallback
                    header('Location: login.php');
                    break;
            }
            exit;
        }
        $error = 'Incorrect email/username or password.';
    }
}

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$logoIcon = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3.3 7.5L12 12.5l8.7-5"/><path d="M12 22V12.5"/></svg>';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | AstraTrack</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets\css\login.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0 min-vh-100">

        <!-- ============ LEFT: BRAND PANEL ============ -->
        <aside class="col-lg-6 d-none d-lg-flex brand-panel">
            <div class="brand-inner">

                <a class="brand-link" href="index.php" aria-label="AstraTrack home">
                    <span class="brand-logo"><?= $logoIcon ?></span>
                    <span class="brand-name">Astra<span>Track</span></span>
                </a>

                <div class="brand-content">
                    <!-- Ship illustration -->
                    <svg class="illustration" viewBox="0 0 280 205" role="img" aria-label="Cargo ship carrying containers">
                        <defs><clipPath id="card"><rect width="280" height="205" rx="18"/></clipPath></defs>
                        <rect width="280" height="205" rx="18" fill="#1f4e79"/>
                        <g clip-path="url(#card)">
                            <!-- status bubble -->
                            <ellipse cx="228" cy="52" rx="14" ry="6" fill="#2a6597"/>
                            <rect x="151" y="13" width="107" height="35" rx="9" fill="#fff"/>
                            <circle cx="166" cy="30" r="5" fill="#2f7fc1"/>
                            <rect x="178" y="25" width="58" height="5" rx="2.5" fill="#17395e"/>
                            <rect x="178" y="34" width="36" height="5" rx="2.5" fill="#a9c6e3"/>

                            <!-- containers -->
                            <rect x="104" y="45" width="33" height="23" fill="#e3edf7"/>
                            <rect x="137" y="45" width="33" height="23" fill="#6fb1e4"/>
                            <rect x="87"  y="68" width="33" height="23" fill="#6fb1e4"/>
                            <rect x="120" y="68" width="33" height="23" fill="#2f7fc1"/>
                            <rect x="153" y="68" width="33" height="23" fill="#e3edf7"/>
                            <rect x="70"  y="91" width="33" height="23" fill="#2f7fc1"/>
                            <rect x="103" y="91" width="33" height="23" fill="#e3edf7"/>
                            <rect x="136" y="91" width="33" height="23" fill="#6fb1e4"/>
                            <rect x="169" y="91" width="33" height="23" fill="#2f7fc1"/>

                            <!-- deck + hull -->
                            <rect x="49" y="113" width="194" height="4" fill="#6fb1e4"/>
                            <polygon points="52,117 243,117 228,150 70,150" fill="#12304f"/>

                            <!-- waves -->
                            <path d="M0 158 Q 17 148 35 158 T 70 158 T 105 158 T 140 158 T 175 158 T 210 158 T 245 158 T 280 158 V205 H0 Z" fill="#2e86c8"/>
                            <path d="M0 175 Q 20 166 40 175 T 80 175 T 120 175 T 160 175 T 200 175 T 240 175 T 280 175 V205 H0 Z" fill="#12304f"/>
                        </g>
                    </svg>

                    <h1 class="brand-title">
                        <span class="d-block">Shipments, billing, and payments in</span>
                        <span class="d-block">one place.</span>
                    </h1>
                    <p class="brand-sub">A centralized system for Astra Cargo Impex Solutions OPC.</p>
                </div>

                <div class="brand-footer">&copy; <?= date('Y') ?> AstraTrack. All rights reserved.</div>
            </div>
        </aside>

        <!-- ============ RIGHT: LOGIN FORM ============ -->
        <main class="col-12 col-lg-6 login-panel">
            <!-- Logo shows on mobile only (left panel is hidden) -->
            <a class="brand-link brand-link-dark d-lg-none mb-4" href="landing_page.php" aria-label="AstraTrack home">
                <span class="brand-logo"><?= $logoIcon ?></span>
                <span class="brand-name">Astra<span>Track</span></span>
            </a>

            <div class="login-card">
                <h2>Welcome Back</h2>
                <p class="lead-sm">Sign in to access your AstraTrack account.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-login d-flex align-items-start gap-2" role="alert">
                        <svg class="flex-shrink-0 mt-1" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                        <span><?= e($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="" id="loginForm" novalidate>
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

                    <div class="mb-3">
                        <label for="login" class="form-label">Email or Username</label>
                        <input type="text" class="form-control" id="login" name="login"
                               placeholder="Enter your email or username"
                               value="<?= e($old) ?>" autocomplete="username"
                               autocapitalize="none" spellcheck="false" required autofocus>
                        <div class="invalid-feedback">Please enter your email or username.</div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="password-wrap">
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" class="toggle-pass" id="togglePass" aria-label="Show password" aria-pressed="false">
                                <svg id="eyeIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <div class="invalid-feedback d-block-if-invalid">Please enter your password.</div>
                        <div class="caps-hint" id="capsHint" hidden>Caps Lock is on</div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>

                    <button type="submit" class="btn btn-login w-100" id="loginBtn">
                        <span class="btn-text">Log In</span>
                        <span class="btn-loading" hidden>
                            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Signing in…
                        </span>
                    </button>

                    <p class="authorized text-center mt-3 mb-0">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="me-1" style="vertical-align:-1px" aria-hidden="true">
                            <rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                        </svg>Authorized users only.
                    </p>
                </form>
            </div>
        </main>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets\js\login.js"></script>
</body>
</html>