<?php
/**
 * Shop helpers shared by cart.php, checkout.php, cart_handler.php,
 * the detail pages and the listing pages.
 *
 * Prices always come from the catalog below (never from the browser),
 * so nobody can change what they pay by editing the page.
 * The catalog includes sellers' active listings from the database,
 * so this file connects through db_setup.php ($conn).
 */

require_once __DIR__ . '/db_setup.php';

const SF_SHIPPING_FEE = 120;    // flat fee per order that has products (rentals and services don't ship)
const SF_TAX_RATE     = 0.06;   // "Platform Tax (6%)"
const SF_MAX_QTY      = 10;     // most of one product per order (also capped by its stock)
const SF_MAX_STOCK    = 9999;   // highest stock a seller can enter
const SF_LOW_STOCK    = 3;      // at or below this, sellers see "Low stock" and buyers "Only N left"

const SF_RENTAL_DAYS  = [1, 2, 3, 7, 14, 30];   // rental lengths a renter can pick
const SF_PLACEHOLDER_IMAGE = 'assets/images/logo2.png';

// ── Options for sellers' listings (listing_form.php, become_seller.php) ──
const SF_CONDITIONS = ['brand-new' => 'Brand new', 'like-new' => 'Like new', 'excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair'];
// Service price units and how many a buyer can book
const SF_SERVICE_UNITS = [
    'hr'      => [1, 2, 3, 4, 5, 6, 7, 8],
    'session' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
    'slide'   => [5, 10, 15, 20, 25, 30],
    'shoot'   => [1, 2, 3],
    'job'     => [1],
    'order'   => [1],
];

// Catalog ID of a seller's listing, e.g. "listing-12"
function sf_listing_key($listingId) {
    return 'listing-' . (int) $listingId;
}

// Listing categories from the database (managed in admin_categories.php), per type:
// ['products' => [slug => name, ...], 'rentals' => [...], 'services' => [...]].
// Slugs match the marketplace filter values. $activeOnly hides categories an admin turned off.
function sf_categories($activeOnly = false) {
    global $conn;
    static $cache = [];
    $key = $activeOnly ? 'active' : 'all';
    if (isset($cache[$key])) return $cache[$key];

    $cats = ['products' => [], 'rentals' => [], 'services' => []];
    $res  = $conn->query("SELECT type, slug, name FROM categories" . ($activeOnly ? " WHERE status = 'active'" : "") . " ORDER BY name");
    while ($res && $row = $res->fetch_assoc()) {
        if (isset($cats[$row['type']])) $cats[$row['type']][$row['slug']] = $row['name'];
    }
    // "Other" last
    foreach ($cats as &$list) {
        if (isset($list['other'])) { $other = $list['other']; unset($list['other']); $list['other'] = $other; }
    }
    unset($list);
    return $cache[$key] = $cats;
}

// Every active category label, for the seller application's "Primary category"
function sf_all_categories() {
    $all = [];
    foreach (sf_categories(true) as $cats) $all += $cats;
    return $all;
}

