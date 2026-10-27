<?php
/**
 * Account, seller and order-tracking helpers shared by account.php,
 * become_seller.php, listing_form.php and checkout.php.
 * Needs shop.php and inbox.php included first.
 */

const SF_UPLOAD_DIR       = 'uploads/listings';
const SF_UPLOAD_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB per photo
const SF_MAX_PHOTOS       = 6;                 // photos per listing

// ── Order tracking ──────────────────────────────────────────────
// For each listing type: status => [label shown to buyer & seller, next status, seller's button text]
const SF_FULFILLMENT = [
    'products' => [
        'pending'   => ['Processing', 'shipped',   'Mark as shipped'],
        'shipped'   => ['In transit', 'delivered', 'Mark as delivered'],
        'delivered' => ['Delivered',  null,        null],
    ],
    'rentals' => [
        'pending'  => ['Upcoming', 'active',   'Mark as picked up'],
        'active'   => ['Active',   'returned', 'Mark as returned'],
        'returned' => ['Returned', null,       null],
    ],
    'services' => [
        'pending'     => ['Pending',     'in_progress', 'Start service'],
        'in_progress' => ['In progress', 'completed',   'Mark as completed'],
        'completed'   => ['Completed',   null,          null],
    ],
];

// What the buyer is told when the seller moves an item to a new status
const SF_FULFILLMENT_NOTICES = [
    'shipped'     => ['Order shipped',     'Your order #%s (%s) has been shipped by %s.'],
    'delivered'   => ['Order delivered',   'Your order #%s (%s) was marked as delivered by %s.'],
    'active'      => ['Rental started',    'Your rental of %2$s (order #%1$s) has started. Enjoy, and return it to %3$s on time.'],
    'returned'    => ['Rental returned',   '%3$s marked your rental of %2$s (order #%1$s) as returned.'],
    'in_progress' => ['Service started',   '%3$s has started working on %2$s (order #%1$s).'],
    'completed'   => ['Service completed', '%3$s marked %2$s (order #%1$s) as completed.'],
];

function sf_fulfillment_label($type, $status) {
    return SF_FULFILLMENT[$type][$status][0] ?? ucfirst(str_replace('_', ' ', $status));
}

function sf_fulfillment_is_final($type, $status) {
    return isset(SF_FULFILLMENT[$type][$status]) && SF_FULFILLMENT[$type][$status][1] === null;
}

// 'refunded' (an admin refunded a dispute) and 'cancelled' (the buyer cancelled before the seller
// started) sit outside the steps above: the item is finished but never counts as a sale, so
// sf_fulfillment_is_final() is false for them.
function sf_item_closed($status) {
    return $status === 'refunded' || $status === 'cancelled';
}

// CSS modifier for the status badge.
function sf_status_class($type, $status) {
    if ($status === 'cancelled') return 'cancelled';
    if ($status === 'refunded' || sf_fulfillment_is_final($type, $status)) return 'done';
    return $status === 'pending' ? 'pending' : 'active';
}

