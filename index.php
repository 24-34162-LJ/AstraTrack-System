<?php
/**
 * AstraTrack - Landing page
 * Files: landing_page.php + css/landing_page.css + js/landing_page.js
 */
session_start();

$loggedIn  = !empty($_SESSION['user']);
$loginUrl  = $loggedIn ? 'dashboard.php' : 'login.php';
$loginText = $loggedIn ? 'Dashboard' : 'Login';

$navLinks = [
    'Home'     => '#home',
    'About Us' => '#about',
    'Features' => '#features',
    'Contact'  => '#contact',
];

// Feature icons (inline SVG paths, 24x24, stroke)
$features = [
    [
        'title' => 'Shipment Monitoring',
        'text'  => 'Monitor shipment progress and view recorded customs-processing, trucking, and delivery updates.',
        'icon'  => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    ],
    [
        'title' => 'Client Information Management',
        'text'  => 'Organize and maintain client information in a centralized system.',
        'icon'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    ],
    [
        'title' => 'Billing and Payment Tracking',
        'text'  => 'Review billing records, payment statuses, and outstanding balances.',
        'icon'  => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6"/><path d="M16 12H8"/><path d="M13 16H8"/>',
    ],
    [
        'title' => 'Transaction Management',
        'text'  => 'Organize transaction records and monitor ongoing, pending, and completed transactions.',
        'icon'  => '<path d="m17 3 4 4-4 4"/><path d="M3 7h18"/><path d="m7 21-4-4 4-4"/><path d="M21 17H3"/>',
    ],
];

// Small helper for the logo icon (box)
$logoIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3.3 7.5L12 12.5l8.7-5"/><path d="M12 22V12.5"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AstraTrack | Customs Brokerage Management System</title>
    <meta name="description" content="AstraTrack is a centralized platform for monitoring shipment progress, managing transaction records, and tracking billing and payment statuses.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets\css\landing.css" rel="stylesheet">
</head>
<body>

