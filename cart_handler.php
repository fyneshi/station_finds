<?php
/**
 * Cart Handler (AJAX, JSON)
 *
 * Actions:
 *   - count:         number of items in the cart (for the navbar badge)
 *   - add:           { id, qty, units }  add a catalog item (units = days / hours)
 *   - update:        { key, qty }        change quantity
 *   - remove:        { key }             remove a line
 *   - apply_promo:   { code }            apply a promo code
 *   - remove_promo:                      remove the promo code
 * Every response includes the new cart count and formatted totals.
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
require_once 'shop.php';

$userId  = (int) $_SESSION['user_id'];
$catalog = sf_catalog();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $input['action'] ?? '';

// Changes must be JSON requests from this site (browsers block cross-site JSON posts)
if ($action !== 'count' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

function respond($conn, $userId, $extra = []) {
    $items  = sf_cart_items($conn, $userId);
    $totals = sf_cart_totals($items, $_SESSION['promo_code'] ?? '');
    if ($totals['count'] === 0) unset($_SESSION['promo_code']);

    $lines = [];
    foreach ($items as $item) {
        $lines[$item['key']] = ['quantity' => $item['quantity'], 'line_total' => sf_peso($item['line_total'])];
    }
    echo json_encode(array_merge([
        'success' => true,
        'count'   => $totals['count'],
        'totals'  => sf_totals_display($totals),
        'lines'   => (object) $lines,
    ], $extra));
    exit;
}

function fail($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

switch ($action) {

    case 'count':
        echo json_encode(['success' => true, 'count' => sf_cart_count($conn, $userId)]);
        break;

    case 'add':
        $id = (string) ($input['id'] ?? '');
        if (!isset($catalog[$id])) fail('That listing is not available.');
        $item  = $catalog[$id];
        if ($item['seller_id'] === $userId) fail('This is your own listing.');
        $qty   = max(1, min(SF_MAX_QTY, (int) ($input['qty'] ?? 1)));
        $units = (int) ($input['units'] ?? $item['units'][0]);
        if (!in_array($units, $item['units'], true)) fail('Please choose a valid duration.');

        // Products: adding again increases the quantity.
        // Rentals / services: adding again updates the days / hours.
        $sql = $item['type'] === 'products'
            ? "INSERT INTO cart_items (user_id, item_key, quantity, units) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), " . SF_MAX_QTY . ")"
            : "INSERT INTO cart_items (user_id, item_key, quantity, units) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE units = VALUES(units)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isii", $userId, $id, $qty, $units);
        $stmt->execute();
        $stmt->close();
        respond($conn, $userId, ['message' => $item['title'] . ' added to your cart.']);
        break;

    case 'update':
        $key = (string) ($input['key'] ?? '');
        $qty = (int) ($input['qty'] ?? 0);
        if (!isset($catalog[$key])) fail('Item not found.');
        if ($qty < 1) {
            $stmt = $conn->prepare("DELETE FROM cart_items WHERE user_id = ? AND item_key = ?");
            $stmt->bind_param("is", $userId, $key);
        } else {
            $qty = min(SF_MAX_QTY, $qty);
            $stmt = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE user_id = ? AND item_key = ?");
            $stmt->bind_param("iis", $qty, $userId, $key);
        }
        $stmt->execute();
        $stmt->close();
        respond($conn, $userId);
        break;

    case 'remove':
        $key = (string) ($input['key'] ?? '');
        $stmt = $conn->prepare("DELETE FROM cart_items WHERE user_id = ? AND item_key = ?");
        $stmt->bind_param("is", $userId, $key);
        $stmt->execute();
        $stmt->close();
        respond($conn, $userId);
        break;

    case 'apply_promo':
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if ($code === '') fail('Please enter a code.');
        if (!isset(sf_promo_codes()[$code])) fail('That code is not valid.');
        if (sf_cart_count($conn, $userId) === 0) fail('Your cart is empty.');
        $_SESSION['promo_code'] = $code;
        respond($conn, $userId, ['message' => sf_promo_codes()[$code]['label'] . ' applied.']);
        break;

    case 'remove_promo':
        unset($_SESSION['promo_code']);
        respond($conn, $userId);
        break;

    default:
        fail('Invalid action.');
}

$conn->close();