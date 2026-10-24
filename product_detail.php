<?php
session_start();

// Guests can browse this page; account features ask them to log in (js/guest.js)

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/comments.php';

// Which product to show: product_detail.php?id=<listing id>
// (unknown or missing id = back to the marketplace)
$item = sf_get_item($_GET['id'] ?? '', 'products');
if (!$item) {
    header('Location: marketplace.php?tab=products');
    exit;
}

$username = $_SESSION['username'] ?? 'User';
$email    = $_SESSION['email'] ?? '';
$initials = !empty($username) ? strtoupper(substr($username, 0, 2)) : 'MC';

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$photos = sf_gallery($item);
$isPlaceholder = $item['image'] === SF_PLACEHOLDER_IMAGE;
$maxQty = sf_max_qty($item);   // what's left in stock, up to SF_MAX_QTY per order (0 = out of stock)

// Comments & Suggestions: saved to the database (comments.php), shown to everyone
$pageLink = 'product_detail.php?id=' . $item['id'];
[$commentError, $commentDraft] = sf_handle_comment_post($conn, $item, (int) ($_SESSION['user_id'] ?? 0), $pageLink);
$comments = sf_listing_comments($conn, (int) substr($item['id'], 8));
$reviews  = sf_listing_reviews($conn, $item['id']);   // Ratings & Reviews from completed orders
$csrf     = sf_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $e($item['detail_title']); ?> — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/detail.css?v=13" />
  <link rel="stylesheet" href="css/header.css?v=1" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
