<?php
/**
 * Report Handler (AJAX, JSON) — used by js/report.js
 *
 * Actions:
 *   - report:  { type: 'listing' | 'user', id, reason, details }   file a report for the admins
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit;
}

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => "Admin accounts can't use this feature."]);
    exit;
}

require_once 'db_setup.php';
require_once 'reports.php';

$input = json_decode(file_get_contents('php://input'), true);

// Changes must be JSON requests from this site (browsers block cross-site JSON posts)
if (!is_array($input) || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false || ($input['action'] ?? '') !== 'report') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$error = sf_file_report($conn, (int) $_SESSION['user_id'], (string) ($input['type'] ?? ''), (int) ($input['id'] ?? 0),
    (string) ($input['reason'] ?? ''), (string) ($input['details'] ?? ''));

echo json_encode($error
    ? ['success' => false, 'message' => $error]
    : ['success' => true, 'message' => 'Thanks for letting us know. Our team will review your report.']);
