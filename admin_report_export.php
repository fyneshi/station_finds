<?php
/**
 * Downloads a report as a CSV file (opens in Excel / Google Sheets) and logs it
 * in report_exports. Posted from admin_reports.php.
 *
 *   type:   users | listings | transactions | summary
 *   period: 7 | 30 | month | all   (by when the user joined / listing was posted / order was placed)
 */
session_start();

// Admins only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php?mode=login');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/seller.php';
require_once __DIR__ . '/includes/admin_layout.php';   // sf_admin_log
sf_admin_require($conn, 'reports');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !sf_check_csrf()) {
    header('Location: admin_reports.php');
    exit;
}

$types   = ['users' => 'User Report', 'listings' => 'Listing Report', 'transactions' => 'Transaction Report', 'summary' => 'Marketplace Summary'];
$periods = ['7' => 'Last 7 days', '30' => 'Last 30 days', 'month' => date('F Y'), 'all' => 'All time'];
$type    = isset($types[$_POST['type'] ?? '']) ? $_POST['type'] : 'users';
$period  = isset($periods[$_POST['period'] ?? '']) ? $_POST['period'] : '30';

// SQL condition for the chosen period on a date column
function period_sql($column, $period) {
    switch ($period) {
        case '7':     return "$column >= NOW() - INTERVAL 7 DAY";
        case '30':    return "$column >= NOW() - INTERVAL 30 DAY";
        case 'month': return "$column >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        default:      return "1 = 1";
    }
}

