<?php
/**
 * Cart Handler (AJAX, JSON)
 *
 * Actions:
 *   - count:         number of items in the cart (for the navbar badge)
 *   - preview:       the newest items in the cart, for the cart icon's drop-down panel (js/cart.js)
 *   - add:           { id, qty, units }  add a catalog item (units = days / hours)
 *   - buy_now:       { id, qty, units }  check out just this item next (the cart is left alone)
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

require_once dirname(__DIR__) . '/includes/db_setup.php';
require_once dirname(__DIR__) . '/includes/shop.php';

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
        $lines[$item['key']] = ['quantity' => $item['quantity'], 'line_total' => sf_peso($item['line_total']),
                               'max_qty' => $item['max_qty'], 'stock_problem' => $item['stock_problem']];
    }
    echo json_encode(array_merge([
        'success' => true,
        'count'   => $totals['count'],
        'totals'  => sf_totals_display($totals),
        'lines'   => (object) $lines,
        'stock_problem' => sf_stock_problems($items),   // checkout is blocked until this is ''
    ], $extra));
    exit;
}

function fail($message, $extra = []) {
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
    exit;
}

// How many of this listing the user already has in the cart
function in_cart($conn, $userId, $key) {
    $stmt = $conn->prepare("SELECT quantity FROM cart_items WHERE user_id = ? AND item_key = ?");
    $stmt->bind_param("is", $userId, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['quantity'] : 0;
}

// Stops products that are out of stock, or a quantity above what's left (checked again at checkout)
function check_stock(array $item, $wanted, $alreadyInCart = 0) {
    if ($item['type'] !== 'products') return;
    $left = (int) $item['stock'];
    if ($left <= 0) fail('Sorry, ' . $item['title'] . ' is out of stock.', ['stock' => 0]);
    if ($alreadyInCart + $wanted > $left) {
        $msg = 'Only ' . $left . ' ' . ($left === 1 ? 'is' : 'are') . ' left in stock';
        if ($alreadyInCart > 0) $msg .= ', and you already have ' . $alreadyInCart . ' in your cart';
        fail($msg . '.', ['stock' => $left]);
    }
}

switch ($action) {

    case 'count':
        echo json_encode(['success' => true, 'count' => sf_cart_count($conn, $userId)]);
        break;

    case 'preview':
        // Newest first, like a shop's "Recently Added Products"
        $items = array_reverse(sf_cart_items($conn, $userId));
        $shown = array_map(fn($i) => [
            'title' => $i['title'], 'image' => $i['image'], 'link' => $i['link'], 'quantity' => $i['quantity'],
            'price' => sf_peso($i['price']) . ($i['unit'] !== '' ? ' / ' . $i['unit'] : ''),
        ], array_slice($items, 0, 5));
        echo json_encode(['success' => true, 'items' => $shown, 'more' => max(0, count($items) - 5), 'count' => sf_cart_count($conn, $userId)]);
        break;

    case 'add':
        $id = (string) ($input['id'] ?? '');
        if (!isset($catalog[$id])) fail('That listing is not available.');
        $item  = $catalog[$id];
        if ($item['seller_id'] === $userId) fail('This is your own listing.');
        if ($item['type'] === 'rentals' && !sf_id_verified($conn, $userId)) fail(SF_VERIFY_MESSAGE, ['verify' => SF_VERIFY_LINK]);
        $qty   = max(1, (int) ($input['qty'] ?? 1));
        if ($qty > SF_MAX_QTY) fail('You can order up to ' . SF_MAX_QTY . ' at a time.');
        $units = (int) ($input['units'] ?? $item['units'][0]);
        if (!in_array($units, $item['units'], true)) fail('Please choose a valid duration.');
        check_stock($item, $qty, in_cart($conn, $userId, $id));
        if ($item['type'] === 'products' && in_cart($conn, $userId, $id) + $qty > SF_MAX_QTY) {
            fail('You can order up to ' . SF_MAX_QTY . ' of one item at a time.');
        }

        // Products: adding again increases the quantity.
        // Rentals / services: adding again updates the days / hours.
        $sql = $item['type'] === 'products'
            ? "INSERT INTO cart_items (user_id, item_key, quantity, units) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), " . sf_max_qty($item) . ")"
            : "INSERT INTO cart_items (user_id, item_key, quantity, units) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE units = VALUES(units)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isii", $userId, $id, $qty, $units);
        $stmt->execute();
        $stmt->close();
        respond($conn, $userId, ['message' => $item['title'] . ' added to your cart.']);
        break;

    case 'buy_now':
        // Remembered for checkout.php, which then sells only this item
        $id = (string) ($input['id'] ?? '');
        if (!isset($catalog[$id])) fail('That listing is not available.');
        $item = $catalog[$id];
        if ($item['seller_id'] === $userId) fail('This is your own listing.');
        if ($item['type'] === 'rentals' && !sf_id_verified($conn, $userId)) fail(SF_VERIFY_MESSAGE, ['verify' => SF_VERIFY_LINK]);
        $qty   = $item['type'] === 'products' ? max(1, (int) ($input['qty'] ?? 1)) : 1;
        if ($qty > SF_MAX_QTY) fail('You can order up to ' . SF_MAX_QTY . ' at a time.');
        $units = (int) ($input['units'] ?? $item['units'][0]);
        if (!in_array($units, $item['units'], true)) fail('Please choose a valid duration.');
        check_stock($item, $qty);
        $_SESSION['buy_now'] = ['key' => $id, 'qty' => $qty, 'units' => $units];
        echo json_encode(['success' => true, 'redirect' => 'checkout.php']);
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
            // Lowering is always allowed (stock may have dropped below what's in the cart); raising must fit the stock
            if ($qty > in_cart($conn, $userId, $key)) check_stock($catalog[$key], $qty);
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
        if (sf_cart_count($conn, $userId) === 0) fail('Your cart is empty.');
        $subtotal = array_sum(array_column(sf_cart_items($conn, $userId), 'line_total'));
        [$promo, $why] = sf_promo_check($conn, $input['code'] ?? '', $userId, $subtotal);
        if (!$promo) fail($why);
        // Free shipping only means something when a product is being shipped
        if ($promo['type'] === 'free_shipping' && !in_array('products', array_column(sf_cart_items($conn, $userId), 'type'), true)) {
            fail('This code gives free shipping, but rentals and services have no shipping fee.');
        }
        $_SESSION['promo_code'] = $promo['code'];
        respond($conn, $userId, ['message' => $promo['code'] . ': ' . sf_promo_label($promo) . ' applied.']);
        break;

    case 'remove_promo':
        unset($_SESSION['promo_code']);
        respond($conn, $userId);
        break;

    default:
        fail('Invalid action.');
}

$conn->close();