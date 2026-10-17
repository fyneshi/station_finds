<?php
session_start();

// Redirect to login page if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once 'db_setup.php';
require_once 'shop.php';
require_once 'layout.php';
require_once 'inbox.php';
require_once 'seller.php';
require_once 'feedback.php';

$userId = (int) $_SESSION['user_id'];
$user   = sf_account_user($conn, $userId);
if (!$user) {
    header('Location: logout.php');
    exit;
}
// Seller application (pending / approved / rejected); seller tools only open once an admin approves it
$application = sf_seller_profile($conn, $userId);
$seller      = $application && $application['status'] === 'approved' ? $application : null;

// Tabs: account.php?tab=buyer (default) | seller | payouts | credentials | preferences | profile
$tabs        = ['buyer', 'seller', 'payouts', 'credentials', 'preferences', 'profile'];

// Preferences: choices offered for now (the site's content is in English and prices are in pesos)
const SF_LANGUAGES  = ['en-US' => 'English (US)'];
$sellerOnly  = ['seller', 'payouts'];
$tab = in_array($_GET['tab'] ?? '', $tabs, true) ? $_GET['tab'] : 'buyer';
if (in_array($tab, $sellerOnly, true) && !$seller) {
    header('Location: ' . ($application ? 'account.php' : 'become_seller.php'));   // applicants see their status on the buyer tab
    exit;
}

$flash = $_SESSION['flash'] ?? null;    // [ 'ok' | 'err', message ] from the last form
unset($_SESSION['flash']);
$errors = [];
$old    = [];
$open   = '';      // Login Credentials: which "Change" form stays open after an error (email | password)

// Current password check for sensitive changes (older accounts may still have plain-text passwords)
$passwordMatches = fn($p) => (string) $p !== '' && password_verify((string) $p, $user['password']);

function back_to($tab, $type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: account.php?tab=' . $tab);
    exit;
}

// ── Form submissions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $flash = ['err', 'That photo is too large to upload. Profile pictures can be up to 3 MB.'];
        $tab   = 'profile';
    } elseif (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } elseif ($action === 'update_avatar') {
        // New profile picture replaces the old one
        [$saved, $photoError] = sf_save_avatar($_FILES['avatar'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '']);
        if ($photoError) back_to('profile', 'err', ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? 'Choose a photo first.' : $photoError);
        $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
        $stmt->bind_param("si", $saved, $userId);
        $stmt->execute();
        $stmt->close();
        sf_delete_avatar($user['avatar']);
        back_to('profile', 'ok', 'Your profile picture was updated.');

    } elseif ($action === 'remove_avatar') {
        $conn->query("UPDATE users SET avatar = '' WHERE id = " . (int) $userId);
        sf_delete_avatar($user['avatar']);
        back_to('profile', 'ok', 'Your profile picture was removed.');

    } elseif ($action === 'update_profile') {
        // Email is changed under Login Credentials (it needs the current password)
        $old = [
            'full_name' => trim(preg_replace('/\s+/', ' ', (string) ($_POST['full_name'] ?? ''))),
            'username'  => trim(preg_replace('/\s+/', ' ', (string) ($_POST['username'] ?? ''))),
            'bio'       => trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", (string) ($_POST['bio'] ?? '')))),
        ];
        if (mb_strlen($old['full_name']) > 100) $errors['full_name'] = 'Full name can be up to 100 characters.';
        if (mb_strlen($old['bio']) > 300) $errors['bio'] = 'Keep your About me under 300 characters.';
        if (!preg_match('/^[\p{L}\p{N} ._-]{3,50}$/u', $old['username'])) $errors['username'] = 'Use 3–50 letters, numbers, spaces, dots, dashes or underscores.';

        if (!$errors) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
            $stmt->bind_param("si", $old['username'], $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $errors['username'] = 'That username is already taken.';
            $stmt->close();
        }
        if (!$errors) {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, bio = ? WHERE id = ?");
            $stmt->bind_param("sssi", $old['full_name'], $old['username'], $old['bio'], $userId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['username'] = $old['username'];
            back_to('profile', 'ok', 'Your profile was updated.');
        }
        $tab = 'profile';

    } elseif ($action === 'change_email') {
        $old = ['email' => trim((string) ($_POST['new_email'] ?? ''))];
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 255) {
            $errors['new_email'] = 'Please enter a valid email address.';
        } elseif (strcasecmp($old['email'], $user['email']) === 0) {
            $errors['new_email'] = 'That is already your email.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
            $stmt->bind_param("si", $old['email'], $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $errors['new_email'] = 'That email is already used by another account.';
            $stmt->close();
        }
        if (!$passwordMatches($_POST['email_password'] ?? '')) $errors['email_password'] = 'Your password is incorrect.';

        if (!$errors) {
            $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->bind_param("si", $old['email'], $userId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['email'] = $old['email'];
            sf_notify($conn, $userId, 'security', 'Email changed',
                'Your login email was changed from ' . $user['email'] . ' to ' . $old['email'] . '. If this wasn\'t you, contact support@stationfinds.edu.ph right away.');
            back_to('credentials', 'ok', 'Your email was changed. Use ' . $old['email'] . ' to log in from now on.');
        }
        $open = 'email';
        $tab  = 'credentials';

    } elseif ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (!$passwordMatches($current)) $errors['current_password'] = 'Your current password is incorrect.';
        if (strlen($new) < 8)   $errors['new_password'] = 'Use at least 8 characters.';
        elseif ($new === $current) $errors['new_password'] = 'Choose a password different from your current one.';
        if ($confirm !== $new)  $errors['confirm_password'] = 'Passwords do not match.';

        if (!$errors) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hash, $userId);
            $stmt->execute();
            $stmt->close();
            sf_notify($conn, $userId, 'security', 'Password changed',
                'Your account password was changed from your Account settings. If this wasn\'t you, contact support@stationfinds.edu.ph right away.');
            back_to('credentials', 'ok', 'Your password was changed.');
        }
        $open = 'password';
        $tab  = 'credentials';

    } elseif ($action === 'save_preferences') {
        $messages = isset($_POST['notify_messages']) ? 1 : 0;
        $orders   = isset($_POST['notify_orders']) ? 1 : 0;
        $push     = isset($_POST['notify_push']) ? 1 : 0;
        $online   = isset($_POST['show_online']) ? 1 : 0;
        $language = isset(SF_LANGUAGES[$_POST['language'] ?? '']) ? $_POST['language'] : $user['language'];
        $stmt = $conn->prepare("UPDATE users SET notify_messages = ?, notify_orders = ?, notify_push = ?, show_online = ?, language = ? WHERE id = ?");
        $stmt->bind_param("iiiisi", $messages, $orders, $push, $online, $language, $userId);
        $stmt->execute();
        $stmt->close();
        // The page saves each change in the background (see savePreferences below)
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        back_to('preferences', 'ok', 'Your preferences were saved.');

    } elseif ($seller && in_array($action, ['listing_status', 'listing_delete', 'listing_stock'], true)) {
        $listing = sf_my_listing($conn, $userId, (int) ($_POST['id'] ?? 0));
        if (!$listing) back_to('seller', 'err', 'That listing could not be found.');

        // Quick stock update from the Your Listings panel (products only)
        if ($action === 'listing_stock') {
            if ($listing['type'] !== 'products') back_to('seller', 'err', 'Only products have a stock count.');
            $raw = trim((string) ($_POST['stock'] ?? ''));
            if (!ctype_digit($raw) || (int) $raw > SF_MAX_STOCK) {
                back_to('seller', 'err', 'Enter a stock from 0 to ' . number_format(SF_MAX_STOCK) . ' for "' . $listing['title'] . '".');
            }
            $stock = (int) $raw;
            $stmt = $conn->prepare("UPDATE listings SET stock = ? WHERE id = ? AND seller_id = ? AND type = 'products'");
            $stmt->bind_param("iii", $stock, $listing['id'], $userId);
            $stmt->execute();
            $stmt->close();
            back_to('seller', 'ok', '"' . $listing['title'] . '" now has ' . $stock . ' in stock' . ($stock === 0 ? ' and shows as Out of stock.' : '.'));
        }

        if ($action === 'listing_status') {
            if ($listing['status'] === 'pending') back_to('seller', 'err', '"' . $listing['title'] . '" is already waiting for an admin to review it.');
            $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'draft';
            // A flagged listing goes to review instead of straight back into the shop
            if ($listing['status'] === 'flagged') {
                if ($status === 'draft') back_to('seller', 'err', 'Flagged listings stay hidden until an admin approves them.');
                $status = 'pending';
            }
            $stmt = $conn->prepare("UPDATE listings SET status = ? WHERE id = ? AND seller_id = ?");
            $stmt->bind_param("sii", $status, $listing['id'], $userId);
            $stmt->execute();
            $stmt->close();
            $done = ['active' => 'is now live in the marketplace.', 'draft' => 'is now a draft and hidden from buyers.',
                     'pending' => 'was sent to an admin for review.'];
            back_to('seller', 'ok', '"' . $listing['title'] . '" ' . $done[$status]);
        }

        // Sold listings stay, so their orders and earnings keep showing on the dashboard
        $key  = sf_listing_key($listing['id']);
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM order_items WHERE item_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $sold = (int) $stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        if ($sold > 0) {
            back_to('seller', 'err', '"' . $listing['title'] . '" has orders, so it can\'t be deleted. Unpublish it instead to hide it from buyers.');
        }

        $stmt = $conn->prepare("DELETE FROM listings WHERE id = ? AND seller_id = ?");
        $stmt->bind_param("ii", $listing['id'], $userId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("DELETE FROM cart_items WHERE item_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->close();
        foreach (sf_listing_photos($listing) as $photo) sf_delete_listing_photo($photo);
        back_to('seller', 'ok', '"' . $listing['title'] . '" was deleted.');

    } elseif ($seller && $action === 'request_payout') {
        $raw = str_replace([',', '₱', ' '], '', (string) ($_POST['amount'] ?? ''));
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw)) {
            $errors['amount'] = 'Enter an amount like 5000 or 5000.50.';
        } else {
            [$requestId, $error] = sf_request_payout($conn, $userId, (float) $raw);
            if ($error) {
                $errors['amount'] = $error;
            } else {
                back_to('payouts', 'ok', 'Payout request ' . sf_txn_no($requestId) . ' for ' . sf_peso($raw) . ' was sent for admin approval.');
            }
        }
        $old = ['amount' => $_POST['amount'] ?? ''];
        $tab = 'payouts';

    } elseif ($seller && $action === 'fulfill') {
        $next = sf_advance_fulfillment($conn, $userId, (int) ($_POST['item'] ?? 0), $seller['store_name']);
        if ($next === null) back_to('seller', 'err', 'That order could not be updated. Please refresh and try again.');
        back_to('seller', 'ok', 'Order updated. The buyer has been notified.');

    } elseif ($action === 'cancel_item') {
        $error = sf_cancel_order_item($conn, $userId, (int) ($_POST['item'] ?? 0), (string) ($_POST['reason'] ?? ''));
        back_to('buyer', $error ? 'err' : 'ok', $error ?: 'Cancelled. The seller has been told.');

    } elseif ($action === 'open_dispute') {
        $error = sf_open_dispute($conn, $userId, (int) ($_POST['item'] ?? 0), (string) ($_POST['reason'] ?? ''), (string) ($_POST['details'] ?? ''));
        back_to('buyer', $error ? 'err' : 'ok', $error ?: 'Your report was sent. An admin will review it and get back to you through your notifications.');

    } elseif ($action === 'rate_order' || ($seller && $action === 'rate_buyer')) {
        // Buyers rate a finished order (the listing's public rating); sellers rate the buyer
        $type  = $action === 'rate_order' ? 'buyer' : 'seller';
        $back  = $type === 'buyer' ? 'buyer' : 'seller';
        $error = sf_rate_order_item($conn, $user, $type, (int) ($_POST['item'] ?? 0), $_POST['rating'] ?? 0, $_POST['comment'] ?? '');
        back_to($back, $error ? 'err' : 'ok', $error ?: 'Thanks for your rating!');

    } else {
        $flash = ['err', 'Something went wrong. Please try again.'];
    }
}

