<?php
/**
 * Opens a renter's ID photo (Account › Edit Profile › ID verification) for an admin reviewing
 * it in admin_users.php?tab=ids. The files live in private/ids, which the web can't reach.
 *
 *   admin_id_file.php?user=<user id>&doc=front|selfie
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
sf_admin_require($conn, 'users');

$column = ($_GET['doc'] ?? '') === 'selfie' ? 'id_selfie' : 'id_front';
$stmt = $conn->prepare("SELECT $column AS doc FROM users WHERE id = ?");
$userId = (int) ($_GET['user'] ?? 0);
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$path = $row ? sf_id_document_path($row['doc']) : null;
if (!$path) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'That ID photo could not be found.';
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . SF_ID_TYPES[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . ($column === 'id_selfie' ? 'selfie' : 'id-front') . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
