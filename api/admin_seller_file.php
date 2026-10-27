<?php
/**
 * Opens the ID document a seller uploaded (Become a Seller, step 2) for an admin reviewing
 * them in admin_sellers.php. The files live in private/ids, which the web can't reach.
 *
 *   admin_seller_file.php?id=<seller user id>
 */
session_start();

// Admins only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php?mode=login');
    exit;
}

require_once dirname(__DIR__) . '/includes/db_setup.php';
require_once dirname(__DIR__) . '/includes/shop.php';
require_once dirname(__DIR__) . '/includes/seller.php';
require_once dirname(__DIR__) . '/includes/admin_layout.php';
sf_admin_require($conn, 'seller-apps');

$profile = sf_seller_profile($conn, (int) ($_GET['id'] ?? 0));
$path    = $profile ? sf_id_document_path($profile['id_document']) : null;
if (!$path) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'That ID document could not be found.';
    exit;
}

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$name = preg_replace('/[^\w.\- ]/u', '_', $profile['id_document_name'] ?: 'id-document.' . $ext);
header('Content-Type: ' . SF_ID_TYPES[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
