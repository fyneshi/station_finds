<?php
/**
 * Public profile of a buyer or seller: profile.php?id=<user id>
 * Shows their picture, About me, store (sellers), active listings and the reviews they've
 * received. Never shows their email, phone, school or student ID.
 */
session_start();

// Guests can browse this page; account features ask them to log in (js/guest.js)

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/reports.php';

$myId      = (int) ($_SESSION['user_id'] ?? 0);
$profileId = (int) ($_GET['id'] ?? $myId);
$e         = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// Admin and suspended accounts have no public profile
$stmt = $conn->prepare("SELECT id, username, full_name, role, status, avatar, bio, UNIX_TIMESTAMP(created_at) AS joined, UNIX_TIMESTAMP(last_seen_at) AS last_seen, show_online FROM users WHERE id = ?");
$stmt->bind_param("i", $profileId);
$stmt->execute();
$person = $stmt->get_result()->fetch_assoc();
$stmt->close();
$available = $person && $person['role'] !== 'admin' && $person['status'] === 'active';

if ($available) {
    $name   = $person['full_name'] !== '' ? $person['full_name'] : $person['username'];
    $isMe   = $profileId === $myId;
    $stmt = $conn->prepare("SELECT store_name, seller_type, category, about FROM seller_profiles WHERE user_id = ? AND status = 'approved'");
    $stmt->bind_param("i", $profileId);
    $stmt->execute();
    $store = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Their active listings, newest first (the catalog already leaves out hidden ones)
    $listings = array_filter(sf_catalog(), fn($item) => ($item['seller_id'] ?? 0) === $profileId);
    $detailPages = ['products' => 'product_detail.php', 'rentals' => 'rental_detail.php', 'services' => 'service_detail.php'];

    // Reviews they've received: from buyers (as a seller) and from sellers (as a buyer)
    $stmt = $conn->prepare("SELECT f.type, f.rating, f.message, f.name, f.user_id, UNIX_TIMESTAMP(f.created_at) AS ts,
                                   u.avatar, u.status AS reviewer_status, COALESCE(l.title, oi.title) AS item
                            FROM feedback f
                            LEFT JOIN users u ON u.id = f.user_id
                            LEFT JOIN order_items oi ON oi.id = f.order_item_id
                            LEFT JOIN listings l ON f.listing_key = CONCAT('listing-', l.id)
                            WHERE f.about_user_id = ? AND f.type IN ('buyer', 'seller') AND f.rating IS NOT NULL
                              AND f.status NOT IN ('flagged', 'dismissed')
                            ORDER BY f.created_at DESC, f.id DESC");
    $stmt->bind_param("i", $profileId);
    $stmt->execute();
    $reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $asSeller = array_filter($reviews, fn($r) => $r['type'] === 'buyer');
    $asBuyer  = array_filter($reviews, fn($r) => $r['type'] === 'seller');
    $avg = fn($list) => $list ? number_format(array_sum(array_column($list, 'rating')) / count($list), 1) : null;

    // Finished sales of their listings
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM order_items oi JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                            WHERE l.seller_id = ? AND oi.fulfillment IN ('delivered', 'returned', 'completed')");
    $stmt->bind_param("i", $profileId);
    $stmt->execute();
    $sales = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    $categories = sf_all_categories();
    $role = $store ? 'Buyer & Seller' : 'Buyer';
}
$csrf = sf_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $available ? $e($name) . ' — Station Finds' : 'Profile not available — Station Finds'; ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=6" />
  <link rel="stylesheet" href="css/profile.css?v=3" />
  <link rel="stylesheet" href="css/header.css?v=3" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
</head>
<body>

  <?php sf_render_navbar(sf_cart_count($conn, $myId), 'profile'); ?>

  <main class="profile-page">
    <?php if (!$available): ?>
    <section class="pf-card pf-missing">
      <h1>This profile isn't available</h1>
      <p>The account may have been removed or suspended.</p>
      <a href="marketplace.php" class="pf-btn">Back to the marketplace</a>
    </section>
    <?php else: ?>

    <!-- Header -->
    <section class="pf-card pf-head">
      <div class="presence pf-presence"><div class="pf-avatar"><?php echo sf_avatar_html($name, $person['avatar']); ?></div><?php echo sf_online_dot($person); ?></div>
      <div class="pf-who">
        <h1><?php echo $e($name); ?></h1>
        <p class="pf-sub">@<?php echo $e($person['username']); ?> · Joined <?php echo date('M Y', $person['joined']); ?><?php $active = sf_active_text($person); if ($active !== ''): ?> · <span class="<?php echo $active === 'Active now' ? 'active-now' : ''; ?>"><?php echo $active; ?></span><?php endif; ?></p>
        <div class="pf-tags">
          <span class="pf-tag"><?php echo $role; ?></span>
          <?php if ($store): ?><span class="pf-tag gold"><?php echo $store['seller_type'] === 'business' ? 'Registered business' : 'Student seller'; ?></span><?php endif; ?>
        </div>
        <?php if ($person['bio'] !== ''): ?><p class="pf-bio"><?php echo nl2br($e($person['bio'])); ?></p><?php endif; ?>
      </div>
      <div class="pf-actions">
        <?php if ($isMe): ?>
        <a href="account.php?tab=profile" class="pf-btn">Edit profile</a>
        <?php else: ?>
        <a href="messages.php?with=<?php echo $profileId; ?>" class="pf-btn">Message</a>
        <button type="button" class="pf-btn outline" aria-expanded="false" aria-controls="pfReport" onclick="toggleReport(this)">Report</button>
        <?php endif; ?>
      </div>
    </section>

    <?php if (!$isMe): ?>
    <!-- Report user -->
    <form class="pf-card pf-report" id="pfReport" hidden novalidate>
      <h2>Report <?php echo $e($name); ?></h2>
      <label for="pfReason">Reason</label>
      <select id="pfReason" name="reason" required>
        <option value="">Choose a reason…</option>
        <?php foreach (SF_REPORT_REASONS['user'] as $reason): ?><option><?php echo $e($reason); ?></option><?php endforeach; ?>
      </select>
      <label for="pfDetails">Details</label>
      <textarea id="pfDetails" name="details" maxlength="1000" placeholder="Describe the issue and include any relevant messages or dates…"></textarea>
      <div class="pf-report-foot">
        <p class="pf-report-msg" role="status"></p>
        <button type="submit" class="pf-btn">Submit report</button>
      </div>
      <p class="pf-note">Reports go to the Station Finds admins and stay private.</p>
    </form>
    <?php endif; ?>

    <!-- Stats -->
    <section class="pf-stats">
      <div class="pf-card pf-stat"><span>Seller rating</span><strong><?php echo $avg($asSeller) ? '★ ' . $avg($asSeller) : '—'; ?></strong><small><?php echo count($asSeller); ?> review<?php echo count($asSeller) === 1 ? '' : 's'; ?> from buyers</small></div>
      <div class="pf-card pf-stat"><span>Buyer rating</span><strong><?php echo $avg($asBuyer) ? '★ ' . $avg($asBuyer) : '—'; ?></strong><small><?php echo count($asBuyer); ?> review<?php echo count($asBuyer) === 1 ? '' : 's'; ?> from sellers</small></div>
      <div class="pf-card pf-stat"><span>Active listings</span><strong><?php echo count($listings); ?></strong><small><?php echo $sales; ?> completed sale<?php echo $sales === 1 ? '' : 's'; ?></small></div>
    </section>

    <?php if ($store): ?>
    <!-- Store -->
    <section class="pf-card">
      <h2 class="pf-title"><?php echo $e($store['store_name']); ?></h2>
      <p class="pf-sub">Sells mostly <?php echo $e($categories[$store['category']] ?? 'a bit of everything'); ?></p>
      <?php if (trim((string) $store['about']) !== ''): ?><p class="pf-bio"><?php echo nl2br($e($store['about'])); ?></p><?php endif; ?>
    </section>

    <!-- Listings -->
    <section class="pf-card">
      <h2 class="pf-title">Listings</h2>
      <?php if ($listings): ?>
      <div class="pf-listings">
        <?php foreach ($listings as $key => $l): ?>
        <a href="<?php echo $detailPages[$l['type']] ?? 'marketplace.php'; ?>?id=<?php echo $e($key); ?>" class="pf-listing">
          <img src="<?php echo $e($l['image']); ?>" alt="" class="<?php echo $l['image'] === SF_PLACEHOLDER_IMAGE ? 'placeholder' : ''; ?>" />
          <span class="pf-listing-type"><?php echo strtoupper(rtrim($l['type'], 's')); ?><?php if ($stock = sf_stock_status($l)): ?> &middot; <span class="pf-stock-<?php echo $stock[0]; ?>"><?php echo $e(mb_strtoupper($stock[1])); ?></span><?php endif; ?></span>
          <strong><?php echo $e($l['title']); ?></strong>
          <span class="pf-listing-price"><?php echo sf_price_html($l); ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="pf-empty">No active listings right now.</p>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- Reviews -->
    <section class="pf-card">
      <h2 class="pf-title">Reviews</h2>
      <?php if (!$reviews): ?>
      <p class="pf-empty">No reviews yet. Reviews appear after completed orders.</p>
      <?php endif; ?>
      <?php foreach (array_slice($reviews, 0, 10) as $r): $who = $r['name'] ?: 'Student'; $linked = $r['user_id'] && $r['reviewer_status'] === 'active'; ?>
      <article class="pf-review">
        <div class="pf-review-top">
          <span class="pf-review-avatar"><?php echo sf_avatar_html($who, $r['avatar'] ?? ''); ?></span>
          <span class="pf-review-who">
            <?php if ($linked): ?><a href="profile.php?id=<?php echo (int) $r['user_id']; ?>"><?php echo $e($who); ?></a><?php else: ?><?php echo $e($who); ?><?php endif; ?>
            <small><?php echo $r['type'] === 'buyer' ? 'Bought from them' : 'Sold to them'; ?><?php echo $r['item'] ? ' · ' . $e($r['item']) : ''; ?></small>
          </span>
          <span class="pf-review-stars" aria-label="<?php echo (int) $r['rating']; ?> out of 5 stars"><?php echo str_repeat('★', (int) $r['rating']) . '<span>' . str_repeat('☆', 5 - (int) $r['rating']) . '</span>'; ?></span>
        </div>
        <?php if (trim((string) $r['message']) !== ''): ?><p><?php echo nl2br($e($r['message'])); ?></p><?php endif; ?>
        <small class="pf-review-date"><?php echo date('M j, Y', $r['ts']); ?></small>
      </article>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=8"></script>
  <script src="js/inbox.js?v=5"></script>
  <script>
    function toggleReport(btn) {
      const form = document.getElementById('pfReport');
      form.hidden = !form.hidden;
      btn.setAttribute('aria-expanded', String(!form.hidden));
      if (!form.hidden) form.querySelector('select').focus();
    }

    // Report this user to the admins' Content Moderation
    const reportForm = document.getElementById('pfReport');
    if (reportForm) reportForm.addEventListener('submit', async e => {
      e.preventDefault();
      const msg = reportForm.querySelector('.pf-report-msg');
      const show = (text, ok) => { msg.textContent = text; msg.className = 'pf-report-msg ' + (ok ? 'ok' : 'err'); };
      if (!reportForm.elements.reason.value) { show('Choose a reason first.', false); reportForm.elements.reason.focus(); return; }
      const btn = reportForm.querySelector('button[type="submit"]');
      btn.disabled = true;
      try {
        const res = await fetch('api/report_handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'report', type: 'user', id: <?php echo (int) $profileId; ?>,
                                 reason: reportForm.elements.reason.value, details: reportForm.elements.details.value.trim() })
        });
        const data = await res.json();
        show(data.message || (data.success ? 'Thanks, your report was sent.' : 'Your report could not be sent.'), !!data.success);
        if (data.success) reportForm.reset();
      } catch (err) {
        show('Your report could not be sent. Please try again.', false);
      }
      btn.disabled = false;
    });
  </script>
  <?php sf_render_tabbar(''); ?>
</body>
</html>
