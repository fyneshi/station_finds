<?php
/**
 * Messages & notifications helpers shared by messages.php, notifications.php,
 * inbox_handler.php, checkout.php and auth_handler.php.
 * Needs db_setup.php included first ($conn).
 *
 * Times are read from MySQL as Unix timestamps (UNIX_TIMESTAMP(created_at))
 * and shown in Philippine time.
 */

date_default_timezone_set('Asia/Manila');

const SF_MESSAGE_MAX = 2000;   // longest chat message, in characters

// Notification types and the icon each one shows on notifications.php
const SF_NOTIFY_TYPES = ['message', 'order', 'payout', 'price', 'security', 'info'];

// ── Notifications ───────────────────────────────────────────────
function sf_notify($conn, $userId, $type, $title, $body = '', $link = '') {
    if (!in_array($type, SF_NOTIFY_TYPES, true)) $type = 'info';
    if (!sf_wants_notification($conn, $userId, $type)) return;
    $title = mb_substr($title, 0, 150);
    $body  = mb_substr($body, 0, 500);
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $type, $title, $body, $link);
    $stmt->execute();
    $stmt->close();
}

// Preferences (account.php?tab=preferences) can turn off message and order alerts;
// security alerts are always sent.
function sf_wants_notification($conn, $userId, $type) {
    $columns = ['message' => 'notify_messages', 'order' => 'notify_orders'];
    if (!isset($columns[$type])) return true;
    $stmt = $conn->prepare("SELECT {$columns[$type]} AS wanted FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && (int) $row['wanted'] === 1;
}

// New-message alert: one unread alert per sender, refreshed with the latest message
function sf_notify_message($conn, $receiverId, $senderId, $senderName, $text) {
    if (!sf_wants_notification($conn, $receiverId, 'message')) return;
    $link    = 'messages.php?with=' . (int) $senderId;
    $title   = 'Message from ' . $senderName;
    $preview = '"' . sf_excerpt($text, 120) . '"';

    $stmt = $conn->prepare("UPDATE notifications SET title = ?, body = ?, created_at = CURRENT_TIMESTAMP
                            WHERE user_id = ? AND type = 'message' AND link = ? AND read_at IS NULL");
    $stmt->bind_param("ssis", $title, $preview, $receiverId, $link);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    if ($updated < 1) {
        sf_notify($conn, $receiverId, 'message', $title, $preview, $link);
    }
}

// The newest notifications. $withUnread: also every unread one older than that (the bell's
// Unread tab, js/inbox.js), still newest first.
function sf_notifications($conn, $userId, $limit = 50, $withUnread = false) {
    $stmt = $conn->prepare("SELECT id, type, title, body, link, read_at IS NOT NULL AS is_read,
                                   UNIX_TIMESTAMP(created_at) AS ts
                            FROM notifications WHERE user_id = ?
                              AND (id IN (SELECT id FROM (SELECT id FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?) newest)"
                         . ($withUnread ? " OR read_at IS NULL" : "") . ")
                            ORDER BY created_at DESC, id DESC");
    $stmt->bind_param("iii", $userId, $userId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Mark one notification (or all of them, when $id is null) as read
function sf_mark_notifications_read($conn, $userId, $id = null) {
    if ($id === null) {
        $stmt = $conn->prepare("UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND read_at IS NULL");
        $stmt->bind_param("i", $userId);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND id = ? AND read_at IS NULL");
        $stmt->bind_param("ii", $userId, $id);
    }
    $stmt->execute();
    $stmt->close();
}

// ── Messages ────────────────────────────────────────────────────
function sf_user($conn, $userId) {
    $stmt = $conn->prepare("SELECT id, username, email, role, status, avatar, UNIX_TIMESTAMP(last_seen_at) AS last_seen, show_online FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

// Everyone except the current user (for starting a new chat)
function sf_other_users($conn, $userId) {
    $stmt = $conn->prepare("SELECT id, username, role, status, avatar, UNIX_TIMESTAMP(last_seen_at) AS last_seen, show_online FROM users WHERE id <> ? ORDER BY username");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// One row per chat partner: last message, its time, and how many are unread
function sf_conversations($conn, $userId) {
    $sql = "SELECT u.id, u.username, u.role, u.status, u.avatar, UNIX_TIMESTAMP(u.last_seen_at) AS last_seen, u.show_online, IF(m.unsent_at IS NULL, m.body, '') AS last_body, m.unsent_at IS NOT NULL AS last_unsent, m.attachment <> '' AS last_photo, m.sender_id AS last_sender,
                   UNIX_TIMESTAMP(m.created_at) AS ts,
                   (SELECT COUNT(*) FROM messages x
                     WHERE x.sender_id = u.id AND x.receiver_id = ? AND x.read_at IS NULL) AS unread
            FROM (SELECT IF(sender_id = ?, receiver_id, sender_id) AS partner_id, MAX(id) AS last_id
                    FROM messages WHERE sender_id = ? OR receiver_id = ?
                    GROUP BY partner_id) c
            JOIN messages m ON m.id = c.last_id
            JOIN users u ON u.id = c.partner_id
            ORDER BY m.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Messages between two users, oldest first (only those after $afterId when given)
function sf_thread($conn, $userId, $otherId, $afterId = 0, $limit = 200) {
    $stmt = $conn->prepare("SELECT * FROM (
                                SELECT m.id, m.sender_id, m.body, m.attachment, m.reply_to, m.unsent_at IS NOT NULL AS unsent, UNIX_TIMESTAMP(m.created_at) AS ts
                                FROM messages m
                                WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND m.id > ?
                                  AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
                                ORDER BY m.id DESC LIMIT ?
                            ) t ORDER BY id");
    $stmt->bind_param("iiiiiii", $userId, $otherId, $otherId, $userId, $afterId, $userId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ── Photos in chats (messages.php) ──────────────────────────────
// Payment screenshots and the like can show names and numbers, so they're kept in private/
// (closed to the web) and opened only through message_file.php by the two people in the chat.
const SF_CHAT_DIR       = 'private/chat';
const SF_CHAT_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB

// Saves an uploaded photo. Returns [saved path, null] or [null, error message].
function sf_save_chat_photo(array $file) {
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
        || $file['size'] > SF_CHAT_MAX_BYTES) return [null, 'That photo is over 5 MB.'];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return [null, 'The photo could not be uploaded. Please try again.'];
    $info = @getimagesize($file['tmp_name']);
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($exts[$info['mime']])) return [null, 'Send a JPG, PNG or WebP photo.'];
    $dir = dirname(__DIR__) . '/' . SF_CHAT_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return [null, 'The photo could not be saved. Please try again.'];
    $name = bin2hex(random_bytes(12)) . '.' . $exts[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return [null, 'The photo could not be saved. Please try again.'];
    return [SF_CHAT_DIR . '/' . $name, null];
}

// Full path of a saved chat photo, or null if the stored value isn't one of ours
function sf_chat_photo_path($path) {
    if (!preg_match('#^' . preg_quote(SF_CHAT_DIR, '#') . '/[a-f0-9]{24}\.(jpg|png|webp)$#', (string) $path)) return null;
    $full = dirname(__DIR__) . '/' . $path;
    return is_file($full) ? $full : null;
}

function sf_delete_chat_photo($path) {
    $full = sf_chat_photo_path($path);
    if ($full) @unlink($full);
}

// ── Reactions, replies, unsend, remove for you (messages.php) ────
const SF_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '😡'];

// A message in a chat this user is part of (or null): id, sender_id, receiver_id, body, unsent
function sf_my_message($conn, $userId, $messageId) {
    $stmt = $conn->prepare("SELECT id, sender_id, receiver_id, body, unsent_at IS NOT NULL AS unsent FROM messages
                            WHERE id = ? AND (sender_id = ? OR receiver_id = ?)");
    $stmt->bind_param("iii", $messageId, $userId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Reactions on some messages: [message id => [['emoji' => …, 'count' => n, 'mine' => bool], …]]
function sf_message_reactions($conn, $userId, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $res = $conn->query("SELECT message_id, emoji, COUNT(*) AS n, MAX(user_id = " . (int) $userId . ") AS mine
                         FROM message_reactions WHERE message_id IN (" . implode(',', $ids) . ")
                         GROUP BY message_id, emoji ORDER BY MIN(created_at)");
    $out = [];
    while ($res && $r = $res->fetch_assoc()) {
        $out[(int) $r['message_id']][] = ['emoji' => $r['emoji'], 'count' => (int) $r['n'], 'mine' => (bool) $r['mine']];
    }
    return $out;
}

// Messages as the chat page draws them: text (empty once unsent), the quoted message it
// replies to, and its reactions. $partnerName labels the other person's quotes.
function sf_message_payloads($conn, $userId, array $rows, $partnerName) {
    $replyIds  = array_filter(array_column($rows, 'reply_to'));
    $quotes    = [];
    if ($replyIds) {
        $res = $conn->query("SELECT id, sender_id, body, attachment, unsent_at IS NOT NULL AS unsent FROM messages WHERE id IN (" . implode(',', array_map('intval', $replyIds)) . ")");
        while ($res && $q = $res->fetch_assoc()) $quotes[(int) $q['id']] = $q;
    }
    $reactions = sf_message_reactions($conn, $userId, array_column($rows, 'id'));
    return array_map(function ($m) use ($userId, $quotes, $reactions, $partnerName) {
        $quote = null;
        if ($m['reply_to'] && isset($quotes[(int) $m['reply_to']])) {
            $q = $quotes[(int) $m['reply_to']];
            $quote = ['id' => (int) $q['id'], 'author' => (int) $q['sender_id'] === $userId ? 'You' : $partnerName,
                      'text' => $q['unsent'] ? 'Message unsent' : ($q['body'] !== '' ? sf_excerpt($q['body'], 80) : '📷 Photo'), 'unsent' => (bool) $q['unsent']];
        }
        return [
            'id'        => (int) $m['id'],
            'mine'      => (int) $m['sender_id'] === $userId,
            'body'      => $m['unsent'] ? '' : $m['body'],
            'unsent'    => (bool) $m['unsent'],
            'time'      => sf_message_time($m['ts']),
            'reply'     => $quote,
            // Only the two people in the chat can open it (message_file.php checks)
            'photo'     => !$m['unsent'] && ($m['attachment'] ?? '') !== '' ? 'api/message_file.php?id=' . (int) $m['id'] : null,
            'reactions' => $m['unsent'] ? [] : ($reactions[(int) $m['id']] ?? []),
        ];
    }, $rows);
}

// Opening a chat marks its messages, and the matching alert, as read
function sf_mark_thread_read($conn, $userId, $otherId) {
    $stmt = $conn->prepare("UPDATE messages SET read_at = CURRENT_TIMESTAMP
                            WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL");
    $stmt->bind_param("ii", $otherId, $userId);
    $stmt->execute();
    $stmt->close();

    $link = 'messages.php?with=' . (int) $otherId;
    $stmt = $conn->prepare("UPDATE notifications SET read_at = CURRENT_TIMESTAMP
                            WHERE user_id = ? AND type = 'message' AND link = ? AND read_at IS NULL");
    $stmt->bind_param("is", $userId, $link);
    $stmt->execute();
    $stmt->close();
}

function sf_send_message($conn, $senderId, $receiverId, $text) {
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $senderId, $receiverId, $text);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

// Unread counts for the navbar markers
function sf_unread_counts($conn, $userId) {
    $stmt = $conn->prepare("SELECT
                              (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL) AS notifications,
                              (SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read_at IS NULL) AS messages");
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['notifications' => (int) $row['notifications'], 'messages' => (int) $row['messages']];
}

// For browser pop-ups (Preferences → Push Notifications): whether they're on, and the newest
// unread notification. `ts` changes when a message alert is refreshed, so js/inbox.js uses it
// to tell what's new.
function sf_push_info($conn, $userId) {
    $stmt = $conn->prepare("SELECT notify_push FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $on = (int) ($stmt->get_result()->fetch_assoc()['notify_push'] ?? 0) === 1;
    $stmt->close();
    if (!$on) return ['push' => false];

    $stmt = $conn->prepare("SELECT id, title, body, link, UNIX_TIMESTAMP(created_at) AS ts FROM notifications
                            WHERE user_id = ? AND read_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $latest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($latest) {
        $latest['link'] = sf_safe_link($latest['link']) ?: 'notifications.php';
        $latest['ts']   = (int) $latest['ts'];
    }
    return ['push' => true, 'latest' => $latest, 'now' => time()];
}

// ── Formatting ──────────────────────────────────────────────────
// "Just now", "10m ago", "2h ago", "Yesterday", "3 days ago", "Oct 2"
function sf_time_ago($ts) {
    $ts   = (int) $ts;
    $diff = time() - $ts;
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    $days = (int) ((new DateTime('today'))->diff((new DateTime())->setTimestamp($ts)->setTime(0, 0))->days);
    if ($days === 0)   return floor($diff / 3600) . 'h ago';
    if ($days === 1)   return 'Yesterday';
    if ($days < 7)     return $days . ' days ago';
    return date('M j', $ts) . (date('Y', $ts) === date('Y') ? '' : ', ' . date('Y', $ts));
}

// Time under a chat bubble: "10:15 AM" today, otherwise "Oct 2, 10:15 AM"
function sf_message_time($ts) {
    $ts = (int) $ts;
    return (date('Y-m-d', $ts) === date('Y-m-d') ? '' : date('M j, ', $ts)) . date('g:i A', $ts);
}

function sf_is_today($ts) {
    return date('Y-m-d', (int) $ts) === date('Y-m-d');
}

function sf_excerpt($text, $length = 60) {
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
}

// Only allow links to pages on this site (no "javascript:" or other hosts)
function sf_safe_link($link) {
    // Optional #section at the end, e.g. product_detail.php?id=listing-5#comment-12
    return preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.-]*)?(#[A-Za-z0-9_-]+)?$/', (string) $link) ? $link : '';
}