// ── Profiles ────────────────────────────────────────────────────
function sf_account_user($conn, $userId) {
    $stmt = $conn->prepare("SELECT id, username, full_name, email, password, role, notify_messages, notify_orders,
                                   notify_push, show_online, language, avatar, bio, created_at,
                                   id_status, id_type, id_front, id_selfie, id_note, id_submitted_at
                            FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function sf_display_name(array $user) {
    return $user['full_name'] !== '' ? $user['full_name'] : $user['username'];
}

function sf_seller_profile($conn, $userId) {
    $stmt = $conn->prepare("SELECT * FROM seller_profiles WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Where payouts go, for display: [badge, title, masked account]
// e.g. ['GCash', 'GCash Account', '+63 917 ••• •321'] or ['BPI', 'BPI Account', '•••• 6789']
function sf_payout_destination(array $profile) {
    $acct = $profile['payout_account'];
    if ($profile['payout_method'] === 'gcash') {
        return ['GCash', 'GCash Account', '+63 ' . substr($acct, 1, 3) . ' ••• •' . substr($acct, -3)];
    }
    return [$profile['payout_bank'], $profile['payout_bank'] . ' Account', '•••• ' . substr($acct, -4)];
}

// ── Payouts ─────────────────────────────────────────────────────
const SF_PAYOUT_MIN = 100;   // smallest amount a seller can withdraw (₱)

// Request statuses: label, badge style, and what it means for the seller
const SF_PAYOUT_STATUS = [
    'pending'  => ['Pending',  'pending', 'Awaiting admin review'],
    'flagged'  => ['Flagged',  'flagged', 'Held for review'],
    'paid'     => ['Paid',     'live',    'Sent to %s'],
    'rejected' => ['Rejected', 'draft',   'Returned to your balance'],
];

// "TXN-0042"
function sf_txn_no($requestId) {
    return 'TXN-' . str_pad((string) (int) $requestId, 4, '0', STR_PAD_LEFT);
}

// Released earnings (finished orders) minus paid, pending and flagged requests
function sf_payout_balance($conn, $sellerId) {
    $released = 0;
    foreach (sf_seller_sales($conn, $sellerId) as $s) {
        if (sf_fulfillment_is_final($s['item_type'], $s['fulfillment'])) $released += (float) $s['line_total'];
    }
    $stmt = $conn->prepare("SELECT
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0) AS paid,
        COALESCE(SUM(CASE WHEN status IN ('pending', 'flagged') THEN amount END), 0) AS reserved
        FROM payout_requests WHERE seller_id = ?");
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $paid     = (float) $row['paid'];
    $reserved = (float) $row['reserved'];
    return [
        'released'  => $released,
        'paid'      => $paid,
        'reserved'  => $reserved,
        'available' => max(0, round($released - $paid - $reserved, 2)),
    ];
}

// Withdrawals are paid to GCash only, so only GCash withdrawals are listed
function sf_payout_requests($conn, $sellerId) {
    $stmt = $conn->prepare("SELECT *, UNIX_TIMESTAMP(created_at) AS ts FROM payout_requests
                            WHERE seller_id = ? AND method = 'gcash' ORDER BY created_at DESC, id DESC");
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Create a payout request. Returns [request id, error message].
// The seller's profile row is locked so two quick requests can't both spend the same balance.
function sf_request_payout($conn, $sellerId, $amount) {
    $amount = round((float) $amount, 2);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM seller_profiles WHERE user_id = ? FOR UPDATE");
        $stmt->bind_param("i", $sellerId);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$profile || $profile['status'] !== 'approved') throw new RuntimeException('Your seller account needs to be approved first.');
        // Withdrawals go to GCash only
        if ($profile['payout_method'] !== 'gcash' || !preg_match('/^09\d{9}$/', $profile['payout_account'])) {
            throw new RuntimeException('Withdrawals are sent to GCash only. Add your GCash number in your seller details first.');
        }

        $balance = sf_payout_balance($conn, $sellerId);
        if ($amount < SF_PAYOUT_MIN) throw new RuntimeException('The minimum payout is ' . sf_peso(SF_PAYOUT_MIN) . '.');
        if ($amount > $balance['available']) throw new RuntimeException('You can withdraw up to ' . sf_peso($balance['available']) . '.');

        [$badge, $title, $masked] = sf_payout_destination($profile);
        $destination = $title . ' ' . $masked;
        $stmt = $conn->prepare("INSERT INTO payout_requests (seller_id, amount, method, destination) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("idss", $sellerId, $amount, $profile['payout_method'], $destination);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        $conn->commit();
    } catch (RuntimeException $ex) {
        $conn->rollback();
        return [null, $ex->getMessage()];
    }

    return [$id, null];
}

// Admin decision on a request: paid | flagged | rejected. Returns an error message or null.
function sf_decide_payout($conn, $requestId, $decision, $note) {
    $allowed = ['pending' => ['paid', 'flagged', 'rejected'], 'flagged' => ['paid', 'rejected']];
    $stmt = $conn->prepare("SELECT * FROM payout_requests WHERE id = ?");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$req) return 'That request could not be found.';
    if (!in_array($decision, $allowed[$req['status']] ?? [], true)) return 'That request has already been handled.';
    if ($decision === 'rejected' && $note === '') return 'Please add a reason when rejecting a request.';

    $stmt = $conn->prepare("UPDATE payout_requests SET status = ?, admin_note = ?,
                            processed_at = IF(? IN ('paid', 'rejected'), CURRENT_TIMESTAMP, NULL)
                            WHERE id = ? AND status = ?");
    $stmt->bind_param("sssis", $decision, $note, $decision, $requestId, $req['status']);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$changed) return 'That request has already been handled.';

    $txn    = sf_txn_no($requestId);
    $amount = sf_peso($req['amount']);
    $reason = $note !== '' ? ' Note from admin: ' . $note : '';
    $notices = [
        'paid'     => ['Payout Processed', $amount . ' has been sent to your ' . $req['destination'] . ' (' . $txn . ').'],
        'flagged'  => ['Payout on hold', 'Your payout request ' . $txn . ' for ' . $amount . ' is being reviewed. The amount stays reserved until it is resolved.' . $reason],
        'rejected' => ['Payout request rejected', 'Your payout request ' . $txn . ' for ' . $amount . ' was rejected and the amount is back in your available balance.' . $reason],
    ];
    sf_notify($conn, (int) $req['seller_id'], 'payout', $notices[$decision][0], $notices[$decision][1], 'account.php?tab=payouts');
    return null;
}

// ── Listings ────────────────────────────────────────────────────
function sf_my_listings($conn, $sellerId) {
    $stmt = $conn->prepare("SELECT * FROM listings WHERE seller_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function sf_my_listing($conn, $sellerId, $listingId) {
    $stmt = $conn->prepare("SELECT * FROM listings WHERE id = ? AND seller_id = ?");
    $stmt->bind_param("ii", $listingId, $sellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// "₱450/hr", "₱350/day", "₱1,100"
function sf_listing_price(array $listing) {
    return sf_peso($listing['price']) . ($listing['unit'] !== '' ? '/' . $listing['unit'] : '');
}

function sf_listing_image(array $listing) {
    return $listing['image'] !== '' ? $listing['image'] : SF_PLACEHOLDER_IMAGE;
}

// Every photo of a listing row, cover first (older listings only have `image`)
function sf_listing_photos(array $listing) {
    $photos = json_decode((string) ($listing['photos'] ?? ''), true);
    if (!is_array($photos)) $photos = $listing['image'] !== '' ? [$listing['image']] : [];
    return array_values(array_filter($photos, 'is_string'));
}

// ── Orders: seller side ─────────────────────────────────────────
// Every item sold from this seller's listings, newest first
function sf_seller_sales($conn, $sellerId) {
    $stmt = $conn->prepare("SELECT oi.id, oi.item_key, oi.item_type, oi.title, oi.units, oi.quantity, oi.line_total,
                                   oi.fulfillment, oi.dispute_status, oi.dispute_outcome, oi.dispute_reason,
                                   oi.return_status, oi.return_reason, oi.return_details, oi.return_photos, oi.return_response,
                                   o.order_no, o.user_id AS buyer_id, o.full_name AS buyer_name,
                                   UNIX_TIMESTAMP(o.created_at) AS ts
                            FROM order_items oi
                            JOIN orders o   ON o.id = oi.order_id
                            JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE l.seller_id = ?
                            ORDER BY o.created_at DESC, oi.id DESC");
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Move one sold item to its next status and tell the buyer. Returns the new status or null.
function sf_advance_fulfillment($conn, $sellerId, $orderItemId, $sellerName) {
    $stmt = $conn->prepare("SELECT oi.id, oi.item_type, oi.title, oi.fulfillment, oi.dispute_status, oi.return_status, o.order_no, o.user_id AS buyer_id
                            FROM order_items oi
                            JOIN orders o   ON o.id = oi.order_id
                            JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE oi.id = ? AND l.seller_id = ?");
    $stmt->bind_param("ii", $orderItemId, $sellerId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    // Wait for an admin to settle a dispute, or for the owner to answer a return / refund request
    if (!$item || $item['dispute_status'] === 'open' || $item['return_status'] === 'requested') return null;

    $next = SF_FULFILLMENT[$item['item_type']][$item['fulfillment']][1] ?? null;
    if ($next === null) return null;

    $stmt = $conn->prepare("UPDATE order_items SET fulfillment = ? WHERE id = ? AND fulfillment = ?");
    $stmt->bind_param("sis", $next, $item['id'], $item['fulfillment']);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$changed) return null;

    [$title, $body] = SF_FULFILLMENT_NOTICES[$next];
    sf_notify($conn, (int) $item['buyer_id'], 'order', $title,
        sprintf($body, $item['order_no'], $item['title'], $sellerName), 'account.php');
    return $next;
}

// ── Cancelling (buyer) ──────────────────────────────────────────
// A buyer can cancel an item only while it's still pending: not shipped, not picked up,
// not started. After that, "Report a problem" (a dispute) is the way.
const SF_CANCEL_REASONS = ['Changed my mind', 'Ordered by mistake', 'Found it somewhere else', 'Seller isn\'t responding', 'Wrong dates or details', 'Other'];

// Returns an error message, or null when it was cancelled
function sf_cancel_order_item($conn, $buyerId, $orderItemId, $reason) {
    if (!in_array($reason, SF_CANCEL_REASONS, true)) return 'Please choose why you\'re cancelling.';
    $stmt = $conn->prepare("SELECT oi.id, oi.title, oi.item_type, oi.fulfillment, oi.dispute_status, oi.line_total, o.order_no, o.payment_method, l.seller_id
                            FROM order_items oi JOIN orders o ON o.id = oi.order_id
                            LEFT JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE oi.id = ? AND o.user_id = ?");
    $stmt->bind_param("ii", $orderItemId, $buyerId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item) return 'That order could not be found.';
    if ($item['fulfillment'] === 'cancelled') return 'This item is already cancelled.';
    if ($item['dispute_status'] === 'open') return 'An admin is reviewing a problem with this item, so it can\'t be cancelled now.';
    if ($item['fulfillment'] !== 'pending') {
        return [
            'products' => 'This item has already been shipped, so it can\'t be cancelled. Use "Report a problem" instead.',
            'rentals'  => 'This rental has already started, so it can\'t be cancelled. Use "Report a problem" instead.',
            'services' => 'The provider has already started, so it can\'t be cancelled. Use "Report a problem" instead.',
        ][$item['item_type']] ?? 'This item can\'t be cancelled any more.';
    }

    // Only if it's still pending (the seller may have just updated it). Stock taken at checkout goes back
    // in the same transaction; the row lock and stock_deducted = 1 check make sure it's returned only once.
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT item_key, quantity, stock_deducted FROM order_items WHERE id = ? AND fulfillment = 'pending' FOR UPDATE");
        $stmt->bind_param("i", $item['id']);
        $stmt->execute();
        $locked = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$locked) {
            $conn->rollback();
            return 'The seller just updated this item, so it can\'t be cancelled now. Please refresh.';
        }
        $stmt = $conn->prepare("UPDATE order_items SET fulfillment = 'cancelled', cancel_reason = ?, cancelled_at = NOW(), stock_deducted = 0
                                WHERE id = ? AND fulfillment = 'pending'");
        $stmt->bind_param("si", $reason, $item['id']);
        $stmt->execute();
        $stmt->close();
        if ((int) $locked['stock_deducted'] === 1) {
            $listingId = (int) substr($locked['item_key'], 8);
            $qty       = (int) $locked['quantity'];
            $stmt = $conn->prepare("UPDATE listings SET stock = LEAST(stock + ?, " . SF_MAX_STOCK . ") WHERE id = ? AND type = 'products'");
            $stmt->bind_param("ii", $qty, $listingId);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        return 'This item could not be cancelled. Please try again.';
    }

    $what = '"' . $item['title'] . '" (order #' . $item['order_no'] . ')';
    if ($item['seller_id']) {
        sf_notify($conn, (int) $item['seller_id'], 'order', 'Order cancelled by the buyer',
            $what . ' was cancelled. Reason: ' . $reason . '. You don\'t need to do anything.', 'account.php?tab=seller');
    }
    $paidOnline = $item['payment_method'] !== 'cod';
    sf_notify($conn, (int) $buyerId, 'order', 'Order cancelled',
        'You cancelled ' . $what . '.' . ($paidOnline ? ' Your ' . sf_peso($item['line_total']) . ' will be refunded the way you paid within 5–7 business days.' : ''), 'account.php');
    return null;
}

// ── Orders: buyer side ──────────────────────────────────────────
// Everything this user has bought, newest first, grouped by listing type
function sf_buyer_items($conn, $userId) {
    $stmt = $conn->prepare("SELECT oi.id, oi.item_key, oi.item_type, oi.title, oi.seller, oi.units, oi.quantity, oi.line_total,
                                   oi.fulfillment, oi.dispute_status, oi.dispute_outcome, oi.dispute_reason, oi.dispute_note, oi.cancel_reason,
                                   oi.return_status, oi.return_reason, oi.return_response,
                                   o.order_no, UNIX_TIMESTAMP(o.created_at) AS ts
                            FROM order_items oi JOIN orders o ON o.id = oi.order_id
                            WHERE o.user_id = ?
                            ORDER BY o.created_at DESC, oi.id DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $groups = ['products' => [], 'rentals' => [], 'services' => []];
    foreach ($rows as $row) {
        if (isset($groups[$row['item_type']])) $groups[$row['item_type']][] = $row;
    }
    return $groups;
}

function sf_buyer_stats($conn, $userId) {
    $stmt = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM orders WHERE user_id = ? AND YEAR(created_at) = YEAR(CURDATE())) AS orders_year,
        (SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
           WHERE o.user_id = ? AND oi.item_type = 'rentals' AND oi.fulfillment IN ('pending', 'active')) AS active_rentals,
        (SELECT COUNT(*) FROM favorites WHERE user_id = ?) AS saved");
    $stmt->bind_param("iii", $userId, $userId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return array_map('intval', $row);
}

// ── Photo uploads ───────────────────────────────────────────────
// Files from a multiple upload field (<input type="file" name="photos[]" multiple>), one array per file
function sf_uploaded_files($field) {
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['name'])) return [];
    $files = [];
    foreach ($f['name'] as $i => $name) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $files[] = ['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $files;
}

// Checks an uploaded listing photo without saving it. Returns [file extension, error].
function sf_check_listing_photo(array $file) {
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
        || $file['size'] > SF_UPLOAD_MAX_BYTES) return [null, '"' . $file['name'] . '" is over 5 MB.'];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return [null, '"' . $file['name'] . '" could not be uploaded. Please try again.'];

    $info = @getimagesize($file['tmp_name']);
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($exts[$info['mime']])) return [null, '"' . $file['name'] . '" is not a JPG, PNG or WebP photo.'];
    return [$exts[$info['mime']], null];
}

// Saves a photo already passed by sf_check_listing_photo. Returns the saved path or null.
function sf_save_listing_photo(array $file, $ext) {
    $dir = dirname(__DIR__) . '/' . SF_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return null;
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . '/' . $name) ? SF_UPLOAD_DIR . '/' . $name : null;
}

// Remove a photo saved by sf_save_listing_photo (ignores anything outside the upload folder)
function sf_delete_listing_photo($path) {
    if (preg_match('#^' . preg_quote(SF_UPLOAD_DIR, '#') . '/[a-f0-9]{24}\.(jpg|png|webp)$#', (string) $path)) {
        @unlink(dirname(__DIR__) . '/' . $path);
    }
}

// ── Moderation (admin_listings.php, admin_moderation.php) ───────
// Hide a listing from the marketplace and tell the seller what to fix
function sf_flag_listing($conn, array $listing, $note) {
    $stmt = $conn->prepare("UPDATE listings SET status = 'flagged', moderation_note = ? WHERE id = ?");
    $stmt->bind_param("si", $note, $listing['id']);
    $stmt->execute();
    $stmt->close();
    sf_notify($conn, (int) $listing['seller_id'], 'info', 'Listing flagged: ' . $listing['title'],
        'An admin hid this listing from the marketplace. Reason: ' . $note . ' Edit it and request a review from your Seller Dashboard.', 'account.php?tab=seller');
}

// ── Disputes (account.php → admin_transactions.php) ─────────────
const SF_DISPUTE_REASONS = ['Item not received', 'Item not as described', 'Damaged or broken', 'Seller didn\'t show up', 'Service not delivered', 'Other'];

// The buyer reports a problem with one item of their order. Returns null or an error message.
function sf_open_dispute($conn, $buyerId, $orderItemId, $reason, $details) {
    if (!in_array($reason, SF_DISPUTE_REASONS, true)) return 'Please choose what went wrong.';
    $details = trim(str_replace("\r\n", "\n", (string) $details));
    if (mb_strlen($details) < 10) return 'Please describe the problem so an admin can help (at least 10 characters).';
    if (mb_strlen($details) > 1000) return 'Please keep the details under 1,000 characters.';

    $stmt = $conn->prepare("SELECT oi.id, oi.title, oi.fulfillment, oi.dispute_status, oi.return_status, o.order_no, l.seller_id
                            FROM order_items oi JOIN orders o ON o.id = oi.order_id
                            LEFT JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE oi.id = ? AND o.user_id = ?");
    $stmt->bind_param("ii", $orderItemId, $buyerId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item) return 'That order could not be found.';
    if ($item['dispute_status'] !== '') return 'You\'ve already reported a problem with this item.';
    if ($item['return_status'] !== '') return 'You already asked for a return or refund on this rental.';
    if ($item['fulfillment'] === 'refunded') return 'This item was already refunded.';
    if ($item['fulfillment'] === 'cancelled') return 'This item was cancelled.';

    $stmt = $conn->prepare("UPDATE order_items SET dispute_status = 'open', dispute_reason = ?, dispute_details = ?, disputed_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $reason, $details, $item['id']);
    $stmt->execute();
    $stmt->close();
    if ($item['seller_id']) {
        sf_notify($conn, (int) $item['seller_id'], 'order', 'Problem reported: ' . $item['title'],
            'The buyer of order #' . $item['order_no'] . ' reported: ' . $reason . '. An admin will review it; you can\'t update this item until then.', 'account.php?tab=seller');
    }
    return null;
}

// An admin settles a dispute: 'refund' (the buyer gets their money back, the item stops counting as a sale)
// or 'release' (the seller keeps the sale). Returns null or an error message.
function sf_resolve_dispute($conn, $orderItemId, $outcome, $note) {
    if (!in_array($outcome, ['refund', 'release'], true)) return 'Choose how to settle the dispute.';
    if ($note === '') return 'Add a note: both the buyer and the seller see it.';

    $stmt = $conn->prepare("SELECT oi.id, oi.title, oi.line_total, oi.dispute_status, o.order_no, o.user_id AS buyer_id, l.seller_id
                            FROM order_items oi JOIN orders o ON o.id = oi.order_id
                            LEFT JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE oi.id = ?");
    $stmt->bind_param("i", $orderItemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item || $item['dispute_status'] !== 'open') return 'That dispute is already settled.';

    $sql = "UPDATE order_items SET dispute_status = 'resolved', dispute_outcome = ?, dispute_note = ?"
         . ($outcome === 'refund' ? ", fulfillment = 'refunded'" : "") . " WHERE id = ? AND dispute_status = 'open'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $outcome, $note, $item['id']);
    $stmt->execute();
    $stmt->close();

    $what = $item['title'] . ' (order #' . $item['order_no'] . ')';
    if ($outcome === 'refund') {
        sf_notify($conn, (int) $item['buyer_id'], 'order', 'Refund approved', sf_peso($item['line_total']) . ' for ' . $what . ' will be refunded. Note from admin: ' . $note, 'account.php');
        if ($item['seller_id']) sf_notify($conn, (int) $item['seller_id'], 'order', 'Dispute settled: refunded', $what . ' was refunded to the buyer and no longer counts toward your earnings. Note from admin: ' . $note, 'account.php?tab=seller');
    } else {
        sf_notify($conn, (int) $item['buyer_id'], 'order', 'Dispute closed', 'We reviewed your report about ' . $what . ' and closed it without a refund. Note from admin: ' . $note, 'account.php');
        if ($item['seller_id']) sf_notify($conn, (int) $item['seller_id'], 'order', 'Dispute settled in your favour', $what . ' stays a sale. You can update the order again. Note from admin: ' . $note, 'account.php?tab=seller');
    }
    return null;
}

// ── Rentals: return / refund requests (account.php) ─────────────
// The renter asks once the item is in their hands (active) or back with the owner (returned).
// The owner accepts (the renter is refunded) or declines with a reason; a declined request goes
// to an admin as a dispute (admin_transactions.php), who refunds the renter or pays the owner.
const SF_RETURN_REASONS = ['Item damaged or broken', 'Not as described', 'Missing parts or accessories', 'Stopped working', 'Returning it early', 'Other'];
const SF_RETURN_PHOTOS  = 3;   // photos a renter can add to a request

// The renter's request. $files: from sf_uploaded_files('photos'). Returns null or an error message.
function sf_request_return($conn, $renterId, $orderItemId, $reason, $details, array $files) {
    if (!in_array($reason, SF_RETURN_REASONS, true)) return 'Please choose a reason.';
    $details = trim(str_replace("\r\n", "\n", (string) $details));
    if (mb_strlen($details) < 10) return 'Please describe what happened (at least 10 characters).';
    if (mb_strlen($details) > 1000) return 'Please keep the details under 1,000 characters.';
    if (count($files) > SF_RETURN_PHOTOS) return 'You can add up to ' . SF_RETURN_PHOTOS . ' photos.';

    $stmt = $conn->prepare("SELECT oi.id, oi.title, oi.item_type, oi.fulfillment, oi.dispute_status, oi.return_status, o.order_no, l.seller_id
                            FROM order_items oi JOIN orders o ON o.id = oi.order_id
                            LEFT JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE oi.id = ? AND o.user_id = ?");
    $stmt->bind_param("ii", $orderItemId, $renterId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item || $item['item_type'] !== 'rentals') return 'That rental could not be found.';
    if ($item['return_status'] !== '') return 'You already asked for a return or refund on this rental.';
    if ($item['dispute_status'] !== '') return 'You already reported a problem with this rental. An admin is handling it.';
    if (!in_array($item['fulfillment'], ['active', 'returned'], true)) {
        return $item['fulfillment'] === 'pending' ? 'You haven\'t picked this up yet. Cancel the rental instead.' : 'This rental is already closed.';
    }
    if (!$item['seller_id']) return 'The owner\'s listing was removed. Use Report a problem so an admin can help.';

    // Check every photo first, then save them
    $checked = [];
    foreach ($files as $file) {
        [$ext, $error] = sf_check_listing_photo($file);
        if ($error) return $error;
        $checked[] = [$file, $ext];
    }
    $photos = [];
    foreach ($checked as [$file, $ext]) {
        $path = sf_save_listing_photo($file, $ext);
        if (!$path) {
            foreach ($photos as $p) sf_delete_listing_photo($p);
            return 'A photo could not be saved. Please try again.';
        }
        $photos[] = $path;
    }

    $photosJson = json_encode($photos);
    $stmt = $conn->prepare("UPDATE order_items SET return_status = 'requested', return_reason = ?, return_details = ?, return_photos = ?,
                                   return_requested_at = NOW() WHERE id = ? AND return_status = ''");
    $stmt->bind_param("sssi", $reason, $details, $photosJson, $item['id']);
    $stmt->execute();
    $stmt->close();
    sf_notify($conn, (int) $item['seller_id'], 'order', 'Return / refund requested: ' . $item['title'],
        'The renter of order #' . $item['order_no'] . ' asked for a return / refund: ' . $reason . '. Accept or decline it from your Seller Dashboard.', 'account.php?tab=seller');
    return null;
}

// The owner answers. Accept: the renter is refunded. Decline: needs a reason, and an admin decides.
// Returns null or an error message.
function sf_answer_return($conn, $ownerId, $orderItemId, $accept, $note) {
    $note = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $note)), 0, 255);
    if (!$accept && mb_strlen($note) < 5) return 'Tell the renter why you\'re declining (at least 5 characters).';

    $stmt = $conn->prepare("SELECT oi.id, oi.title, oi.line_total, oi.return_status, oi.return_reason, oi.return_details,
                                   o.order_no, o.user_id AS renter_id
                            FROM order_items oi JOIN orders o ON o.id = oi.order_id
                            JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE oi.id = ? AND l.seller_id = ?");
    $stmt->bind_param("ii", $orderItemId, $ownerId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item || $item['return_status'] !== 'requested') return 'That request was already answered.';

    $what = '"' . $item['title'] . '" (order #' . $item['order_no'] . ')';
    if ($accept) {
        // Refunded: the rental stops counting toward the owner's earnings (like a refunded dispute)
        $stmt = $conn->prepare("UPDATE order_items SET return_status = 'accepted', return_response = ?, fulfillment = 'refunded'
                                WHERE id = ? AND return_status = 'requested'");
        $stmt->bind_param("si", $note, $item['id']);
        $stmt->execute();
        $stmt->close();
        sf_notify($conn, (int) $item['renter_id'], 'order', 'Refund approved',
            'The owner accepted your return / refund request for ' . $what . '. ' . sf_peso($item['line_total']) . ' will be refunded the way you paid within 5–7 business days.'
            . ' If you still have the item, return it to the owner.' . ($note !== '' ? ' Note from the owner: ' . $note : ''), 'account.php');
        return null;
    }

    // Declined: it becomes a dispute for an admin, with the renter's reason and details
    $reason = mb_substr('Return declined: ' . $item['return_reason'], 0, 60);
    $stmt = $conn->prepare("UPDATE order_items SET return_status = 'declined', return_response = ?,
                                   dispute_status = 'open', dispute_reason = ?, dispute_details = ?, disputed_at = NOW()
                            WHERE id = ? AND return_status = 'requested'");
    $stmt->bind_param("sssi", $note, $reason, $item['return_details'], $item['id']);
    $stmt->execute();
    $stmt->close();
    sf_notify($conn, (int) $item['renter_id'], 'order', 'Return / refund declined by the owner',
        'The owner declined your request for ' . $what . ': ' . $note . ' An admin will now review it and decide.', 'account.php');
    return null;
}

// Photos added to a return request (a list of paths under uploads/listings)
function sf_return_photos($json) {
    $list = json_decode((string) $json, true);
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}

// ── ID verification for renting (Account › Edit Profile → admin_users.php?tab=ids) ──
// A renter uploads a photo of a valid ID and a selfie holding it, once. An admin approves it.
const SF_RENTER_ID_TYPES = ['School ID', 'PhilSys National ID', 'Driver\'s license', 'Passport', 'UMID', 'Postal ID', 'Other government ID'];

// Saves an ID photo (no PDFs here: the admin compares the two photos). Returns [path, error].
function sf_save_id_photo(array $file, $label) {
    $info = @getimagesize($file['tmp_name']);
    if ($file['error'] === UPLOAD_ERR_OK && (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true))) {
        return [null, $label . ': upload a JPG, PNG or WebP photo.'];
    }
    [$path, $error] = sf_save_id_document($file);
    return [$path, $error ? $label . ': ' . $error : null];
}

// The renter sends (or re-sends after a rejection) their ID. Returns null or an error message.
function sf_submit_renter_id($conn, array $user, $type, $front, $selfie) {
    if ($user['id_status'] === 'verified') return 'Your ID is already verified.';
    if ($user['id_status'] === 'pending') return 'Your ID is already being reviewed.';
    if (!in_array($type, SF_RENTER_ID_TYPES, true)) return 'Please choose the type of ID.';
    if (!$front || ($front['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Add a photo of the front of your ID.';
    if (!$selfie || ($selfie['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Add a selfie of you holding your ID.';

    [$frontPath, $error] = sf_save_id_photo($front, 'ID photo');
    if ($error) return $error;
    [$selfiePath, $error] = sf_save_id_photo($selfie, 'Selfie');
    if ($error) {
        sf_delete_id_document($frontPath);
        return $error;
    }
    // A rejected ID's old photos aren't needed any more
    sf_delete_id_document($user['id_front']);
    sf_delete_id_document($user['id_selfie']);

    $stmt = $conn->prepare("UPDATE users SET id_status = 'pending', id_type = ?, id_front = ?, id_selfie = ?, id_note = '',
                                   id_submitted_at = NOW(), id_reviewed_at = NULL WHERE id = ?");
    $stmt->bind_param("sssi", $type, $frontPath, $selfiePath, $user['id']);
    $stmt->execute();
    $stmt->close();
    return null;
}

// An admin approves or rejects (with a reason the user sees). Returns null or an error message.
function sf_review_renter_id($conn, $userId, $approve, $note) {
    $note = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $note)), 0, 255);
    if (!$approve && $note === '') return 'Add a reason so the user knows what to fix.';
    $status = $approve ? 'verified' : 'rejected';
    $stmt = $conn->prepare("UPDATE users SET id_status = ?, id_note = ?, id_reviewed_at = NOW() WHERE id = ? AND id_status = 'pending'");
    $stmt->bind_param("ssi", $status, $note, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$changed) return 'That ID was already reviewed.';
    sf_notify($conn, (int) $userId, 'security', $approve ? 'Your ID is verified' : 'Your ID wasn\'t approved',
        $approve ? 'You can now rent items on Station Finds.' : 'Reason: ' . $note . ' Upload a new photo from Account › Edit Profile.',
        $approve ? 'marketplace.php?tab=rentals' : 'account.php?tab=profile#verify');
    return null;
}

// ── Seller ID documents ─────────────────────────────────────────
// Uploaded in Become a Seller (step 2) and checked by an admin in admin_sellers.php.
// private/ids is closed to the web (.htaccess); admin_seller_file.php is the only way to open one.
const SF_ID_DIR   = 'private/ids';
const SF_ID_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

// Saves an uploaded ID photo or PDF. Returns [saved path, null] or [null, error message].
function sf_save_id_document(array $file) {
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
        || $file['size'] > SF_UPLOAD_MAX_BYTES) return [null, 'That file is over 5 MB.'];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return [null, 'The file could not be uploaded. Please try again.'];

    // Decide the type from the contents, not the name
    $ext  = null;
    $info = @getimagesize($file['tmp_name']);
    if ($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $ext = array_search($info['mime'], SF_ID_TYPES, true);
    } elseif (file_get_contents($file['tmp_name'], false, null, 0, 5) === '%PDF-') {
        $ext = 'pdf';
    }
    if (!$ext) return [null, 'Upload a JPG, PNG or WebP photo, or a PDF.'];

    $dir = dirname(__DIR__) . '/' . SF_ID_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return [null, 'The file could not be saved. Please try again.'];
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return [null, 'The file could not be saved. Please try again.'];
    return [SF_ID_DIR . '/' . $name, null];
}

// Full path of a saved ID document, or null if the stored value isn't one of ours
function sf_id_document_path($path) {
    if (!preg_match('#^' . preg_quote(SF_ID_DIR, '#') . '/[a-f0-9]{24}\.(jpg|png|webp|pdf)$#', (string) $path)) return null;
    $full = dirname(__DIR__) . '/' . $path;
    return is_file($full) ? $full : null;
}

function sf_delete_id_document($path) {
    $full = sf_id_document_path($path);
    if ($full) @unlink($full);
}