// ── Data for the open tab ───────────────────────────────────────
$unread   = sf_unread_counts($conn, $userId);
$name     = sf_display_name($user);
$initials = $user['full_name'] !== '' ? sf_initials($user['full_name']) : strtoupper(mb_substr($user['username'], 0, 2));
$e        = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf     = sf_csrf_token();

if ($tab === 'buyer') {
    $bought = sf_buyer_items($conn, $userId);
    $stats  = sf_buyer_stats($conn, $userId);
    $rated  = sf_rated_items($conn, $userId, 'buyer');    // order items already rated by this buyer
}
if ($tab === 'seller') {
    $ratedBuyers  = sf_rated_items($conn, $userId, 'seller');
    $sellerRating = sf_seller_rating($conn, $userId);
}
if ($tab === 'seller' || $tab === 'payouts') {
    $listings = sf_my_listings($conn, $userId);
    $sales    = sf_seller_sales($conn, $userId);
    $earned = $available = 0;
    $toFulfill = 0;
    foreach ($sales as $s) {
        if (sf_item_closed($s['fulfillment'])) continue;   // refunded after a dispute, or cancelled by the buyer: not a sale
        $earned += $s['line_total'];
        if (sf_fulfillment_is_final($s['item_type'], $s['fulfillment'])) $available += $s['line_total'];
        else $toFulfill++;
    }
    // Open orders first, then finished ones
    $finished = fn($s) => sf_item_closed($s['fulfillment']) || sf_fulfillment_is_final($s['item_type'], $s['fulfillment']);
    usort($sales, fn($a, $b) => $finished($a) <=> $finished($b));
}
if ($tab === 'payouts') {
    $balance        = sf_payout_balance($conn, $userId);
    $payoutRequests = sf_payout_requests($conn, $userId);
    [$destBadge, $destTitle, $destMasked] = sf_payout_destination($seller);
}

// Thumbnail + link for something bought (the listing may have been removed since)
function bought_thumb($itemKey) {
    $item = sf_get_item($itemKey);
    return [$item ? $item['image'] : SF_PLACEHOLDER_IMAGE, $item ? $item['link'] : ''];
}