// Active listings from sellers, in the same shape as the catalog entries below
function sf_seller_catalog() {
    global $conn;
    if (!($conn instanceof mysqli)) return [];

    $categories = sf_categories();   // includes turned-off categories, so older listings keep their label

    // Buyer ratings (feedback.php): per listing and per seller; flagged or dismissed reviews don't count
    $ratings = $sellerRatings = [];
    $res = $conn->query("SELECT listing_key, about_user_id, rating FROM feedback
                         WHERE type = 'buyer' AND rating IS NOT NULL AND status NOT IN ('flagged', 'dismissed')");
    $perSeller = [];
    while ($res && $row = $res->fetch_assoc()) {
        $ratings[$row['listing_key']][] = (int) $row['rating'];
        $perSeller[$row['about_user_id']][] = (int) $row['rating'];
    }
    foreach ($ratings as $key => $list) $ratings[$key] = ['avg' => round(array_sum($list) / count($list), 1), 'count' => count($list)];
    foreach ($perSeller as $id => $list) $sellerRatings[$id] = round(array_sum($list) / count($list), 1);

    // Only published listings from approved sellers whose account isn't suspended
    $res = $conn->query("SELECT l.*, u.username, u.avatar AS seller_avatar, UNIX_TIMESTAMP(u.last_seen_at) AS seller_last_seen, u.show_online AS seller_show_online, u.created_at AS joined, s.store_name, s.seller_type
                         FROM listings l
                         JOIN users u ON u.id = l.seller_id
                         JOIN seller_profiles s ON s.user_id = l.seller_id
                         WHERE l.status = 'active' AND u.status = 'active' AND s.status = 'approved'
                         ORDER BY l.id DESC");
    $items = [];
    while ($res && $row = $res->fetch_assoc()) {
        $type = $row['type'];
        if (!isset($categories[$type])) continue;
        $category = $categories[$type][$row['category']] ?? 'Other';
        $photos   = json_decode((string) $row['photos'], true);
        if (!is_array($photos)) $photos = $row['image'] !== '' ? [$row['image']] : [];
        $item = [
            'type'        => $type,
            'title'       => $row['title'],
            'seller'      => $row['store_name'] !== '' ? $row['store_name'] : $row['username'],
            'seller_id'   => (int) $row['seller_id'],
            'seller_meta' => 'Joined ' . date('M Y', strtotime($row['joined'])) . ' · '
                           . ($row['seller_type'] === 'business' ? 'Registered business' : 'Student seller'),
            'term'        => $type === 'services' ? $category : (SF_CONDITIONS[$row['term']] ?? $category),
            'category'    => $row['category'],
            'condition'   => $row['term'],
            'price'       => (float) $row['price'],
            'stock'       => $type === 'products' ? (int) $row['stock'] : null,   // rentals / services aren't counted
            'unit'        => $row['unit'],
            'image'       => $row['image'],
            'description' => $row['description'],
            'location'    => $row['location'],
            'photos'      => $photos,
            'details'     => array_filter(['Category' => $category, 'Location' => $row['location']]),
            'posted'      => $row['created_at'],
            'rating'      => $ratings['listing-' . $row['id']]['avg'] ?? 0,
            'reviews'     => $ratings['listing-' . $row['id']]['count'] ?? 0,
            'seller_rating' => $sellerRatings[$row['seller_id']] ?? 0,
            'seller_avatar' => $row['seller_avatar'],
            'seller_presence' => ['last_seen' => $row['seller_last_seen'], 'show_online' => (int) $row['seller_show_online']],
        ];
        if ($type === 'services') {
            $item['units'] = SF_SERVICE_UNITS[$row['unit']] ?? [1];
        }
        $items[sf_listing_key($row['id'])] = $item;
    }
    return $items;
}

// ── Catalog: every listing that can be added to the cart ────────
// Only listings posted by sellers (see sf_seller_catalog above), so every
// item someone buys has a real seller who can fulfill the order.
// Keys are the listing IDs used in ?id= links and in cart_items.item_key.
//   type   products | rentals | services
//   price  per item (products), per day (rentals), per unit (services)
//   unit   '' for products; 'day' for rentals; 'hr', 'shoot', 'slide',
//          'session', 'job' or 'order' for services
//   units  the allowed number of days / hours / etc.
//   from   true shows the price as "From ₱…"
function sf_catalog() {
    static $catalog = null;
    if ($catalog !== null) return $catalog;

    $catalog = sf_seller_catalog();

    // Fill in the defaults every listing needs
    foreach ($catalog as $id => &$item) {
        $item += ['unit' => '', 'rating' => 0, 'reviews' => 0, 'from' => false, 'specs' => [], 'details' => [],
                  'detail_title' => $item['title'], 'seller_meta' => 'Student seller', 'seller_rating' => 0,
                  'seller_id' => 0, 'description' => '', 'location' => '', 'photos' => [], 'seller_avatar' => '', 'seller_presence' => []];
        if (!isset($item['units'])) {
            $item['units'] = $item['type'] === 'rentals' ? SF_RENTAL_DAYS : [1];
        }
        if (!isset($item['default_units']) || !in_array($item['default_units'], $item['units'], true)) {
            $item['default_units'] = $item['units'][0];
        }
        if ($item['image'] === '') {
            $item['image'] = SF_PLACEHOLDER_IMAGE;
        }
        $item['link'] = sf_item_link($id, $item['type']);
    }
    unset($item);

    return $catalog;
}

// One listing, or null if the ID is unknown (or not of the expected type)
function sf_get_item($id, $type = null) {
    $catalog = sf_catalog();
    $id = (string) $id;
    if (!isset($catalog[$id])) return null;
    if ($type !== null && $catalog[$id]['type'] !== $type) return null;
    return $catalog[$id] + ['id' => $id];
}

// Detail page link for a listing, e.g. "rental_detail.php?id=city-bike-rental"
function sf_item_link($id, $type = null) {
    $pages = ['products' => 'product_detail.php', 'rentals' => 'rental_detail.php', 'services' => 'service_detail.php'];
    if ($type === null) {
        $item = sf_get_item($id);
        $type = $item ? $item['type'] : 'products';
    }
    return ($pages[$type] ?? 'product_detail.php') . '?id=' . rawurlencode($id);
}


// "day" / "days", "hour" / "hours", "slide" / "slides", ...
function sf_unit_word($unit, $n = 1) {
    $words = ['day' => 'day', 'hr' => 'hour', 'shoot' => 'shoot', 'slide' => 'slide',
              'session' => 'session', 'job' => 'job', 'order' => 'order'];
    $word = $words[$unit] ?? $unit;
    return $word . ($n == 1 ? '' : 's');
}

// Price as HTML for the detail pages, e.g. "₱350<span class="price-unit"> / day</span>"
function sf_price_html(array $item) {
    $html = ($item['from'] ? 'From ' : '') . '&#8369;' . number_format($item['price']);
    if ($item['unit'] !== '' && !$item['from']) {
        $html .= '<span class="price-unit"> / ' . htmlspecialchars($item['unit']) . '</span>';
    }
    return $html;
}

// Photos for a detail page: [['main' => url, 'thumb' => url], ...] (the placeholder when there are none)
function sf_gallery(array $item) {
    $photos = $item['photos'] ?: [$item['image']];
    return array_map(fn($p) => ['main' => $p, 'thumb' => $p], $photos);
}

// "Alyssa N." -> "AN"
function sf_initials($name) {
    $letters = '';
    foreach (preg_split('/\s+/', trim($name)) as $part) {
        if ($part !== '') $letters .= strtoupper($part[0]);
    }
    return substr($letters, 0, 2);
}

// ── Profile pictures ────────────────────────────────────────────
// Uploaded on the Account page (Profile), shown in the navbar, on profile.php, listing pages…
const SF_AVATAR_DIR       = 'uploads/avatars';
const SF_AVATAR_MAX_BYTES = 3 * 1024 * 1024;   // 3 MB

// The stored path if it's one of our saved pictures and still there, otherwise ''
function sf_avatar_url($path) {
    $path = (string) $path;
    if (!preg_match('#^' . preg_quote(SF_AVATAR_DIR, '#') . '/[a-f0-9]{24}\.(jpg|png|webp)$#', $path)) return '';
    return is_file(dirname(__DIR__) . '/' . $path) ? $path : '';
}

// What goes inside an avatar circle: the picture, or the person's initials
function sf_avatar_html($name, $avatar) {
    $url = sf_avatar_url($avatar);
    if ($url !== '') return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="" class="avatar-photo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;" />';
    $initials = sf_initials((string) $name);
    return htmlspecialchars($initials !== '' ? $initials : '?', ENT_QUOTES, 'UTF-8');
}

// The signed-in user's navbar avatar (read once per page)
function sf_nav_avatar_html() {
    global $conn;
    static $html = null;
    if ($html === null) {
        $name = $_SESSION['username'] ?? 'User';
        $row  = null;
        if (!empty($_SESSION['user_id']) && $conn) {
            $stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE id = ?");
            $id   = (int) $_SESSION['user_id'];
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $html = sf_avatar_html($row && $row['full_name'] !== '' ? $row['full_name'] : $name, $row['avatar'] ?? '');
    }
    return $html;
}

// ── Online status (green dot) ───────────────────────────────────
// users.last_seen_at is refreshed by db_setup.php while someone uses the site.
const SF_ONLINE_SECONDS = 120;   // seen in the last 2 minutes = online

// $person needs 'last_seen' (unix time or null) and 'show_online' (they can hide it in Preferences)
function sf_is_online(array $person) {
    return !empty($person['show_online']) && !empty($person['last_seen']) && time() - (int) $person['last_seen'] <= SF_ONLINE_SECONDS;
}

// The dot placed on a profile picture (inside an element with class "presence")
function sf_online_dot(array $person) {
    return sf_is_online($person) ? '<span class="online-dot" title="Online" aria-label="Online"></span>' : '';
}

// "Active now", "Active 5m ago", "Active yesterday"… or '' when hidden or never seen
function sf_active_text(array $person) {
    if (empty($person['show_online']) || empty($person['last_seen'])) return '';
    $ago = time() - (int) $person['last_seen'];
    if ($ago <= SF_ONLINE_SECONDS) return 'Active now';
    if ($ago < 3600)  return 'Active ' . floor($ago / 60) . 'm ago';
    if ($ago < 86400) return 'Active ' . floor($ago / 3600) . 'h ago';
    if ($ago < 172800) return 'Active yesterday';
    if ($ago < 7 * 86400) return 'Active ' . floor($ago / 86400) . 'd ago';
    return '';
}

// Saves an uploaded profile picture. Returns [saved path, null] or [null, error message].
function sf_save_avatar(array $file) {
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
        || $file['size'] > SF_AVATAR_MAX_BYTES) return [null, 'That photo is over 3 MB.'];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return [null, 'The photo could not be uploaded. Please try again.'];
    $info = @getimagesize($file['tmp_name']);
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($exts[$info['mime']])) return [null, 'Choose a JPG, PNG or WebP photo.'];
    if ($info[0] < 64 || $info[1] < 64) return [null, 'That photo is too small. Use one at least 64 × 64 pixels.'];

    $dir = dirname(__DIR__) . '/' . SF_AVATAR_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return [null, 'The photo could not be saved. Please try again.'];
    $name = bin2hex(random_bytes(12)) . '.' . $exts[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return [null, 'The photo could not be saved. Please try again.'];
    return [SF_AVATAR_DIR . '/' . $name, null];
}

function sf_delete_avatar($path) {
    $url = sf_avatar_url($path);
    if ($url !== '') @unlink(dirname(__DIR__) . '/' . $url);
}

// ── Promo codes ("Enter student code") ──────────────────────────
// ── Promo codes (managed in admin_promos.php) ───────────────────
const SF_PROMO_TYPES = ['percent' => 'Percent off', 'fixed' => 'Amount off (₱)', 'free_shipping' => 'Free shipping'];

// "10% off", "₱50 off", "Free shipping"
function sf_promo_label(array $promo) {
    if ($promo['type'] === 'free_shipping') return 'Free shipping';
    if ($promo['type'] === 'fixed') return sf_peso($promo['value']) . ' off';
    return rtrim(rtrim(number_format((float) $promo['value'], 2), '0'), '.') . '% off';
}

// Times a code has been used on orders (by everyone, or by one student)
function sf_promo_uses($conn, $code, $userId = null) {
    if ($userId === null) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM orders WHERE promo_code = ?");
        $stmt->bind_param("s", $code);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM orders WHERE promo_code = ? AND user_id = ?");
        $stmt->bind_param("si", $code, $userId);
    }
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

// Can this student use this code on a cart of $subtotal? Returns [promo row, null] or [null, reason]
function sf_promo_check($conn, $code, $userId, $subtotal) {
    $code = strtoupper(trim((string) $code));
    if ($code === '') return [null, 'Please enter a code.'];
    $stmt = $conn->prepare("SELECT * FROM promo_codes WHERE code = ? AND status = 'active'");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $promo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$promo) return [null, 'That code is not valid.'];
    if ($promo['expires_on'] !== null && $promo['expires_on'] < date('Y-m-d')) return [null, 'That code has expired.'];
    if ($promo['max_uses'] !== null && sf_promo_uses($conn, $code) >= (int) $promo['max_uses']) return [null, 'That code has been fully used up.'];
    if ($promo['once_per_user'] && sf_promo_uses($conn, $code, (int) $userId) > 0) return [null, 'You\'ve already used this code.'];
    if ((float) $subtotal < (float) $promo['min_order']) return [null, 'This code needs an order of at least ' . sf_peso($promo['min_order']) . ' (before shipping and tax).'];
    return [$promo, null];
}

function sf_peso($amount) {
    $amount = round((float) $amount, 2);
    return '₱' . number_format($amount, floor($amount) == $amount ? 0 : 2);
}

// Always two decimals, e.g. "₱12,450.00" (payout amounts)
function sf_peso_exact($amount) {
    return '₱' . number_format(round((float) $amount, 2), 2);
}

// "Condition / Term" line shown in the cart and review step
function sf_term_label(array $item) {
    switch ($item['type']) {
        case 'rentals':  return $item['units'] . ' ' . sf_unit_word('day', $item['units']) . ' rental';
        case 'services': return $item['units'] . ' ' . sf_unit_word($item['unit'], $item['units']) . ($item['unit'] === 'hr' ? ' service' : '');
        default:         return $item['term'] ?? '';
    }
}

// Price breakdown shown under a line, e.g. "₱350/day · 3 days"
function sf_price_note(array $item) {
    if ($item['type'] === 'products') {
        return sf_peso($item['price']) . ' · Qty: ' . $item['quantity'];
    }
    return sf_peso($item['price']) . '/' . $item['unit'] . ' · ' . $item['units'] . ' ' . sf_unit_word($item['unit'], $item['units'])
        . ($item['quantity'] > 1 ? ' · Qty: ' . $item['quantity'] : '')
        . ' (' . sf_peso($item['line_total']) . ')';
}

// ── Cart lookups ────────────────────────────────────────────────
function sf_cart_items($conn, $userId) {
    $catalog = sf_catalog();
    $stmt = $conn->prepare("SELECT item_key, quantity, units FROM cart_items WHERE user_id = ? ORDER BY created_at, id");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        if (!isset($catalog[$row['item_key']])) continue;   // listing no longer exists
        $item = $catalog[$row['item_key']];
        $item['key']        = $row['item_key'];
        $item['quantity']   = (int) $row['quantity'];
        $item['units']      = (int) $row['units'];
        $item['line_total'] = $item['price'] * $item['units'] * $item['quantity'];
        $item['term_label'] = sf_term_label($item);
        $item['max_qty']    = sf_max_qty($item);
        $item['stock_problem'] = sf_stock_problem($item);
        $items[] = $item;
    }
    $stmt->close();
    return $items;
}

