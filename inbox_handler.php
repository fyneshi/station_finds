<?php
/**
 * Messages & Notifications Handler (AJAX, JSON)
 *
 * Actions:
 *   - unread:                        unread counts (navbar markers) and, with push on, the newest notification
 *   - send:           { to, text, reply_to? }  send a chat message (optionally replying to one)
 *   - poll:           { with, after } new messages after id `after`, plus reactions / unsends on recent ones
 *   - react:          { id, emoji }  add, change or remove your reaction to a message
 *   - send_photo:     form upload: to, photo, text?, reply_to?, csrf_token  send a photo (e.g. a payment screenshot)
 *   - unsend:         { id }         unsend your own message (for both people)
 *   - hide:           { id }         remove a message for yourself only
 *   - read_thread:    { with }       mark a chat (and its alert) as read
 *   - read:           { id }         mark one notification as read
 *   - mark_all_read:                 mark every notification as read
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
require_once 'inbox.php';

$userId = (int) $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $input['action'] ?? '';

// Changes must be JSON requests from this site (browsers block cross-site JSON posts).
// Photo uploads can't be JSON, so they carry the page's security token instead.
require_once 'shop.php';   // sf_check_csrf
$isPhotoUpload = $action === 'send_photo' && sf_check_csrf();
if ($action !== 'unread' && !$isPhotoUpload && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

function fail($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Messages as the chat page draws them
// Messages as messages.php draws them (text, quoted reply, reactions); see sf_message_payloads
function format_messages($conn, array $rows, $userId, $otherId) {
    $other = sf_user($conn, $otherId);
    return sf_message_payloads($conn, $userId, $rows, $other['username'] ?? 'Them');
}

switch ($action) {

    case 'unread':
        echo json_encode(['success' => true] + sf_unread_counts($conn, $userId) + sf_push_info($conn, $userId));
        break;

    case 'send':
        $to   = (int) ($input['to'] ?? 0);
        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '') fail('Please write a message.');
        if (mb_strlen($text) > SF_MESSAGE_MAX) fail('Messages can be up to ' . SF_MESSAGE_MAX . ' characters.');
        if ($to === $userId || !sf_user($conn, $to)) fail('That person could not be found.');

        // Replying to a message: it has to be in this same chat and not unsent
        $replyTo = (int) ($input['reply_to'] ?? 0) ?: null;
        if ($replyTo) {
            $quoted = sf_my_message($conn, $userId, $replyTo);
            if (!$quoted || $quoted['unsent'] || !in_array($to, [(int) $quoted['sender_id'], (int) $quoted['receiver_id']], true)) $replyTo = null;
        }

        $id = sf_send_message($conn, $userId, $to, $text);
        if ($replyTo) $conn->query("UPDATE messages SET reply_to = " . (int) $replyTo . " WHERE id = " . (int) $id);
        sf_notify_message($conn, $to, $userId, $_SESSION['username'] ?? 'Someone', $text);
        $row = ['id' => $id, 'sender_id' => $userId, 'body' => $text, 'reply_to' => $replyTo, 'unsent' => 0, 'ts' => time()];
        echo json_encode(['success' => true, 'message' => format_messages($conn, [$row], $userId, $to)[0]]);
        break;

    case 'send_photo':
        $to   = (int) ($input['to'] ?? 0);
        $text = trim((string) ($input['text'] ?? ''));
        if (mb_strlen($text) > SF_MESSAGE_MAX) fail('Messages can be up to ' . SF_MESSAGE_MAX . ' characters.');
        if ($to === $userId || !sf_user($conn, $to)) fail('That person could not be found.');
        [$saved, $photoError] = sf_save_chat_photo($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '']);
        if ($photoError) fail(($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? 'Choose a photo first.' : $photoError);

        $replyTo = (int) ($input['reply_to'] ?? 0) ?: null;
        if ($replyTo) {
            $quoted = sf_my_message($conn, $userId, $replyTo);
            if (!$quoted || $quoted['unsent'] || !in_array($to, [(int) $quoted['sender_id'], (int) $quoted['receiver_id']], true)) $replyTo = null;
        }
        $id = sf_send_message($conn, $userId, $to, $text);
        $stmt = $conn->prepare("UPDATE messages SET attachment = ?, reply_to = ? WHERE id = ?");
        $stmt->bind_param("sii", $saved, $replyTo, $id);
        $stmt->execute();
        $stmt->close();
        sf_notify_message($conn, $to, $userId, $_SESSION['username'] ?? 'Someone', $text !== '' ? '📷 ' . $text : '📷 Photo');
        $row = ['id' => $id, 'sender_id' => $userId, 'body' => $text, 'attachment' => $saved, 'reply_to' => $replyTo, 'unsent' => 0, 'ts' => time()];
        echo json_encode(['success' => true, 'message' => format_messages($conn, [$row], $userId, $to)[0]]);
        break;

    case 'react':
        // One reaction per person per message; picking the same one again removes it
        $message = sf_my_message($conn, $userId, (int) ($input['id'] ?? 0));
        $emoji   = (string) ($input['emoji'] ?? '');
        if (!$message || $message['unsent']) fail('That message could not be found.');
        if (!in_array($emoji, SF_REACTIONS, true)) fail('Choose one of the reactions.');
        $stmt = $conn->prepare("SELECT emoji FROM message_reactions WHERE message_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $message['id'], $userId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc()['emoji'] ?? null;
        $stmt->close();
        if ($current === $emoji) {
            $stmt = $conn->prepare("DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $message['id'], $userId);
        } else {
            $stmt = $conn->prepare("INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("iis", $message['id'], $userId, $emoji);
        }
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'id' => (int) $message['id'],
                          'reactions' => sf_message_reactions($conn, $userId, [$message['id']])[(int) $message['id']] ?? []]);
        break;

    case 'unsend':
        // Your own message, for both people: the text is wiped and reactions go
        $message = sf_my_message($conn, $userId, (int) ($input['id'] ?? 0));
        if (!$message || (int) $message['sender_id'] !== $userId) fail('You can only unsend your own messages.');
        $photo = $conn->query("SELECT attachment FROM messages WHERE id = " . (int) $message['id'])->fetch_assoc()['attachment'] ?? '';
        sf_delete_chat_photo($photo);
        $conn->query("UPDATE messages SET body = '', attachment = '', unsent_at = COALESCE(unsent_at, NOW()) WHERE id = " . (int) $message['id']);
        $conn->query("DELETE FROM message_reactions WHERE message_id = " . (int) $message['id']);
        echo json_encode(['success' => true, 'id' => (int) $message['id']]);
        break;

    case 'hide':
        // Remove for you: the other person still sees it
        $message = sf_my_message($conn, $userId, (int) ($input['id'] ?? 0));
        if (!$message) fail('That message could not be found.');
        $stmt = $conn->prepare("INSERT IGNORE INTO message_hidden (user_id, message_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $userId, $message['id']);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'id' => (int) $message['id']]);
        break;

    case 'poll':
        $with  = (int) ($input['with'] ?? 0);
        $after = max(0, (int) ($input['after'] ?? 0));
        $rows  = sf_thread($conn, $userId, $with, $after);
        if ($rows) sf_mark_thread_read($conn, $userId, $with);
        // Changes to messages already shown (the other person reacted or unsent)
        $recent = sf_thread($conn, $userId, $with, 0, 60);
        $reacts = sf_message_reactions($conn, $userId, array_column($recent, 'id'));
        $state  = array_map(fn($m) => ['id' => (int) $m['id'], 'unsent' => (bool) $m['unsent'],
                                        'reactions' => $m['unsent'] ? [] : ($reacts[(int) $m['id']] ?? [])], $recent);
        echo json_encode(['success' => true, 'messages' => format_messages($conn, $rows, $userId, $with), 'state' => $state]);
        break;

    case 'read_thread':
        sf_mark_thread_read($conn, $userId, (int) ($input['with'] ?? 0));
        echo json_encode(['success' => true] + sf_unread_counts($conn, $userId));
        break;

    case 'read':
        sf_mark_notifications_read($conn, $userId, (int) ($input['id'] ?? 0));
        echo json_encode(['success' => true] + sf_unread_counts($conn, $userId));
        break;

    case 'mark_all_read':
        sf_mark_notifications_read($conn, $userId);
        echo json_encode(['success' => true] + sf_unread_counts($conn, $userId));
        break;

    default:
        fail('Invalid action.');
}

$conn->close();