const ROWS_SHOWN = 3;      // rows shown before "View All"
$icon = [
    'lock'  => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>',
    'arrow' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>',
];
$pageTitles = ['buyer' => 'Buyer Dashboard', 'seller' => 'Seller Dashboard', 'payouts' => 'Payouts & Billing',
               'credentials' => 'Login Credentials', 'preferences' => 'Preferences', 'profile' => 'Edit Profile'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $e($pageTitles[$tab]); ?> — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=5" />
  <link rel="stylesheet" href="css/account.css?v=21" />
</head>
<body>

  <?php sf_render_navbar(sf_cart_count($conn, $userId), 'account'); ?>

  <main class="shop-wrapper">
    <h1 class="shop-title">Account</h1>
    <p class="shop-subtitle">Manage your buyer and seller activity, and account settings.</p>

    <div class="account-layout">

      <!-- ==================== SIDEBAR ==================== -->
      <aside class="card account-sidebar">
        <div class="profile-block">
          <div class="profile-avatar"><?php echo sf_avatar_html($name, $user['avatar']); ?></div>
          <div class="profile-name"><?php echo $e($name); ?></div>
          <div class="profile-email"><?php echo $e($user['email']); ?></div>
          <a href="account.php?tab=profile" class="btn-outline full">Edit Profile</a>
          <a href="profile.php?id=<?php echo (int) $userId; ?>" class="profile-view-link">View my public profile</a>
        </div>

        <nav class="account-nav" aria-label="Account sections">
          <a href="account.php"<?php echo $tab === 'buyer' ? ' class="active" aria-current="page"' : ''; ?>>Buyer Dashboard</a>
          <?php if ($seller): ?>
          <a href="account.php?tab=seller"<?php echo $tab === 'seller' ? ' class="active" aria-current="page"' : ''; ?>>Seller Dashboard</a>
          <a href="account.php?tab=payouts"<?php echo $tab === 'payouts' ? ' class="active" aria-current="page"' : ''; ?>>Payouts &amp; Billing</a>
          <?php else: ?>
          <?php if ($application && $application['status'] === 'pending'): ?>
          <span class="locked app-pending" title="An admin is reviewing your application">Application pending</span>
          <?php elseif ($application): ?>
          <a href="become_seller.php?restart=1">Update application <?php echo $icon['arrow']; ?></a>
          <?php else: ?>
          <a href="become_seller.php">Become a Seller <?php echo $icon['arrow']; ?></a>
          <?php endif; ?>
          <span class="locked" title="Become a seller to unlock">Seller Dashboard <?php echo $icon['lock']; ?></span>
          <span class="locked" title="Become a seller to unlock">Payouts &amp; Billing <?php echo $icon['lock']; ?></span>
          <?php endif; ?>
          <div class="account-nav-label">Settings</div>
          <a href="account.php?tab=credentials"<?php echo $tab === 'credentials' ? ' class="active" aria-current="page"' : ''; ?>>Login Credentials</a>
          <a href="account.php?tab=preferences"<?php echo $tab === 'preferences' ? ' class="active" aria-current="page"' : ''; ?>>Preferences</a>
        </nav>
      </aside>

      <!-- ==================== MAIN ==================== -->
      <section>
        <?php if ($flash): ?>
        <div class="flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <?php if ($tab === 'buyer'): ?>
        <!-- ── BUYER DASHBOARD ── -->
        <div class="section-head">
          <div>
            <h2 class="section-title">Buyer Dashboard</h2>
            <p class="section-sub">Overview of your purchases, active rentals, service requests, and saved listings.</p>
          </div>
        </div>

        <?php if (!$seller && $application && $application['status'] === 'pending'): ?>
        <div class="card sell-banner app-banner">
          <div>
            <h3>Your seller application is being reviewed</h3>
            <p><?php echo $e($application['store_name']); ?> was submitted<?php echo $application['submitted_at'] ? ' on ' . date('M j, Y', strtotime($application['submitted_at'])) : ''; ?>. You'll get a notification as soon as an admin approves it.</p>
          </div>
          <span class="status-badge pending">Pending review</span>
        </div>
        <?php elseif (!$seller && $application): ?>
        <div class="card sell-banner app-banner rejected">
          <div>
            <h3>Your seller application wasn't approved</h3>
            <p>Reason: <?php echo $e($application['review_note'] ?: 'No reason given.'); ?> Update your details and resubmit when you're ready.</p>
          </div>
          <a href="become_seller.php?restart=1" class="btn-primary">Update application</a>
        </div>
        <?php elseif (!$seller): ?>
        <div class="card sell-banner">
          <div>
            <h3>Want to sell or rent out your own items?</h3>
            <p>List products, services, or transit devices to other students. An admin reviews each new seller first.</p>
          </div>
          <a href="become_seller.php" class="btn-primary">Start Selling</a>
        </div>
        <?php endif; ?>

        <div class="stat-grid">
          <div class="card stat-card"><div class="stat-value"><?php echo $stats['orders_year']; ?></div><div class="stat-label">Orders this year</div></div>
          <div class="card stat-card"><div class="stat-value"><?php echo $stats['active_rentals']; ?></div><div class="stat-label">Active rentals</div></div>
          <a href="favorites.php" class="card stat-card"><div class="stat-value"><?php echo $stats['saved']; ?></div><div class="stat-label">Saved items</div></a>
          <a href="messages.php" class="card stat-card"><div class="stat-value"><?php echo $unread['messages']; ?></div><div class="stat-label">Unread messages</div></a>
        </div>

        <?php
          $panels = [
            'products' => ['Orders', 'Track and manage your purchased products.', ['Item', 'Amount', 'Status'], 'products'],
            'rentals'  => ['Rentals', 'View and manage your rented items, including rental period and return status.', ['Item', 'Rental period', 'Cost', 'Status'], 'rentals'],
            'services' => ['Service Requests', 'View and manage services you have requested, including progress and status updates.', ['Service', 'Date requested', 'Fee', 'Status'], 'services'],
          ];
          foreach ($panels as $type => [$title, $sub, $cols, $tabName]):
            $rows = $bought[$type];
            $withPeriod = count($cols) === 4;
        ?>
        <div class="card panel">
          <div class="panel-head">
            <h3 class="panel-title"><?php echo $title; ?></h3>
            <?php if (count($rows) > ROWS_SHOWN): ?>
            <button type="button" class="view-all" onclick="toggleRows(this)" aria-expanded="false">View All (<?php echo count($rows); ?>) &rarr;</button>
            <?php endif; ?>
          </div>
          <p class="panel-sub"><?php echo $sub; ?></p>

          <?php if (!$rows): ?>
          <p class="empty-row">Nothing here yet. <a href="marketplace.php?tab=<?php echo $tabName; ?>">Browse <?php echo $type; ?></a></p>
          <?php else: ?>
          <div class="rows-head<?php echo $withPeriod ? '' : ' no-period'; ?>">
            <span><?php echo $cols[0]; ?></span>
            <?php if ($withPeriod): ?><span class="num"><?php echo $cols[1]; ?></span><?php endif; ?>
            <span class="num"><?php echo $cols[count($cols) - 2]; ?></span>
            <span class="stat"><?php echo $cols[count($cols) - 1]; ?></span>
          </div>
          <?php foreach ($rows as $i => $r):
            [$img, $link] = bought_thumb($r['item_key']);
            $catalogItem  = sf_get_item($r['item_key']);
            $unit = $catalogItem ? $catalogItem['unit'] : ($type === 'rentals' ? 'day' : '');
            if ($type === 'products') {
                $meta = 'Ordered on ' . date('M j, Y', $r['ts']) . ' • Seller: ' . $r['seller'] . ($r['quantity'] > 1 ? ' • Qty: ' . $r['quantity'] : '');
            } elseif ($type === 'rentals') {
                $meta = 'Owner: ' . $r['seller'];
            } else {
                $meta = 'Provider: ' . $r['seller'] . ($unit !== '' ? ' • ' . $r['units'] . ' ' . sf_unit_word($unit, $r['units']) : '');
            }
          ?>
          <div class="row-item<?php echo $withPeriod ? '' : ' no-period'; ?>"<?php echo $i >= ROWS_SHOWN ? ' hidden data-extra' : ''; ?>>
            <div class="row-main">
              <img src="<?php echo $e($img); ?>" alt="" class="row-thumb<?php echo $img === SF_PLACEHOLDER_IMAGE ? ' placeholder' : ''; ?>" />
              <div class="row-text">
                <?php if ($link): ?><a href="<?php echo $e($link); ?>" class="row-title"><?php echo $e($r['title']); ?></a>
                <?php else: ?><span class="row-title"><?php echo $e($r['title']); ?></span><?php endif; ?>
                <div class="row-meta"><?php echo $e($meta); ?></div>
                <?php if (isset($rated[(int) $r['id']])): ?>
                <div class="row-rating">You rated <?php echo sf_stars_html($rated[(int) $r['id']]); ?></div>
                <?php elseif (sf_fulfillment_is_final($type, $r['fulfillment']) && strpos($r['item_key'], 'listing-') === 0): ?>
                <button type="button" class="link-btn rate-btn" onclick='openRate("rate_order", <?php echo (int) $r['id']; ?>, <?php echo json_encode('Rate ' . $r['title'], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>★ Rate this order</button>
                <?php endif; ?>
                <?php if ($r['fulfillment'] === 'cancelled'): ?>
                <div class="row-meta dispute-note">You cancelled this<?php echo $r['cancel_reason'] !== '' ? ': ' . $e($r['cancel_reason']) : ''; ?>.</div>
                <?php elseif ($r['dispute_status'] === 'open'): ?>
                <div class="row-meta dispute-note">Problem reported: <?php echo $e($r['dispute_reason']); ?>. An admin is reviewing it.</div>
                <?php elseif ($r['dispute_status'] === 'resolved'): ?>
                <div class="row-meta dispute-note"><?php echo $r['dispute_outcome'] === 'refund' ? 'Refunded' : 'Dispute closed'; ?>: <?php echo $e($r['dispute_note']); ?></div>
                <?php elseif (strpos($r['item_key'], 'listing-') === 0): ?>
                <?php if ($r['fulfillment'] === 'pending'): ?>
                <button type="button" class="link-btn cancel-btn" onclick='openCancel(<?php echo (int) $r['id']; ?>, <?php echo json_encode($r['title'], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>, <?php echo json_encode($type); ?>)'><?php echo ['products' => 'Cancel order', 'rentals' => 'Cancel rental', 'services' => 'Cancel request'][$type] ?? 'Cancel'; ?></button>
                <?php endif; ?>
                <button type="button" class="link-btn problem-btn" onclick='openDispute(<?php echo (int) $r['id']; ?>, <?php echo json_encode($r['title'], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Report a problem</button>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($type === 'rentals'):
              $end  = $r['ts'] + $r['units'] * 86400;
              $left = (int) ceil(($end - time()) / 86400);
              $due  = [
                'pending'  => 'Awaiting pickup',
                'active'   => $left > 1 ? 'Due in ' . $left . ' days' : ($left === 1 ? 'Due tomorrow' : ($left === 0 ? 'Due today' : 'Overdue')),
                'returned' => 'Returned',
              ][$r['fulfillment']] ?? '';
            ?>
            <div class="row-period"><?php echo date('M j', $r['ts']) . ' – ' . date('M j', $end); ?><small><?php echo $r['units'] . ' ' . sf_unit_word('day', $r['units']) . ($due ? ' • ' . $due : ''); ?></small></div>
            <?php elseif ($type === 'services'): ?>
            <div class="row-period"><?php echo date('M j, Y', $r['ts']); ?></div>
            <?php endif; ?>
            <div class="row-amount"><?php echo sf_peso($r['line_total']); ?></div>
            <div class="stat"><span class="status-badge <?php echo sf_status_class($type, $r['fulfillment']); ?>"><?php echo $e(sf_fulfillment_label($type, $r['fulfillment'])); ?></span></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php elseif ($tab === 'seller'): ?>
        <!-- ── SELLER DASHBOARD ── -->
        <div class="section-head">
          <div>
            <h2 class="section-title">Seller Dashboard</h2>
            <p class="section-sub">Overview of your listings, active rentals, and student sales for <strong><?php echo $e($seller['store_name']); ?></strong>.</p>
          </div>
          <a href="listing_form.php" class="btn-primary auto">+ Create New Listing</a>
        </div>

        <div class="stat-grid">
          <div class="card stat-card"><div class="stat-value"><?php echo count(array_filter($listings, fn($l) => $l['status'] === 'active')); ?></div><div class="stat-label">Active listings</div></div>
          <div class="card stat-card"><div class="stat-value"><?php echo $toFulfill; ?></div><div class="stat-label">Orders to fulfill</div></div>
          <div class="card stat-card"><div class="stat-value"><?php echo sf_peso($earned); ?></div><div class="stat-label">Total earnings</div></div>
          <div class="card stat-card">
            <div class="stat-value"><?php echo $sellerRating['count'] ? number_format($sellerRating['avg'], 1) . ' <span class="star-gold">★</span>' : '&mdash;'; ?></div>
            <div class="stat-label">Average rating<?php echo $sellerRating['count'] ? ' (' . $sellerRating['count'] . ' review' . ($sellerRating['count'] === 1 ? '' : 's') . ')' : ' (no reviews yet)'; ?></div>
          </div>
        </div>

        <?php if ($sales): ?>
        <div class="card panel">
          <div class="panel-head">
            <h3 class="panel-title">Orders</h3>
            <?php if (count($sales) > ROWS_SHOWN): ?>
            <button type="button" class="view-all" onclick="toggleRows(this)" aria-expanded="false">View All (<?php echo count($sales); ?>) &rarr;</button>
            <?php endif; ?>
          </div>
          <p class="panel-sub">Update each order as you hand it over. The buyer gets a notification every time.</p>
          <?php foreach ($sales as $i => $s):
            $next = SF_FULFILLMENT[$s['item_type']][$s['fulfillment']] ?? null;
            $catalogItem = sf_get_item($s['item_key']);
            $unit = $catalogItem ? $catalogItem['unit'] : '';
          ?>
          <div class="listing-row"<?php echo $i >= ROWS_SHOWN ? ' hidden data-extra' : ''; ?>>
            <div class="row-main">
              <div class="row-text">
                <span class="row-title"><?php echo $e($s['title']); ?></span>
                <div class="row-meta">Order #<?php echo $e($s['order_no']); ?> • <?php echo date('M j, Y', $s['ts']); ?> • Buyer: <?php echo $e($s['buyer_name']); ?><?php echo $unit !== '' ? ' • ' . $s['units'] . ' ' . $e(sf_unit_word($unit, $s['units'])) : ''; ?><?php echo $s['quantity'] > 1 ? ' • Qty: ' . (int) $s['quantity'] : ''; ?></div>
              </div>
            </div>
            <div class="listing-side">
              <span class="row-amount"><?php echo sf_peso($s['line_total']); ?></span>
              <span class="status-badge <?php echo sf_status_class($s['item_type'], $s['fulfillment']); ?>"><?php echo $e(sf_fulfillment_label($s['item_type'], $s['fulfillment'])); ?></span>
              <?php if ($s['dispute_status'] === 'open'): ?><span class="status-badge flagged" title="<?php echo $e($s['dispute_reason']); ?>">Disputed</span><?php endif; ?>
              <div class="listing-actions">
                <a href="messages.php?with=<?php echo (int) $s['buyer_id']; ?>" class="btn-outline small">Message buyer</a>
                <?php if (isset($ratedBuyers[(int) $s['id']])): ?>
                <span class="row-rating" title="Your rating of this buyer"><?php echo sf_stars_html($ratedBuyers[(int) $s['id']]); ?></span>
                <?php elseif (sf_fulfillment_is_final($s['item_type'], $s['fulfillment'])): ?>
                <button type="button" class="btn-outline small" onclick='openRate("rate_buyer", <?php echo (int) $s['id']; ?>, <?php echo json_encode('Rate the buyer: ' . $s['buyer_name'], JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Rate buyer</button>
                <?php endif; ?>
                <?php if ($next && $next[1] && $s['dispute_status'] !== 'open'): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
                  <input type="hidden" name="action" value="fulfill" />
                  <input type="hidden" name="item" value="<?php echo (int) $s['id']; ?>" />
                  <button type="submit" class="btn-outline small"><?php echo $e($next[2]); ?></button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card panel">
          <div class="panel-head">
            <h3 class="panel-title">Your Listings</h3>
            <?php
              $lowStock = array_filter($listings, fn($l) => $l['type'] === 'products' && (int) $l['stock'] <= SF_LOW_STOCK);
            ?>
            <?php if (count($listings) > 5): ?>
            <button type="button" class="view-all" onclick="toggleRows(this)" aria-expanded="false">Manage All (<?php echo count($listings); ?>)</button>
            <?php endif; ?>
          </div>
          <?php if ($lowStock): $soldOut = count(array_filter($lowStock, fn($l) => (int) $l['stock'] === 0)); ?>
          <div class="stock-alert" role="status">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span><?php
              $parts = [];
              if ($soldOut) $parts[] = $soldOut . ' ' . ($soldOut === 1 ? 'product is' : 'products are') . ' out of stock';
              if (count($lowStock) - $soldOut) $parts[] = (count($lowStock) - $soldOut) . ' ' . (count($lowStock) - $soldOut === 1 ? 'is' : 'are') . ' running low (' . SF_LOW_STOCK . ' or fewer left)';
              echo ucfirst(implode(' and ', $parts)) . '. Update the stock below when you restock.';
            ?></span>
          </div>
          <?php endif; ?>
          <?php if (!$listings): ?>
          <p class="empty-row">You haven't posted anything yet. <a href="listing_form.php">Create your first listing</a></p>
          <?php endif; ?>
          <?php foreach ($listings as $i => $l):
            $img    = sf_listing_image($l);
            $live   = $l['status'] === 'active';
            $catLbl = ['products' => 'Products', 'rentals' => 'Rentals', 'services' => 'Services'][$l['type']] ?? '';
            // Status badge, and the publish button's label (none while an admin is reviewing it)
            [$badgeClass, $badgeText, $toggleLabel] = [
                'active'  => ['live', 'Active', 'Unpublish'],
                'draft'   => ['draft', 'Draft', 'Publish'],
                'flagged' => ['flagged', 'Flagged', 'Request review'],
                'pending' => ['pending', 'Pending review', null],
            ][$l['status']] ?? ['draft', ucfirst($l['status']), null];
          ?>
          <?php
            // Products: stock level (out / low / in) for the highlight and the badge
            $stockLevel = null;
            if ($l['type'] === 'products') {
                $left = (int) $l['stock'];
                $stockLevel = $left === 0 ? 'out' : ($left <= SF_LOW_STOCK ? 'low' : 'in');
            }
          ?>
          <div class="listing-row<?php echo in_array($stockLevel, ['out', 'low'], true) ? ' stock-' . $stockLevel : ''; ?>"<?php echo $i >= 5 ? ' hidden data-extra' : ''; ?>>
            <div class="row-main">
              <img src="<?php echo $e($img); ?>" alt="" class="row-thumb<?php echo $img === SF_PLACEHOLDER_IMAGE ? ' placeholder' : ''; ?>" />
              <div class="row-text">
                <?php if ($live): ?><a href="<?php echo $e(sf_item_link(sf_listing_key($l['id']), $l['type'])); ?>" class="row-title"><?php echo $e($l['title']); ?></a>
                <?php else: ?><span class="row-title"><?php echo $e($l['title']); ?></span><?php endif; ?>
                <div class="row-meta">Category: <?php echo $catLbl; ?> • Posted on <?php echo date('M j, Y', strtotime($l['created_at'])); ?></div>
                <?php if ($stockLevel): ?>
                <form method="post" class="stock-form">
                  <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
                  <input type="hidden" name="action" value="listing_stock" />
                  <input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>" />
                  <span class="stock-badge <?php echo $stockLevel; ?>"><?php echo ['out' => 'Out of stock', 'low' => 'Low stock', 'in' => 'In stock'][$stockLevel]; ?></span>
                  <label for="stock-<?php echo (int) $l['id']; ?>">Stock</label>
                  <input type="number" id="stock-<?php echo (int) $l['id']; ?>" name="stock" value="<?php echo (int) $l['stock']; ?>" min="0" max="<?php echo SF_MAX_STOCK; ?>" step="1" inputmode="numeric" required aria-label="Stock for <?php echo $e($l['title']); ?>" />
                  <button type="submit" class="btn-outline small">Update</button>
                </form>
                <?php endif; ?>
                <?php if ($l['status'] === 'flagged'): ?>
                <div class="row-meta flag-note">Flagged by an admin: <?php echo $e($l['moderation_note']); ?> Fix it, then request a review.</div>
                <?php elseif ($l['status'] === 'pending'): ?>
                <div class="row-meta">An admin is reviewing your changes. It goes live once approved.</div>
                <?php endif; ?>
              </div>
            </div>
            <div class="listing-side">
              <span class="row-amount"><?php echo $e(sf_listing_price($l)); ?></span>
              <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
              <div class="listing-actions">
                <a href="listing_form.php?id=<?php echo (int) $l['id']; ?>" class="btn-outline small">Edit</a>
                <?php if ($toggleLabel): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
                  <input type="hidden" name="action" value="listing_status" />
                  <input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>" />
                  <input type="hidden" name="status" value="<?php echo $live ? 'draft' : 'active'; ?>" />
                  <button type="submit" class="btn-outline small"><?php echo $toggleLabel; ?></button>
                </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Delete this listing? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
                  <input type="hidden" name="action" value="listing_delete" />
                  <input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>" />
                  <button type="submit" class="btn-outline small danger" aria-label="Delete <?php echo $e($l['title']); ?>">Delete</button>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php elseif ($tab === 'payouts'): ?>
        <!-- ── PAYOUTS & BILLING ── -->
        <div class="card payout-panel">
          <h2 class="section-title">Payout Balance &amp; Settings</h2>
          <p class="section-sub">Released earnings available to withdraw. Excludes past payouts and pending / flagged reservations.</p>

          <div class="balance-box">
            <div>
              <div class="balance-label">Available balance</div>
              <div class="balance-amount"><?php echo $e(sf_peso_exact($balance['available'])); ?></div>
              <?php if ($earned - $balance['released'] > 0): ?>
              <div class="balance-note"><?php echo $e(sf_peso($earned - $balance['released'])); ?> more is released when your open orders are completed.</div>
              <?php endif; ?>
            </div>
            <a href="#requestPayout" class="btn-dark" onclick="focusAmount(event)"<?php echo $balance['available'] < SF_PAYOUT_MIN ? ' aria-disabled="true"' : ''; ?>>Withdraw earnings</a>
          </div>

          <h3 class="payout-heading">Primary Payout Method</h3>
          <div class="method-row">
            <span class="method-badge"><?php echo $e($destBadge); ?></span>
            <div class="method-text">
              <strong><?php echo $e($destTitle); ?></strong>
              <span>Connected to <?php echo $e($destMasked); ?> · <?php echo $e($seller['payout_name']); ?></span>
            </div>
            <a href="become_seller.php?step=3" class="method-edit">Edit</a>
          </div>

          <h3 class="payout-heading">Payout History</h3>
          <?php if (!$payoutRequests): ?>
          <div class="history-list"><p class="empty-row">No payouts yet. Requests you submit below show up here.</p></div>
          <?php else: ?>
          <div class="history-list">
            <?php foreach ($payoutRequests as $r):
              [$label, $badge, $meaning] = SF_PAYOUT_STATUS[$r['status']];
              $meaning = sprintf($meaning, $r['method'] === 'gcash' ? 'GCash' : 'bank');
            ?>
            <div class="history-row">
              <div class="history-text">
                <strong><?php echo sf_txn_no($r['id']); ?></strong>
                <span><?php echo date('M j, Y', $r['ts']); ?> • <?php echo $e($meaning); ?><?php echo $r['admin_note'] !== '' ? ' • ' . $e($r['admin_note']) : ''; ?></span>
              </div>
              <span class="status-badge <?php echo $badge; ?>"><?php echo strtoupper($label); ?></span>
              <span class="history-amount"><?php echo $e(sf_peso_exact($r['amount'])); ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <p class="payout-footnote">Previously paid total: <?php echo $e(sf_peso($balance['paid'])); ?>. These past transfers are separate from the <?php echo $e(sf_peso($balance['available'])); ?> currently available.<?php if ($balance['reserved'] > 0): ?> <?php echo $e(sf_peso($balance['reserved'])); ?> is reserved by pending or flagged requests.<?php endif; ?></p>
          <?php endif; ?>

          <form method="post" class="request-box" id="requestPayout" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
            <input type="hidden" name="action" value="request_payout" />
            <h3 class="panel-title">Request a payout</h3>
            <p class="panel-sub">Submit your payout request for admin review. Pending and flagged amounts are reserved until resolved.</p>

            <h4 class="payout-subheading">Payout details</h4>
            <label class="field-caps" for="amount">Request amount</label>
            <div class="amount-field<?php echo isset($errors['amount']) ? ' has-error' : ''; ?>">
              <span>₱</span>
              <input type="text" id="amount" name="amount" inputmode="decimal" autocomplete="off" maxlength="12"
                     value="<?php echo $e($old['amount'] ?? ''); ?>" placeholder="<?php echo number_format(min(5000, $balance['available']), 2); ?>"
                     <?php echo $balance['available'] < SF_PAYOUT_MIN ? 'disabled' : ''; ?> />
              <small>≤ <?php echo $e(sf_peso_exact($balance['available'])); ?></small>
            </div>
            <?php if (isset($errors['amount'])): ?><span class="field-error"><?php echo $e($errors['amount']); ?></span><?php endif; ?>

            <span class="field-caps">Destination</span>
            <div class="method-row">
              <span class="method-badge"><?php echo $e($destBadge); ?></span>
              <div class="method-text">
                <strong><?php echo $e($destTitle); ?></strong>
                <span><?php echo $e($destMasked); ?></span>
              </div>
              <a href="become_seller.php?step=3" class="method-edit">Edit</a>
            </div>

            <div class="request-foot">
              <span>Requested by <?php echo $e($name); ?></span>
              <button type="submit" class="btn-dark"<?php echo $balance['available'] < SF_PAYOUT_MIN ? ' disabled' : ''; ?>>Submit for approval</button>
            </div>
            <?php if ($balance['available'] < SF_PAYOUT_MIN): ?>
            <p class="payout-footnote">You need at least <?php echo $e(sf_peso(SF_PAYOUT_MIN)); ?> available to request a payout. Earnings are released when you mark an order delivered, returned or completed.</p>
            <?php endif; ?>
            <p class="payout-legend">Pending: awaiting admin review. Flagged: held for investigation. Paid: transfer confirmed. Rejected requests return reserved funds to available balance.</p>
          </form>
        </div>

        <?php elseif ($tab === 'credentials'): ?>
        <!-- ── LOGIN CREDENTIALS ── -->
        <?php
          $fieldErr = fn($key) => isset($errors[$key]) ? '<span class="field-error">' . $e($errors[$key]) . '</span>' : '';
          $fieldBad = fn($key) => isset($errors[$key]) ? ' has-error' : '';
        ?>
        <div class="card cred-panel">
          <h2 class="section-title">Login Credentials</h2>
          <p class="section-sub">Change the campus email you log in with, or update your password.</p>

          <!-- Email -->
          <div class="cred-block">
            <label class="form-label" for="currentEmail">Campus Email</label>
            <div class="cred-row">
              <input type="email" id="currentEmail" class="text-input" value="<?php echo $e($user['email']); ?>" readonly />
              <button type="button" class="btn-small" aria-controls="emailForm" aria-expanded="<?php echo $open === 'email' ? 'true' : 'false'; ?>" onclick="toggleCred('emailForm')">Change</button>
            </div>
            <form method="post" id="emailForm" class="cred-form"<?php echo $open === 'email' ? '' : ' hidden'; ?> novalidate>
              <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
              <input type="hidden" name="action" value="change_email" />
              <div class="field-row-split">
                <div class="form-field">
                  <label class="form-label" for="new_email">New email</label>
                  <input type="email" id="new_email" name="new_email" class="text-input<?php echo $fieldBad('new_email'); ?>" value="<?php echo $e($old['email'] ?? ''); ?>" maxlength="255" autocomplete="email" placeholder="e.g. juandelacruz@up.edu.ph" required />
                  <?php echo $fieldErr('new_email'); ?>
                </div>
                <div class="form-field">
                  <label class="form-label" for="email_password">Current password</label>
                  <input type="password" id="email_password" name="email_password" class="text-input<?php echo $fieldBad('email_password'); ?>" autocomplete="current-password" required />
                  <?php echo $fieldErr('email_password'); ?>
                </div>
              </div>
              <div class="cred-actions">
                <button type="button" class="btn-outline" onclick="toggleCred('emailForm')">Cancel</button>
                <button type="submit" class="btn-primary inline">Save email</button>
              </div>
            </form>
          </div>

          <!-- Password -->
          <div class="cred-block">
            <label class="form-label" for="currentPassword">Account Password</label>
            <div class="cred-row">
              <input type="password" id="currentPassword" class="text-input" value="passwordhidden" readonly aria-label="Account password (hidden)" />
              <button type="button" class="btn-small" aria-controls="passwordForm" aria-expanded="<?php echo $open === 'password' ? 'true' : 'false'; ?>" onclick="toggleCred('passwordForm')">Change</button>
            </div>
            <form method="post" id="passwordForm" class="cred-form"<?php echo $open === 'password' ? '' : ' hidden'; ?> novalidate>
              <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
              <input type="hidden" name="action" value="change_password" />
              <div class="cred-grid-3">
                <?php foreach (['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Confirm new password'] as $field => $label): ?>
                <div class="form-field">
                  <label class="form-label" for="<?php echo $field; ?>"><?php echo $label; ?></label>
                  <input type="password" id="<?php echo $field; ?>" name="<?php echo $field; ?>" class="text-input<?php echo $fieldBad($field); ?>"
                         autocomplete="<?php echo $field === 'current_password' ? 'current-password' : 'new-password'; ?>"<?php echo $field === 'current_password' ? '' : ' minlength="8"'; ?> required />
                  <?php echo $fieldErr($field); ?>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="cred-actions">
                <button type="button" class="btn-outline" onclick="toggleCred('passwordForm')">Cancel</button>
                <button type="submit" class="btn-primary inline">Update password</button>
              </div>
            </form>
          </div>
        </div>

        <?php elseif ($tab === 'preferences'): ?>
        <!-- ── PREFERENCES ── -->
        <?php
          // [field, title, description, on?] — saved as soon as they're switched
          $toggles = [
            ['notify_push', 'Push Notifications', 'Real-time alerts inside your browser while Station Finds is open.', $user['notify_push']],
            ['notify_messages', 'Message alerts', 'Get a notification when someone sends you a message.', $user['notify_messages']],
            ['notify_orders', 'Order updates', 'Orders you place, new orders for your listings, and shipping or rental updates.', $user['notify_orders']],
            ['show_online', 'Show when I\'m online', 'Others see a green dot on your picture and when you were last active. Turn off to stay hidden.', $user['show_online']],
          ];
        ?>
        <form method="post" class="card pref-panel" id="prefsForm">
          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
          <input type="hidden" name="action" value="save_preferences" />
          <div class="pref-title-row">
            <div>
              <h2 class="section-title">Preferences</h2>
              <p class="section-sub">Tailor your catalog alerts, notification frequencies, and localization tools.</p>
            </div>
            <span class="pref-saved" id="prefsSaved" role="status" aria-live="polite"></span>
          </div>

          <h3 class="pref-heading">Notifications</h3>
          <div class="pref-list">
            <?php foreach ($toggles as [$field, $title, $text, $on]): ?>
            <label class="pref-row">
              <span class="pref-text"><strong><?php echo $title; ?></strong><span><?php echo $text; ?></span></span>
              <input type="checkbox" name="<?php echo $field; ?>" value="1" class="switch-input" role="switch"<?php echo $on ? ' checked' : ''; ?> />
            </label>
            <?php endforeach; ?>
          </div>
          <p class="field-hint prefs-note">Security alerts, like a password change, are always sent.</p>

          <div class="pref-selects">
            <div class="form-field">
              <label class="form-label" for="language">Display Language</label>
              <select id="language" name="language" class="text-input">
                <?php foreach (SF_LANGUAGES as $key => $label): ?>
                <option value="<?php echo $key; ?>"<?php echo $user['language'] === $key ? ' selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
                <option disabled>Filipino (coming soon)</option>
              </select>
            </div>
          </div>
          <noscript><button type="submit" class="btn-primary inline">Save preferences</button></noscript>
        </form>

        <?php else: ?>
        <!-- ── EDIT PROFILE ── -->
        <?php $v = $old ?: ['full_name' => $user['full_name'], 'username' => $user['username'], 'bio' => $user['bio']]; ?>
        <div class="section-head">
          <div>
            <h2 class="section-title">Edit Profile</h2>
            <p class="section-sub">Your name is shown to buyers and sellers you trade with.</p>
          </div>
        </div>
        <!-- Profile picture -->
        <div class="card settings-card avatar-card">
          <div class="avatar-preview"><?php echo sf_avatar_html($name, $user['avatar']); ?></div>
          <div class="avatar-actions">
            <span class="form-label">Profile picture</span>
            <p class="field-hint">JPG, PNG or WebP, up to 3 MB. A square photo of your face works best. Everyone you trade with can see it.</p>
            <div class="avatar-buttons">
              <form method="post" enctype="multipart/form-data" id="avatarForm">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
                <input type="hidden" name="action" value="update_avatar" />
                <label class="btn-primary inline avatar-upload">
                  <?php echo sf_avatar_url($user['avatar']) ? 'Change photo' : 'Upload photo'; ?>
                  <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()" />
                </label>
              </form>
              <?php if (sf_avatar_url($user['avatar'])): ?>
              <form method="post" onsubmit="return confirm('Remove your profile picture?')">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
                <input type="hidden" name="action" value="remove_avatar" />
                <button type="submit" class="btn-outline">Remove</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <form method="post" class="card settings-card" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
          <input type="hidden" name="action" value="update_profile" />
          <div class="form-stack">
            <div class="form-field">
              <label class="form-label" for="full_name">Full name <span class="optional">(optional)</span></label>
              <input type="text" id="full_name" name="full_name" class="text-input<?php echo isset($errors['full_name']) ? ' has-error' : ''; ?>" value="<?php echo $e($v['full_name']); ?>" maxlength="100" autocomplete="name" placeholder="e.g. Yuuki Ogawa" />
              <?php if (isset($errors['full_name'])): ?><span class="field-error"><?php echo $errors['full_name']; ?></span><?php endif; ?>
            </div>
            <div class="form-field">
              <label class="form-label" for="username">Username</label>
              <input type="text" id="username" name="username" class="text-input<?php echo isset($errors['username']) ? ' has-error' : ''; ?>" value="<?php echo $e($v['username']); ?>" maxlength="50" autocomplete="username" required />
              <?php if (isset($errors['username'])): ?><span class="field-error"><?php echo $errors['username']; ?></span><?php endif; ?>
            </div>
            <div class="form-field">
              <label class="form-label" for="bio">About me <span class="optional">(optional, shown on your public profile)</span></label>
              <textarea id="bio" name="bio" class="text-input textarea-input<?php echo isset($errors['bio']) ? ' has-error' : ''; ?>" maxlength="300" rows="3" placeholder="e.g. 2nd-year Engineering student. Usually around the campus library."><?php echo $e($v['bio']); ?></textarea>
              <?php if (isset($errors['bio'])): ?><span class="field-error"><?php echo $errors['bio']; ?></span><?php endif; ?>
            </div>
            <div class="form-field">
              <span class="form-label">Email</span>
              <p class="field-hint"><?php echo $e($user['email']); ?> · <a href="account.php?tab=credentials">Change it under Login Credentials</a></p>
            </div>
          </div>
          <button type="submit" class="btn-primary inline">Save changes</button>
        </form>
        <?php endif; ?>
      </section>
    </div>
  </main>

  <!-- Rate a finished order (buyer) or a buyer (seller) -->
  <dialog class="rate-modal" id="rateModal" aria-labelledby="rateTitle">
    <form method="post" id="rateForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" id="rateAction" />
      <input type="hidden" name="item" id="rateItem" />
      <h2 id="rateTitle">Rate this order</h2>
      <fieldset class="star-field">
        <legend class="form-label">Your rating</legend>
        <div class="star-input">
          <?php for ($s = 5; $s >= 1; $s--): ?>
          <input type="radio" id="rate<?php echo $s; ?>" name="rating" value="<?php echo $s; ?>" /><label for="rate<?php echo $s; ?>" title="<?php echo $s; ?> star<?php echo $s === 1 ? '' : 's'; ?>">★</label>
          <?php endfor; ?>
        </div>
      </fieldset>
      <label class="form-label" for="rateComment">Comment <span class="optional">(optional)</span></label>
      <textarea id="rateComment" name="comment" class="text-input" maxlength="1000" placeholder="How did it go? Item condition, communication, meetup…"></textarea>
      <p class="field-error" id="rateError"></p>
      <div class="wizard-actions">
        <button type="button" class="btn-outline" onclick="document.getElementById('rateModal').close()">Cancel</button>
        <button type="submit" class="btn-primary">Submit rating</button>
      </div>
    </form>
  </dialog>

  <!-- Report a problem with something bought (reviewed in admin_transactions.php) -->
  <dialog class="rate-modal" id="disputeModal" aria-labelledby="disputeTitle">
    <form method="post" id="disputeForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" value="open_dispute" />
      <input type="hidden" name="item" id="disputeItem" />
      <h2 id="disputeTitle">Report a problem</h2>
      <p class="field-hint">An admin will look into it and contact you and the seller. The seller can't update this item until it's settled.</p>
      <label class="form-label" for="disputeReason">What went wrong?</label>
      <select id="disputeReason" name="reason" class="text-input">
        <option value="">Choose a reason…</option>
        <?php foreach (SF_DISPUTE_REASONS as $reason): ?><option><?php echo $e($reason); ?></option><?php endforeach; ?>
      </select>
      <label class="form-label" for="disputeDetails">Details</label>
      <textarea id="disputeDetails" name="details" class="text-input" maxlength="1000" placeholder="What happened? Dates, meetup place, messages with the seller…"></textarea>
      <p class="field-error" id="disputeError"></p>
      <div class="wizard-actions">
        <button type="button" class="btn-outline" onclick="document.getElementById('disputeModal').close()">Cancel</button>
        <button type="submit" class="btn-primary">Send report</button>
      </div>
    </form>
  </dialog>

  <dialog class="rate-modal" id="cancelModal" aria-labelledby="cancelTitle">
    <form method="post" id="cancelForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" value="cancel_item" />
      <input type="hidden" name="item" id="cancelItem" />
      <h2 id="cancelTitle">Cancel this order?</h2>
      <p class="field-hint" id="cancelHint">The seller is told right away. If you paid online, the amount is refunded the way you paid within 5–7 business days. This can't be undone.</p>
      <label class="form-label" for="cancelReason">Why are you cancelling?</label>
      <select id="cancelReason" name="reason" class="text-input">
        <option value="">Choose a reason…</option>
        <?php foreach (SF_CANCEL_REASONS as $reason): ?><option><?php echo $e($reason); ?></option><?php endforeach; ?>
      </select>
      <p class="field-error" id="cancelError"></p>
      <div class="wizard-actions">
        <button type="button" class="btn-outline" onclick="document.getElementById('cancelModal').close()">Keep it</button>
        <button type="submit" class="btn-danger">Yes, cancel</button>
      </div>
    </form>
  </dialog>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=4"></script>
  <script src="js/inbox.js?v=2"></script>
  <script src="js/dashboard-motion.js?v=1"></script>
  <script>
    // Ratings: open the form for one order item
    function openRate(action, item, title) {
      const modal = document.getElementById('rateModal');
      document.getElementById('rateAction').value = action;
      document.getElementById('rateItem').value = item;
      document.getElementById('rateTitle').textContent = title;
      document.getElementById('rateForm').reset();
      document.getElementById('rateError').textContent = '';
      modal.showModal();
    }
    document.getElementById('rateForm').addEventListener('submit', e => {
      if (!document.querySelector('#rateForm input[name="rating"]:checked')) {
        e.preventDefault();
        document.getElementById('rateError').textContent = 'Please choose 1 to 5 stars.';
      }
    });
    document.getElementById('rateModal').addEventListener('click', e => { if (e.target.id === 'rateModal') e.target.close(); });

    // Cancelling an item the seller hasn't started on yet
    function openCancel(item, title, type) {
      document.getElementById('cancelForm').reset();
      document.getElementById('cancelItem').value = item;
      document.getElementById('cancelTitle').textContent = ({ products: 'Cancel order: ', rentals: 'Cancel rental: ', services: 'Cancel request: ' }[type] || 'Cancel: ') + title;
      document.getElementById('cancelError').textContent = '';
      document.getElementById('cancelModal').showModal();
    }
    document.getElementById('cancelForm').addEventListener('submit', e => {
      if (!document.getElementById('cancelReason').value) { e.preventDefault(); document.getElementById('cancelError').textContent = 'Please choose a reason.'; }
    });
    document.getElementById('cancelModal').addEventListener('click', e => { if (e.target.id === 'cancelModal') e.target.close(); });

    // Disputes: report a problem with one bought item
    function openDispute(item, title) {
      document.getElementById('disputeForm').reset();
      document.getElementById('disputeItem').value = item;
      document.getElementById('disputeTitle').textContent = 'Report a problem: ' + title;
      document.getElementById('disputeError').textContent = '';
      document.getElementById('disputeModal').showModal();
    }
    document.getElementById('disputeForm').addEventListener('submit', e => {
      const error = !document.getElementById('disputeReason').value ? 'Please choose what went wrong.'
        : document.getElementById('disputeDetails').value.trim().length < 10 ? 'Please describe the problem (at least 10 characters).' : '';
      if (error) { e.preventDefault(); document.getElementById('disputeError').textContent = error; }
    });
    document.getElementById('disputeModal').addEventListener('click', e => { if (e.target.id === 'disputeModal') e.target.close(); });

    // Preferences: save each change right away
    (function () {
      const form = document.getElementById('prefsForm');
      if (!form) return;
      const status = document.getElementById('prefsSaved');
      let timer = null;

      async function savePreferences() {
        status.className = 'pref-saved';
        status.textContent = 'Saving…';
        try {
          const res = await fetch('account.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' },
            body: new FormData(form)
          });
          const r = await res.json();
          if (!r.success) throw new Error();
          status.textContent = 'Saved';
        } catch (e) {
          status.className = 'pref-saved err';
          status.textContent = 'Not saved. Check your connection and try again.';
        }
        clearTimeout(timer);
        timer = setTimeout(() => { if (status.textContent === 'Saved') status.textContent = ''; }, 2500);
      }

      form.addEventListener('change', async e => {
        const box = e.target;
        // Push notifications need the browser's permission first
        if (box.name === 'notify_push' && box.checked) {
          if (!('Notification' in window)) {
            box.checked = false;
            SFCart.toast('This browser does not support notifications.', true);
            return;
          }
          const permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission;
          if (permission !== 'granted') {
            box.checked = false;
            SFCart.toast('Notifications are blocked. Allow them for this site in your browser settings, then try again.', true);
            return;
          }
        }
        savePreferences();
      });
    })();

    // Login Credentials: "Change" / "Cancel" open and close a form
    function toggleCred(formId) {
      const form = document.getElementById(formId);
      form.hidden = !form.hidden;
      document.querySelectorAll('[aria-controls="' + formId + '"]').forEach(btn => {
        btn.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
      });
      if (!form.hidden) {
        const first = form.querySelector('input:not([type="hidden"])');
        if (first) first.focus();
      }
    }

    // "Withdraw earnings": jump to the request form and start typing the amount
    function focusAmount(e) {
      const amount = document.getElementById('amount');
      if (!amount) return;
      e.preventDefault();
      document.getElementById('requestPayout').scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (!amount.disabled) amount.focus({ preventScroll: true });
    }

    // "View All" / "Manage All": show or hide the extra rows in that card
    function toggleRows(btn) {
      const card = btn.closest('.panel');
      const open = btn.getAttribute('aria-expanded') !== 'true';
      card.querySelectorAll('[data-extra]').forEach(row => { row.hidden = !open; });
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (!btn.dataset.label) btn.dataset.label = btn.innerHTML;
      btn.innerHTML = open ? 'Show less' : btn.dataset.label;
    }
  </script>
</body>
</html>