// "Buy now": the one item remembered for checkout (cart_handler.php › buy_now), in the same
// shape as sf_cart_items(). Empty when there's none or it's no longer for sale.
function sf_buy_now_items($userId) {
    $pick = $_SESSION['buy_now'] ?? null;
    if (!$pick) return [];
    $catalog = sf_catalog();
    if (!isset($catalog[$pick['key']]) || $catalog[$pick['key']]['seller_id'] === (int) $userId) return [];
    $item = $catalog[$pick['key']];
    $item['key']        = $pick['key'];
    $item['quantity']   = (int) $pick['qty'];
    $item['units']      = (int) $pick['units'];
    $item['line_total'] = $item['price'] * $item['units'] * $item['quantity'];
    $item['term_label'] = sf_term_label($item);
    $item['max_qty']    = sf_max_qty($item);
    $item['stock_problem'] = sf_stock_problem($item);
    return [$item];
}

// ── Location filter (Home page, carried on to the marketplace) ──
// Sellers type their own campus / location, so "Quezon city" and "Quezon City " count as one place.
const SF_LOCATION_NONE = 'none';   // ?loc=none: listings without a location

function sf_location_key($location) {
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $location)));
}

// Every location used by a listing: key => label (its most used spelling), A–Z
function sf_location_options(array $catalog) {
    $spellings = [];
    foreach ($catalog as $item) {
        $key = sf_location_key($item['location'] ?? '');
        if ($key === '') continue;
        $label = trim(preg_replace('/\s+/', ' ', $item['location']));
        $spellings[$key][$label] = ($spellings[$key][$label] ?? 0) + 1;
    }
    $options = [];
    foreach ($spellings as $key => $labels) {
        arsort($labels);
        $options[$key] = array_key_first($labels);
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
}

// The ?loc= the visitor picked, if it's a real option ('' = all locations)
function sf_location_param(array $options) {
    $loc = (string) ($_GET['loc'] ?? '');
    return ($loc === SF_LOCATION_NONE || isset($options[$loc])) ? $loc : '';
}

// Listings in that location ('' keeps everything)
function sf_filter_location(array $catalog, $loc) {
    if ($loc === '') return $catalog;
    return array_filter($catalog, fn($item) => $loc === SF_LOCATION_NONE
        ? sf_location_key($item['location'] ?? '') === ''
        : sf_location_key($item['location'] ?? '') === $loc);
}

// "Quezon City", "Location not specified" or "" for all locations
function sf_location_label(array $options, $loc) {
    if ($loc === SF_LOCATION_NONE) return 'Location not specified';
    return $options[$loc] ?? '';
}

// Counts only items still for sale (a listing may have been unpublished or deleted)
function sf_cart_count($conn, $userId) {
    return array_sum(array_column(sf_cart_items($conn, $userId), 'quantity'));
}

// ── Stock (products only; rentals and services aren't counted) ──
// The checks here keep the pages honest; the real guard is sf_reserve_stock() at checkout,
// because stock can change between adding to the cart and placing the order.

// Thrown inside the checkout transaction when an item ran out; the message is shown to the buyer
class SfStockException extends RuntimeException {}

// Most of one listing a buyer can order at once: what's left, never more than SF_MAX_QTY
function sf_max_qty(array $item) {
    return $item['type'] === 'products' ? min(SF_MAX_QTY, max(0, (int) $item['stock'])) : SF_MAX_QTY;
}

// [css class, label] for the availability badge, or null for rentals / services
function sf_stock_status(array $item) {
    if ($item['type'] !== 'products') return null;
    $left = (int) $item['stock'];
    if ($left <= 0)            return ['out', 'Out of stock'];
    if ($left <= SF_LOW_STOCK) return ['low', 'Only ' . $left . ' left'];
    return ['in', 'In stock'];
}

// Why a cart / buy-now line can't be checked out as it is ('' when it can)
function sf_stock_problem(array $item) {
    if ($item['type'] !== 'products') return '';
    $left = (int) $item['stock'];
    if ($left <= 0) return $item['title'] . ' is out of stock. Remove it to continue.';
    if ($item['quantity'] > $left) {
        return 'Only ' . $left . ' of ' . $item['title'] . ' ' . ($left === 1 ? 'is' : 'are') . ' left. Lower the quantity to continue.';
    }
    return '';
}

// The first stock problem in a list of cart lines, or ''
function sf_stock_problems(array $items) {
    foreach ($items as $item) {
        if (!empty($item['stock_problem'])) return $item['stock_problem'];
    }
    return '';
}

// Checkout: take each product's quantity out of stock. Must run inside the order's transaction.
// The UPDATE only matches while enough is left (stock >= qty), and InnoDB locks the row until
// commit / rollback, so when two buyers race for the last one, the second UPDATE waits, then
// matches nothing and throws. Rows are locked in listing-id order so two checkouts can't deadlock.
function sf_reserve_stock($conn, array $items) {
    $products = array_filter($items, fn($it) => $it['type'] === 'products');
    usort($products, fn($a, $b) => (int) substr($a['key'], 8) <=> (int) substr($b['key'], 8));

    $stmt = $conn->prepare("UPDATE listings SET stock = stock - ?
                            WHERE id = ? AND type = 'products' AND status = 'active' AND stock >= ?");
    foreach ($products as $it) {
        $listingId = (int) substr($it['key'], 8);
        $qty       = (int) $it['quantity'];
        $stmt->bind_param("iii", $qty, $listingId, $qty);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            $left = sf_listing_stock($conn, $listingId);
            throw new SfStockException($left > 0
                ? 'Someone just bought some of ' . $it['title'] . '. Only ' . $left . ' ' . ($left === 1 ? 'is' : 'are') . ' left, so please lower the quantity.'
                : 'Sorry, ' . $it['title'] . ' just sold out. Please remove it to continue.');
        }
    }
    $stmt->close();
}

// Current stock of one listing, straight from the database
function sf_listing_stock($conn, $listingId) {
    $stmt = $conn->prepare("SELECT stock FROM listings WHERE id = ?");
    $stmt->bind_param("i", $listingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['stock'] : 0;
}

// ── Totals ──────────────────────────────────────────────────────
function sf_cart_totals(array $items, $promoCode = '') {
    $subtotal = 0;
    $count = 0;
    $ships = false;   // only products are shipped; rentals and services have no shipping fee
    foreach ($items as $item) {
        $subtotal += $item['line_total'];
        $count    += $item['quantity'];
        if ($item['type'] === 'products') $ships = true;
    }

    // The code is checked again every time (it may have expired or run out since it was applied)
    global $conn;
    $promo = null;
    if ($promoCode !== '' && $count > 0) [$promo] = sf_promo_check($conn, $promoCode, (int) ($_SESSION['user_id'] ?? 0), $subtotal);
    $discount = 0;
    if ($promo && $promo['type'] === 'percent') $discount = round($subtotal * (float) $promo['value'] / 100, 2);
    if ($promo && $promo['type'] === 'fixed')   $discount = min((float) $promo['value'], $subtotal);
    $shipping = (!$ships || ($promo && $promo['type'] === 'free_shipping')) ? 0 : SF_SHIPPING_FEE;
    $tax      = round(($subtotal - $discount) * SF_TAX_RATE, 2);
    $total    = $subtotal - $discount + $shipping + $tax;

    return [
        'count'       => $count,
        'subtotal'    => $subtotal,
        'discount'    => $discount,
        'shipping'    => $shipping,
        'ships'       => $ships,         // false: no shipping row at all (rentals / services only)
        'tax'         => $tax,
        'total'       => $total,
        'promo_code'  => $promo ? $promo['code'] : '',
        'promo_label' => $promo ? sf_promo_label($promo) : '',
    ];
}

// Same totals, formatted for JSON responses
function sf_totals_display(array $t) {
    return [
        'count'       => $t['count'],
        'subtotal'    => sf_peso($t['subtotal']),
        'discount'    => $t['discount'] > 0 ? '−' . sf_peso($t['discount']) : '',
        'shipping'    => $t['shipping'] > 0 ? sf_peso($t['shipping']) : 'Free',
        'ships'       => $t['ships'],
        'tax'         => sf_peso($t['tax']),
        'total'       => sf_peso($t['total']),
        'promo_code'  => $t['promo_code'],
        'promo_label' => $t['promo_label'],
    ];
}

// ── Checkout helpers ────────────────────────────────────────────
function sf_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function sf_check_csrf() {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function sf_payment_methods() {
    return [
        'gcash' => ['label' => 'GCash',                  'desc' => 'Pay instantly using your GCash mobile wallet.'],
        'card'  => ['label' => 'Credit or Debit Card',   'desc' => 'Visa, Mastercard, or JCB cards accepted.'],
        'cod'   => ['label' => 'Cash on Delivery (COD)', 'desc' => 'Pay with cash upon physical meetup on campus.'],
    ];
}

// e.g. "GCash Wallet (0917-***-4567)"
function sf_payment_summary($method, $phone) {
    $digits = preg_replace('/\D/', '', $phone);
    if (strpos($digits, '63') === 0) $digits = '0' . substr($digits, 2);
    switch ($method) {
        case 'gcash': return 'GCash Wallet (' . substr($digits, 0, 4) . '-***-' . substr($digits, -4) . ')';
        case 'card':  return 'Credit or Debit Card (charged when the seller confirms)';
        case 'cod':   return 'Cash on Delivery (pay at campus meetup)';
        default:      return '';
    }
}