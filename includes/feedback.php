<?php
/**
 * Feedback and ratings: the Contact us form (contact.php), buyers rating finished
 * orders and sellers rating buyers (account.php), and the admin Feedback page
 * (admin_feedback.php). Needs db_setup.php, inbox.php and seller.php included first.
 */

const SF_FEEDBACK_TYPES = [
    'buyer'    => 'Buyer feedback',
    'seller'   => 'Seller feedback',
    'platform' => 'To the platform',
];

// Feedback with these statuses is hidden from public ratings
const SF_FEEDBACK_HIDDEN = ['flagged', 'dismissed'];

// "★★★★☆" as HTML (filled stars are wrapped so they can be coloured)
function sf_stars_html($rating) {
    if (!$rating) return '<span class="stars none">No rating</span>';
    $rating = max(1, min(5, (int) $rating));
    return '<span class="stars" aria-label="' . $rating . ' out of 5 stars"><span class="on">' . str_repeat('★', $rating) . '</span>'
         . str_repeat('☆', 5 - $rating) . '</span>';
}

// "#0042"
function sf_feedback_no($id) {
    return '#' . str_pad((string) (int) $id, 4, '0', STR_PAD_LEFT);
}

// Average buyer rating and review count for every listing: ['listing-12' => ['avg' => 4.5, 'count' => 2], ...]
function sf_listing_ratings($conn) {
    static $ratings = null;
    if ($ratings !== null) return $ratings;
    $ratings = [];
    $res = $conn->query("SELECT listing_key, AVG(rating) AS avg, COUNT(*) AS n FROM feedback
                         WHERE type = 'buyer' AND rating IS NOT NULL AND listing_key IS NOT NULL
                           AND status NOT IN ('flagged', 'dismissed')
                         GROUP BY listing_key");
    while ($res && $row = $res->fetch_assoc()) {
        $ratings[$row['listing_key']] = ['avg' => round((float) $row['avg'], 1), 'count' => (int) $row['n']];
    }
    return $ratings;
}

// A seller's average rating across all their listings: ['avg' => 4.6, 'count' => 5]
function sf_seller_rating($conn, $sellerId) {
    $stmt = $conn->prepare("SELECT AVG(rating) AS avg, COUNT(*) AS n FROM feedback
                            WHERE type = 'buyer' AND about_user_id = ? AND rating IS NOT NULL AND status NOT IN ('flagged', 'dismissed')");
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['avg' => $row['n'] ? round((float) $row['avg'], 1) : 0, 'count' => (int) $row['n']];
}

// Order items this user has already rated, as a set: [order_item_id => rating]
function sf_rated_items($conn, $userId, $type) {
    $stmt = $conn->prepare("SELECT order_item_id, rating FROM feedback WHERE user_id = ? AND type = ? AND order_item_id IS NOT NULL");
    $stmt->bind_param("is", $userId, $type);
    $stmt->execute();
    $rated = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $rated[(int) $row['order_item_id']] = (int) $row['rating'];
    $stmt->close();
    return $rated;
}

// Rate a finished order item. $type 'buyer' = the buyer rates the seller's listing,
// 'seller' = the seller rates the buyer. Returns null on success or an error message.
function sf_rate_order_item($conn, array $user, $type, $orderItemId, $rating, $comment) {
    $rating  = (int) $rating;
    $comment = trim(str_replace("\r\n", "\n", (string) $comment));
    if ($rating < 1 || $rating > 5) return 'Please choose 1 to 5 stars.';
    if (mb_strlen($comment) > 1000) return 'Please keep your review under 1,000 characters.';

    // The order item, its buyer, and the seller of the listing
    $stmt = $conn->prepare("SELECT oi.id, oi.item_key, oi.item_type, oi.title, oi.fulfillment, o.user_id AS buyer_id, o.order_no,
                                   l.seller_id
                            FROM order_items oi
                            JOIN orders o ON o.id = oi.order_id
                            LEFT JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE oi.id = ?");
    $stmt->bind_param("i", $orderItemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $mine = $item && ($type === 'buyer' ? (int) $item['buyer_id'] === (int) $user['id'] : (int) $item['seller_id'] === (int) $user['id']);
    if (!$mine) return 'That order could not be found.';
    if (!sf_fulfillment_is_final($item['item_type'], $item['fulfillment'])) return 'You can leave a rating once the order is finished.';

    $aboutUser = $type === 'buyer' ? (int) $item['seller_id'] : (int) $item['buyer_id'];
    $subject   = $type === 'buyer' ? 'Review of ' . $item['title'] : 'Buyer on order #' . $item['order_no'];
    $listing   = $type === 'buyer' ? $item['item_key'] : null;
    $name      = $user['full_name'] !== '' ? $user['full_name'] : $user['username'];
    $subject   = mb_substr($subject, 0, 120);

    $stmt = $conn->prepare("INSERT INTO feedback (user_id, name, email, type, subject, message, rating, order_item_id, listing_key, about_user_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssiisi", $user['id'], $name, $user['email'], $type, $subject, $comment, $rating, $item['id'], $listing, $aboutUser);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $ex) {
        $stmt->close();
        return 'You\'ve already rated this order.';
    }
    $stmt->close();

    if ($aboutUser) {
        sf_notify($conn, $aboutUser, 'info', $type === 'buyer' ? 'New review: ' . $rating . '★' : 'A seller rated you ' . $rating . '★',
            $type === 'buyer' ? $name . ' rated ' . $item['title'] . '.' : 'For your order #' . $item['order_no'] . ' (' . $item['title'] . ').',
            $type === 'buyer' ? 'account.php?tab=seller' : 'account.php');
    }
    return null;
}
