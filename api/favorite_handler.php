<?php
/**
 * Favorites Handler (AJAX, JSON)
 *
 * Actions:
 *   - list:    returns the item keys the current user has saved
 *   - add:     saves a listing (body: { action, item: {...} })
 *   - remove:  removes a saved listing (body: { action, key })
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => "Admin accounts can't use this feature."]);
    exit;
}

require_once dirname(__DIR__) . '/includes/db_setup.php';

$userId = (int) $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $input['action'] ?? '';

// ── Helpers ─────────────────────────────────────────────────────
function clean_key($key) {
    $key = strtolower(trim((string) $key));
    return preg_match('/^[a-z0-9:_-]{1,191}$/', $key) ? $key : '';
}

function clean_text($value, $max) {
    $value = trim(strip_tags((string) $value));
    return mb_substr($value, 0, $max);
}

function clean_link($link, $type) {
    $link = trim((string) $link);
    if (preg_match('/^[a-z0-9_]+\.php(\?[a-z0-9_=&%-]*)?$/i', $link)) {
        return $link;
    }
    $defaults = [
        'products' => 'product_detail.php',
        'rentals'  => 'rental_detail.php',
        'services' => 'service_detail.php',
    ];
    return $defaults[$type] ?? 'marketplace.php';
}

// Keeps an https:// image URL, or a seller photo uploaded to this site
// (the browser sends it as a full URL, e.g. http://localhost/station_finds/uploads/listings/ab12….jpg)
function clean_image($image) {
    $image = trim((string) $image);
    if (preg_match('#(?:^|/)(uploads/listings/[a-f0-9]{24}\.(?:jpg|png|webp))$#', $image, $m)) {
        return $m[1];
    }
    if ($image !== '' && strlen($image) <= 500 && preg_match('#^https://#i', $image) && filter_var($image, FILTER_VALIDATE_URL)) {
        return $image;
    }
    return '';
}

function list_keys($conn, $userId) {
    $stmt = $conn->prepare("SELECT item_key FROM favorites WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $keys = [];
    while ($row = $res->fetch_assoc()) {
        $keys[] = $row['item_key'];
    }
    $stmt->close();
    return $keys;
}

// ── Actions ─────────────────────────────────────────────────────
switch ($action) {

    case 'list':
        echo json_encode(['success' => true, 'keys' => list_keys($conn, $userId)]);
        break;

    case 'add':
        $item = is_array($input['item'] ?? null) ? $input['item'] : [];
        $key  = clean_key($item['key'] ?? '');
        $type = in_array($item['type'] ?? '', ['products', 'rentals', 'services'], true) ? $item['type'] : 'products';
        $title = clean_text($item['title'] ?? '', 255);

        if ($key === '' || $title === '') {
            echo json_encode(['success' => false, 'message' => 'Invalid item']);
            break;
        }

        $price        = max(0, (float) ($item['price'] ?? 0));
        $priceDisplay = clean_text($item['price_display'] ?? '', 60);
        $rating       = min(5, max(0, round((float) ($item['rating'] ?? 0), 1)));
        $badgeSub     = clean_text($item['badge_sub'] ?? '', 60);
        $image        = clean_image($item['image'] ?? '');
        $link         = clean_link($item['link'] ?? '', $type);

        $stmt = $conn->prepare(
            "INSERT INTO favorites (user_id, item_key, item_type, title, price, price_display, rating, badge_sub, image, link)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE item_type = VALUES(item_type), title = VALUES(title), price = VALUES(price),
               price_display = VALUES(price_display), rating = VALUES(rating), badge_sub = VALUES(badge_sub),
               image = VALUES(image), link = VALUES(link)"
        );
        $stmt->bind_param("isssdsdsss", $userId, $key, $type, $title, $price, $priceDisplay, $rating, $badgeSub, $image, $link);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => $ok, 'action' => 'add', 'key' => $key]);
        break;

    case 'remove':
        $key = clean_key($input['key'] ?? '');
        if ($key === '') {
            echo json_encode(['success' => false, 'message' => 'Invalid item']);
            break;
        }

        $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND item_key = ?");
        $stmt->bind_param("is", $userId, $key);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => $ok, 'action' => 'remove', 'key' => $key]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

$conn->close();