</head>
<body>

  <!-- ==================== HEADER / NAVBAR ==================== -->
  <?php sf_render_navbar(sf_cart_count($conn, (int) ($_SESSION['user_id'] ?? 0)), 'market'); ?>

  <!-- ==================== BREADCRUMB ==================== -->
  <div class="breadcrumb-container">
    <nav class="breadcrumb-nav">
      <a href="marketplace.php" class="breadcrumb-link">Marketplace</a>
      <span class="breadcrumb-sep">&rsaquo;</span>
      <a href="marketplace.php?tab=products" class="breadcrumb-link">Products</a>
      <span class="breadcrumb-sep">&rsaquo;</span>
      <span class="breadcrumb-current"><?php echo $e($item['title']); ?></span>
    </nav>
  </div>

  <!-- ==================== MAIN CONTENT ==================== -->
  <main class="detail-wrapper">
    <div class="detail-grid">

      <!-- LEFT COLUMN: MEDIA & COMMENTS -->
      <div class="detail-media-col">
        <!-- Main Image -->
        <div class="detail-main-img-box">
          <img id="mainDetailImg" src="<?php echo $e($photos[0]['main']); ?>" alt="<?php echo $e($item['detail_title']); ?>" class="detail-main-img"<?php echo $isPlaceholder ? ' style="object-fit: contain; padding: 40px;"' : ''; ?> />
        </div>

        <?php if (count($photos) > 1): ?>
        <!-- Thumbnails -->
        <div class="detail-thumbnails-row">
          <?php foreach ($photos as $i => $photo): ?>
          <button type="button" class="detail-thumb-btn<?php echo $i === 0 ? ' active' : ''; ?>" onclick="switchMedia(this, '<?php echo $e($photo['main']); ?>')">
            <img src="<?php echo $e($photo['thumb']); ?>" alt="Thumb <?php echo $i + 1; ?>" class="detail-thumb-img" />
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Comments & Suggestions (saved; comments.php) -->
        <?php sf_render_comments($item, $comments, $pageLink, $csrf, $commentError, $commentDraft, ['Comments & Suggestions', 'Write a comment or suggestion…', 'No comments yet. Be the first to ask a question or leave a suggestion.']); ?>

        <!-- Ratings & Reviews (completed orders) -->
        <?php sf_render_reviews($item, $reviews); ?>
      </div>

      <!-- RIGHT COLUMN: PRODUCT INFO & ACTIONS -->
      <div class="detail-info-col">
        <!-- Badges -->
        <div class="detail-badges">
          <span class="badge-gold">PRODUCT</span>
          <span class="badge-subtle"><?php echo $e(strtoupper($item['term'])); ?></span>
        </div>

        <!-- Title -->
        <h1 class="detail-title"><?php echo $e($item['detail_title']); ?></h1>

        <?php if ($item['rating'] > 0): ?>
        <!-- Rating -->
        <div class="detail-rating-row">
          <span class="rating-star">★ <?php echo number_format($item['rating'], 1); ?></span>
          <?php if ($item['reviews'] > 0): ?>
          <span class="rating-count">(<?php echo (int) $item['reviews']; ?> review<?php echo $item['reviews'] == 1 ? '' : 's'; ?>)</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Price -->
        <div class="detail-price-main"><?php echo sf_price_html($item); ?></div>

        <!-- Availability (from the listing's stock) -->
        <?php [$stockClass, $stockText] = sf_stock_status($item); ?>
        <div class="stock-status <?php echo $stockClass; ?>" id="stockStatus" role="status">
          <span class="stock-dot" aria-hidden="true"></span>
          <span id="stockText"><?php echo $e($stockText); ?><?php echo $stockClass === 'in' ? ' &middot; ' . (int) $item['stock'] . ' available' : ''; ?></span>
        </div>

        <?php if ($item['description'] !== ''): ?>
        <!-- Description (sellers' own listings) -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Description</h4>
          <p class="detail-description"><?php echo $e($item['description']); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($item['details']): ?>
        <!-- Item details (category, meetup location) -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Item details</h4>
          <div class="specs-grid-2x2">
            <?php foreach ($item['details'] as $label => $value): ?>
            <div class="spec-prop"><span class="spec-label"><?php echo $e($label); ?>:</span> <span class="spec-value"><?php echo $e($value); ?></span></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($item['specs']): ?>
        <!-- Specs & Inclusions -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Specs &amp; inclusions</h4>
          <ul class="specs-list">
            <?php foreach ($item['specs'] as $spec): ?>
            <li>
              <span class="spec-check-icon">&#10003;</span>
              <span><?php echo $e($spec); ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <!-- Quantity Stepper -->
        <div class="detail-qty-row">
          <span class="qty-label">Quantity:</span>
          <div class="qty-stepper">
            <button type="button" class="qty-btn" id="qtyMinus" onclick="changeQty(-1)" aria-label="Decrease quantity" disabled>&minus;</button>
            <span id="qtyVal" class="qty-num" aria-live="polite"><?php echo $maxQty > 0 ? 1 : 0; ?></span>
            <button type="button" class="qty-btn" id="qtyPlus" onclick="changeQty(1)" aria-label="Increase quantity"<?php echo $maxQty <= 1 ? ' disabled' : ''; ?>>&#43;</button>
          </div>
          <span class="qty-limit" id="qtyLimit"<?php echo $maxQty > 1 ? ' hidden' : ''; ?>><?php echo $maxQty === 1 ? 'Only 1 available' : ''; ?></span>
        </div>

        <!-- Actions -->
        <div class="detail-actions-row">
          <button type="button" class="btn-buy-now" id="buyNowBtn" onclick="buyNow()"<?php echo $maxQty > 0 ? '' : ' disabled'; ?>><?php echo $maxQty > 0 ? 'Buy now' : 'Out of stock'; ?></button>
          <button type="button" class="btn-add-cart" id="addCartBtn" onclick="addToCart()"<?php echo $maxQty > 0 ? '' : ' disabled'; ?>>Add to cart</button>
        </div>

        <!-- Message seller -->
        <div>
          <?php if ($item['seller_id'] === (int) ($_SESSION['user_id'] ?? 0)): ?>
          <a href="listing_form.php?id=<?php echo (int) substr($item['id'], 8); ?>" class="detail-message-link">This is your listing &middot; Edit it</a>
          <?php else: ?>
          <a href="<?php echo $item['seller_id'] ? 'messages.php?with=' . $item['seller_id'] : 'messages.php'; ?>" class="detail-message-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            Message seller about this product
          </a>
          <div class="report-row">
            <button type="button" class="report-link" data-report-type="listing" data-report-id="<?php echo (int) substr($item['id'], 8); ?>" data-report-name="<?php echo $e($item['title']); ?>" data-report-reasons="<?php echo $e(json_encode(SF_REPORT_REASONS['listing'])); ?>">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
              Report listing
            </button>
          </div>
          <?php endif; ?>
        </div>

        <!-- Seller information -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Seller information</h4>
          <div class="seller-card">
            <div class="seller-left">
              <div class="presence"><div class="seller-avatar"><?php echo sf_avatar_html($item['seller'], $item['seller_avatar']); ?></div><?php echo sf_online_dot($item['seller_presence']); ?></div>
              <div class="seller-info">
                <h5 class="seller-name"><?php if ($item['seller_id']): ?><a href="profile.php?id=<?php echo (int) $item['seller_id']; ?>" class="seller-profile-link"><?php echo $e($item['seller']); ?></a><?php else: ?><?php echo $e($item['seller']); ?><?php endif; ?></h5>
                <span class="seller-meta"><?php echo $e($item['seller_meta']); ?></span>
                <?php $active = sf_active_text($item['seller_presence']); if ($active !== ''): ?><span class="seller-active<?php echo $active === 'Active now' ? ' active-now' : ''; ?>"><?php echo $active; ?></span><?php endif; ?>
              </div>
            </div>
            <?php if ($item['seller_rating'] > 0): ?>
            <div class="seller-rating">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
              <span><?php echo number_format($item['seller_rating'], 1); ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </main>

  <!-- ==================== FOOTER ==================== -->
  <footer class="footer">
    <div class="footer-container">
      <div class="footer-top">
        <div class="footer-brand-col">
          <div class="footer-emblem-badge">
            <img src="assets/images/logo2.png" alt="Station Finds Emblem" class="footer-emblem-img" />
          </div>
        </div>
        <div class="footer-col">
          <h4 class="footer-heading"><a href="about.php" class="footer-heading-link">ABOUT US</a></h4>
          <ul class="footer-links-list">
            <li><a href="about.php#get-to-know-us" class="footer-link">Get to know us</a></li>
            <li><a href="about.php#our-commitment" class="footer-link">Our commitment</a></li>
          </ul>
        </div>
        <div class="footer-col">
          <h4 class="footer-heading"><a href="contact.php" class="footer-heading-link">CONTACT US</a></h4>
          <ul class="footer-links-list">
            <li class="footer-text-item">Katipunan Ave, Quezon City, PH</li>
            <li><a href="mailto:support@stationfinds.edu.ph" class="footer-link">support@stationfinds.edu.ph</a></li>
            <li class="footer-text-item">+63 (2) 8123&ndash;4567</li>
          </ul>
        </div>
        <div class="footer-col">
          <h4 class="footer-heading"><a href="policies.php" class="footer-heading-link">POLICIES</a></h4>
          <ul class="footer-links-list">
            <li><a href="policies.php?page=privacy" class="footer-link">Privacy policy</a></li>
            <li><a href="policies.php?page=terms" class="footer-link">Terms of service</a></li>
            <li><a href="policies.php?page=refund" class="footer-link">Refund policy</a></li>
          </ul>
        </div>
      </div>
      <div class="footer-divider"></div>
      <div class="footer-bottom">
        <p>&copy; 2026 STATIONFINDS. Developed for student safety, affordability, and convenience.</p>
      </div>
    </div>
  </footer>

  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <script src="js/report.js?v=2"></script>
  <script>
    const ITEM_ID = <?php echo json_encode($item['id']); ?>;
    const ORDER_LIMIT = <?php echo SF_MAX_QTY; ?>;      // most of one item per order
    const LOW_STOCK   = <?php echo SF_LOW_STOCK; ?>;
    let maxQty = <?php echo $maxQty; ?>;                  // what's left in stock, up to ORDER_LIMIT

    // Thumbnail switcher
    function switchMedia(btn, src) {
      document.querySelectorAll('.detail-thumb-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const mainImg = document.getElementById('mainDetailImg');
      if (mainImg) mainImg.src = src;
    }

    // Quantity stepper
    let qty = maxQty > 0 ? 1 : 0;
    function changeQty(delta) {
      if (maxQty < 1) return;
      qty = Math.min(maxQty, Math.max(1, qty + delta));
      renderQty();
    }
    function renderQty() {
      document.getElementById('qtyVal').textContent = qty;
      document.getElementById('qtyMinus').disabled = qty <= 1;
      document.getElementById('qtyPlus').disabled = qty >= maxQty;
      const limit = document.getElementById('qtyLimit');
      limit.hidden = maxQty < 1 || qty < maxQty;
      limit.textContent = maxQty < ORDER_LIMIT ? 'Only ' + maxQty + ' available' : 'Up to ' + ORDER_LIMIT + ' per order';
    }

    // The server said the stock changed (someone else just bought some): show the new number
    function applyStock(stock) {
      maxQty = Math.min(ORDER_LIMIT, Math.max(0, stock));
      qty = Math.min(Math.max(qty, 1), Math.max(maxQty, 0));
      if (maxQty < 1) qty = 0;
      const status = document.getElementById('stockStatus');
      status.className = 'stock-status ' + (stock <= 0 ? 'out' : stock <= LOW_STOCK ? 'low' : 'in');
      document.getElementById('stockText').textContent =
        stock <= 0 ? 'Out of stock' : stock <= LOW_STOCK ? 'Only ' + stock + ' left' : 'In stock · ' + stock + ' available';
      const buy = document.getElementById('buyNowBtn');
      buy.textContent = maxQty > 0 ? 'Buy now' : 'Out of stock';
      buy.disabled = document.getElementById('addCartBtn').disabled = maxQty < 1;
      renderQty();
    }

    // Cart actions
    async function addToCart() {
      const btn = document.getElementById('addCartBtn');
      btn.disabled = true;
      try {
        const r = await SFCart.add(ITEM_ID, qty);
        SFCart.toast(r.message);
      } catch (e) {   // toast already shown
        if (e.data && typeof e.data.stock === 'number') { applyStock(e.data.stock); return; }
      }
      btn.disabled = maxQty < 1;
    }

    async function buyNow() {
      const btn = document.getElementById('buyNowBtn');
      btn.disabled = true;
      try {
        await SFCart.buyNow(ITEM_ID, qty);   // just this item, the cart is left alone
        window.location.href = 'checkout.php';
      } catch (e) {
        btn.disabled = false;
        if (e.data && typeof e.data.stock === 'number') applyStock(e.data.stock);
      }
    }

    // User dropdown toggle
    function toggleUserDropdown() {
      document.getElementById('userDropdown').classList.toggle('show');
    }
    document.addEventListener('click', function(e) {
      const wrapper = document.querySelector('.user-menu-wrapper');
      if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('userDropdown').classList.remove('show');
      }
    });
  </script>
  <?php sf_render_tabbar('market'); ?>
</body>
</html>