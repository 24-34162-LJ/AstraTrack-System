<?php
/**
 * Backward-compatible route for links created by the retired shared Shipment Details page.
 * The Untitled (19) document-management screen has been removed from the active workflow.
 * Existing records and files remain untouched; each role returns to its established details page.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/function/shipment_common.php';

$isClient = (($_SESSION['user']['role'] ?? '') === 'client');
if ($isClient) {
    requireClient($conn);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        header('Location: clint_shipment.php', true, 303);
        exit;
    }
    header('Location: clint_shipment.php?id=' . $id, true, 303);
    exit;
}

[, , $role] = requireStaffOrAdmin($conn);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . shipListPage($role), true, 303);
    exit;
}
header('Location: shipment_view.php?id=' . $id, true, 303);
exit;