<!-- ================= NAVBAR ================= -->
<nav class="navbar navbar-expand-lg at-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="#home">
            <span class="brand-logo"><?= $logoIcon ?></span>
            <span class="brand-name">Astra<span>Track</span></span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="mainNav">
            <ul class="navbar-nav align-items-lg-center gap-lg-2 pt-3 pt-lg-0">
                <?php foreach ($navLinks as $label => $href): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= $href ?>"><?= $label ?></a></li>
                <?php endforeach; ?>
                <li class="nav-item ms-lg-3 pb-3 pb-lg-0">
                    <a class="btn btn-at-primary" href="<?= $loginUrl ?>"><?= $loginText ?></a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main>
    <!-- ================= HERO ================= -->
    <section id="home" class="hero">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <p class="eyebrow">Customs Brokerage Management System</p>
                    <h1 class="hero-title">Track Shipments. Manage Transactions. Stay Updated.</h1>
                    <p class="hero-text">AstraTrack provides a centralized platform for monitoring shipment progress, managing transaction records, and tracking billing and payment statuses.</p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="<?= $loginUrl ?>" class="btn btn-at-primary btn-lg-at">Get Started</a>
                        <a href="#about" class="btn btn-at-outline btn-lg-at">Learn More</a>
                    </div>
                </div>

                <div class="col-lg-6">
                    <svg class="hero-illustration" viewBox="0 0 462 337" role="img" aria-label="Cargo ship carrying containers">
                        <defs><clipPath id="heroClip"><rect width="462" height="337" rx="28"/></clipPath></defs>
                        <rect width="462" height="337" rx="28" fill="#e3edf7"/>
                        <g clip-path="url(#heroClip)">
                            <!-- status bubble -->
                            <circle cx="373" cy="80" r="28" fill="#f4f8fc"/>
                            <rect x="248" y="23" width="178" height="57" rx="12" fill="#fff" stroke="#d5e1ee"/>
                            <circle cx="272" cy="47" r="8" fill="#2f7fc1"/>
                            <rect x="289" y="43" width="98" height="8" rx="4" fill="#17395e"/>
                            <rect x="289" y="55" width="49" height="6" rx="3" fill="#6fb1e4"/>

                            <!-- containers -->
                            <rect x="172" y="76"  width="54" height="36" fill="#17395e"/>
                            <rect x="226" y="76"  width="56" height="36" fill="#6fb1e4"/>
                            <rect x="144" y="112" width="54" height="37" fill="#6fb1e4"/>
                            <rect x="198" y="112" width="56" height="37" fill="#2f7fc1"/>
                            <rect x="254" y="112" width="57" height="37" fill="#17395e"/>
                            <rect x="116" y="149" width="55" height="36" fill="#2f7fc1"/>
                            <rect x="171" y="149" width="55" height="36" fill="#17395e"/>
                            <rect x="226" y="149" width="57" height="36" fill="#6fb1e4"/>
                            <rect x="283" y="149" width="56" height="36" fill="#2f7fc1"/>

                            <!-- deck + hull -->
                            <rect x="80" y="185" width="320" height="7" fill="#2f7fc1"/>
                            <polygon points="80,192 400,192 365,248 115,248" fill="#17395e"/>

                            <!-- waves -->
                            <path d="M0 270 Q 38 238 76 262 T 152 262 T 228 262 T 304 262 T 380 262 T 456 262 T 532 262 V337 H0 Z" fill="#4a90c8"/>
                            <path d="M0 292 Q 38 262 76 284 T 152 284 T 228 284 T 304 284 T 380 284 T 456 284 T 532 284 V337 H0 Z" fill="#1b4574"/>
                        </g>
                    </svg>
                </div>
            </div>

            <!-- Track shipment -->
            <div class="track-card mx-auto" id="track">
                <h2>Track Your Shipment</h2>
                <p class="track-sub">Check your shipment status using your Bill of Lading (BL) number.</p>

                <form id="trackForm" novalidate>
                    <label for="blNumber" class="form-label">BL Number</label>
                    <div class="row g-3 align-items-center">
                        <div class="col-md">
                            <input type="text" class="form-control form-control-at" id="blNumber" name="bl_number"
                                   placeholder="Enter your BL Number" autocomplete="off" maxlength="40">
                        </div>
                        <div class="col-md-auto">
                            <button type="submit" class="btn btn-at-blue w-100">Track Shipment</button>
                        </div>
                    </div>
                    <p class="track-note mb-0" id="trackNote">Shipment information is available only to authorized users.</p>
                </form>
            </div>
        </div>
    </section>

    <!-- ================= FEATURES ================= -->
    <section id="features" class="section bg-white">
        <div class="container">
            <div class="text-center mb-5">
                <p class="eyebrow mb-1">Features</p>
                <h2 class="section-title">Everything in one centralized system</h2>
            </div>

            <div class="row g-4">
                <?php foreach ($features as $f): ?>
                    <div class="col-sm-6 col-lg-3">
                        <div class="feature-card h-100">
                            <div class="feature-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $f['icon'] ?></svg>
                            </div>
                            <h3><?= htmlspecialchars($f['title']) ?></h3>
                            <p><?= htmlspecialchars($f['text']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ================= ABOUT ================= -->
    <section id="about" class="section bg-panel">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <p class="eyebrow mb-1">About</p>
                    <h2 class="section-title mb-3">About AstraTrack</h2>
                    <p class="about-text mb-0">AstraTrack is a proposed web-based management system designed to help Astra Cargo Impex Solutions OPC organize business records and improve the monitoring of shipments, transactions, billing, and payments.</p>
                </div>

                <div class="col-lg-6">
                    <div class="steps-card">
                        <div class="steps">
                            <div class="step">
                                <span class="step-circle bg-navy">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3.3 7.5L12 12.5l8.7-5"/></svg>
                                </span>
                                <span class="step-label">Customs processing</span>
                            </div>
                            <span class="step-line"></span>
                            <div class="step">
                                <span class="step-circle bg-blue">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v11H3a1 1 0 0 1-1-1Z"/><path d="M14 9h4l4 4v4h-8"/><circle cx="7" cy="18" r="1.6" fill="#2f7fc1"/><circle cx="18" cy="18" r="1.6" fill="#2f7fc1"/></svg>
                                </span>
                                <span class="step-label">Trucking</span>
                            </div>
                            <span class="step-line"></span>
                            <div class="step">
                                <span class="step-circle bg-sky">
                                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#17395e" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                </span>
                                <span class="step-label">Delivery</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= CTA ================= -->
    <section class="section bg-white pt-4">
        <div class="container">
            <div class="cta-box text-center">
                <h2>Manage Your Shipment Information in One Place</h2>
                <p>Access relevant shipment and transaction information through a centralized platform.</p>
                <a href="<?= $loginUrl ?>" class="btn btn-at-blue px-4">Login to AstraTrack</a>
            </div>
        </div>
    </section>
</main>

<!-- ================= FOOTER ================= -->
<footer id="contact" class="at-footer">
    <div class="container">
        <div class="row gy-4">
            <div class="col-lg-6">
                <a class="d-flex align-items-center gap-2 mb-2 text-decoration-none" href="#home">
                    <span class="brand-logo brand-logo-light"><?= $logoIcon ?></span>
                    <span class="brand-name text-white">Astra<span>Track</span></span>
                </a>
                <p class="mb-1">Customs Brokerage Shipment &amp; Transaction Monitoring System</p>
                <p class="mb-1 text-white">Astra Cargo Impex Solutions OPC</p>
                <p class="mb-0">Buhay na Tubig, Imus, Cavite, Philippines</p>
            </div>
            <div class="col-lg-6">
                <ul class="footer-links list-unstyled d-flex flex-wrap justify-content-lg-end gap-4 mb-0">
                    <li><a href="#home">Home</a></li>
                    <li><a href="#about">About</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#contact">Contact</a></li>
                    <li><a href="<?= $loginUrl ?>"><?= $loginText ?></a></li>
                </ul>
            </div>
        </div>
        <hr class="footer-divider">
        <p class="copyright mb-0">&copy; <?= date('Y') ?> AstraTrack. All rights reserved.</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets\js\landing.js"></script>
</body>
</html>