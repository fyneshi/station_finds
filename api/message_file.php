<?php
/**
 * Opens a photo sent in Messages (e.g. a payment screenshot) for the two people in that chat.
 * The files live in private/chat, which the web can't reach directly.
 *
 *   message_file.php?id=<message id>
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/includes/db_setup.php';
require_once dirname(__DIR__) . '/includes/inbox.php';

$userId  = (int) $_SESSION['user_id'];
$message = sf_my_message($conn, $userId, (int) ($_GET['id'] ?? 0));   // only if you sent or received it
$path    = null;
if ($message && !$message['unsent']) {
    $row  = $conn->query("SELECT attachment FROM messages WHERE id = " . (int) $message['id'])->fetch_assoc();
    $path = sf_chat_photo_path($row['attachment'] ?? '');
}
if (!$path) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'That photo is not available.';
    exit;
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="photo-' . (int) $message['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