$rows = [];
if ($type === 'users') {
    $rows[] = ['User ID', 'Username', 'Full name', 'Email', 'Role', 'Account status', 'Joined', 'Seller store', 'Seller status', 'Orders placed', 'Listings'];
    $res = $conn->query("SELECT u.id, u.username, u.full_name, u.email, u.role, u.status, u.created_at, s.store_name, s.status AS seller_status,
                                (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS orders,
                                (SELECT COUNT(*) FROM listings l WHERE l.seller_id = u.id) AS listings
                         FROM users u LEFT JOIN seller_profiles s ON s.user_id = u.id
                         WHERE " . period_sql('u.created_at', $period) . " ORDER BY u.created_at DESC");
    while ($r = $res->fetch_assoc()) {
        $rows[] = [$r['id'], $r['username'], $r['full_name'], $r['email'], $r['role'], $r['status'], $r['created_at'],
                   $r['store_name'] ?? '', $r['seller_status'] ?? '', $r['orders'], $r['listings']];
    }
} elseif ($type === 'listings') {
    $categories = [];
    foreach (sf_categories() as $t => $cats) foreach ($cats as $slug => $name) $categories[$t . '|' . $slug] = $name;
    $rows[] = ['Listing ID', 'Title', 'Type', 'Category', 'Price', 'Per', 'Status', 'Seller', 'Posted', 'Orders', 'Avg rating', 'Reports'];
    $res = $conn->query("SELECT l.*, COALESCE(s.store_name, u.username) AS seller,
                                (SELECT COUNT(*) FROM order_items oi WHERE oi.item_key = CONCAT('listing-', l.id)) AS orders,
                                (SELECT ROUND(AVG(f.rating), 1) FROM feedback f WHERE f.listing_key = CONCAT('listing-', l.id) AND f.type = 'buyer' AND f.status NOT IN ('flagged', 'dismissed')) AS rating,
                                (SELECT COUNT(*) FROM reports r WHERE r.target_type = 'listing' AND r.target_id = l.id) AS reports
                         FROM listings l JOIN users u ON u.id = l.seller_id LEFT JOIN seller_profiles s ON s.user_id = l.seller_id
                         WHERE " . period_sql('l.created_at', $period) . " ORDER BY l.created_at DESC");
    while ($r = $res->fetch_assoc()) {
        $rows[] = [$r['id'], $r['title'], $r['type'], $categories[$r['type'] . '|' . $r['category']] ?? $r['category'], $r['price'],
                   $r['unit'], $r['status'], $r['seller'], $r['created_at'], $r['orders'], $r['rating'] ?? '', $r['reports']];
    }
} elseif ($type === 'transactions') {
    $methods = sf_payment_methods();
    $rows[] = ['Order', 'Placed', 'Buyer', 'Email', 'Payment', 'Items', 'Subtotal', 'Discount', 'Shipping', 'Tax', 'Total', 'Status', 'Open disputes', 'Refunded items', 'Cancelled items', 'Cancel reasons'];
    $res = $conn->query("SELECT o.*, u.email,
                                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS items,
                                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id AND oi.dispute_status = 'open') AS disputes,
                                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id AND oi.fulfillment = 'refunded') AS refunded,
                                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id AND oi.fulfillment = 'cancelled') AS cancelled,
                                (SELECT GROUP_CONCAT(DISTINCT oi.cancel_reason SEPARATOR '; ') FROM order_items oi WHERE oi.order_id = o.id AND oi.fulfillment = 'cancelled') AS cancel_reasons
                         FROM orders o JOIN users u ON u.id = o.user_id
                         WHERE " . period_sql('o.created_at', $period) . " ORDER BY o.created_at DESC");
    $items = [];
    $ir = $conn->query("SELECT order_id, item_type, fulfillment FROM order_items");
    while ($i = $ir->fetch_assoc()) $items[$i['order_id']][] = $i;
    while ($r = $res->fetch_assoc()) {
        $mine = $items[$r['id']] ?? [];
        $done = array_filter($mine, fn($i) => sf_item_closed($i['fulfillment']) || sf_fulfillment_is_final($i['item_type'], $i['fulfillment']));
        $status = $r['disputes'] > 0 ? 'Disputed' : ($mine && (int) $r['cancelled'] === count($mine) ? 'Cancelled'
                 : ($mine && count($done) === count($mine) ? 'Completed' : 'Processing'));
        $rows[] = [$r['order_no'], $r['created_at'], $r['full_name'], $r['email'], $methods[$r['payment_method']]['label'] ?? $r['payment_method'],
                   $r['items'], $r['subtotal'], $r['discount'], $r['shipping'], $r['tax'], $r['total'], $status, $r['disputes'], $r['refunded'],
                   $r['cancelled'], $r['cancel_reasons'] ?? ''];
    }
} else {
    $m = $conn->query("SELECT
        (SELECT COUNT(*) FROM users WHERE role <> 'admin' AND " . period_sql('created_at', $period) . ") AS new_users,
        (SELECT COUNT(*) FROM users WHERE role <> 'admin') AS users,
        (SELECT COUNT(*) FROM users WHERE status = 'suspended') AS suspended,
        (SELECT COUNT(*) FROM seller_profiles WHERE status = 'approved') AS sellers,
        (SELECT COUNT(*) FROM listings WHERE " . period_sql('created_at', $period) . ") AS new_listings,
        (SELECT COUNT(*) FROM listings WHERE status = 'active') AS live_listings,
        (SELECT COUNT(*) FROM listings WHERE status = 'flagged') AS flagged_listings,
        (SELECT COUNT(*) FROM orders WHERE " . period_sql('created_at', $period) . ") AS orders,
        " . sf_net_sales_sql(period_sql('o.created_at', $period)) . " AS sales,
        (SELECT COUNT(*) FROM order_items WHERE dispute_status = 'open') AS disputes,
        (SELECT COUNT(*) FROM order_items WHERE fulfillment = 'cancelled' AND " . period_sql('cancelled_at', $period) . ") AS cancelled,
        (SELECT COALESCE(SUM(line_total), 0) FROM order_items WHERE fulfillment = 'cancelled' AND " . period_sql('cancelled_at', $period) . ") AS cancelled_value,
        (SELECT COALESCE(SUM(amount), 0) FROM payout_requests WHERE status = 'paid' AND " . period_sql('processed_at', $period) . ") AS paid_out,
        (SELECT COUNT(*) FROM reports WHERE status IN ('open', 'escalated')) AS open_reports,
        (SELECT ROUND(AVG(rating), 1) FROM feedback WHERE rating IS NOT NULL AND status NOT IN ('flagged', 'dismissed')) AS avg_rating")->fetch_assoc();
    $rows = [['Metric', 'Value'],
        ['Period', $periods[$period]],
        ['New users in period', $m['new_users']], ['Total users', $m['users']], ['Suspended users', $m['suspended']],
        ['Approved sellers', $m['sellers']], ['New listings in period', $m['new_listings']], ['Live listings', $m['live_listings']],
        ['Flagged listings', $m['flagged_listings']], ['Orders in period', $m['orders']], ['Sales in period, after cancellations and refunds (PHP)', $m['sales']],
        ['Items cancelled by buyers in period', $m['cancelled']], ['Value of cancelled items in period (PHP)', $m['cancelled_value']],
        ['Open disputes', $m['disputes']], ['Paid out to sellers in period (PHP)', $m['paid_out']],
        ['Open reports', $m['open_reports']], ['Average rating', $m['avg_rating'] ?? '']];
}

// Log it
$name     = $types[$type] . ' — ' . $periods[$period];
$adminId  = (int) $_SESSION['user_id'];
$adminNm  = $_SESSION['username'] ?? 'Admin';
$dataRows = count($rows) - 1;
$stmt = $conn->prepare("INSERT INTO report_exports (admin_id, admin_name, report_type, name, period, row_count) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("issssi", $adminId, $adminNm, $type, $name, $period, $dataRows);
$stmt->execute();
$stmt->close();
sf_admin_log($conn, 'export', 'Exported report', $name, 'csv');

// Send the file. Cells starting with = + - @ are prefixed so spreadsheets don't run them as formulas.
$file = 'station-finds-' . $type . '-' . ($period === 'all' ? 'all-time' : ($period === 'month' ? date('Y-m') : 'last-' . $period . '-days')) . '-' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // so Excel reads ₱ and accents correctly
foreach ($rows as $row) {
    fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $row));
}
fclose($out);
