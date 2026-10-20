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

require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/comments.php';

// Which service to show: service_detail.php?id=<listing id>
// (unknown or missing id = back to the marketplace)
$item = sf_get_item($_GET['id'] ?? '', 'services');
if (!$item) {
    header('Location: marketplace.php?tab=services');
    exit;
}
$unitWord = sf_unit_word($item['unit'], 2);   // "hours", "shoots", "slides", ...

$username = $_SESSION['username'] ?? 'User';
$email    = $_SESSION['email'] ?? '';
$initials = !empty($username) ? strtoupper(substr($username, 0, 2)) : 'MC';

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$photos = sf_gallery($item);
$isPlaceholder = $item['image'] === SF_PLACEHOLDER_IMAGE;

// Comments & Suggestions: saved to the database (comments.php), shown to everyone
$pageLink = 'service_detail.php?id=' . $item['id'];
[$commentError, $commentDraft] = sf_handle_comment_post($conn, $item, (int) $_SESSION['user_id'], $pageLink);
$comments = sf_listing_comments($conn, (int) substr($item['id'], 8));
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
  <link rel="stylesheet" href="css/detail.css?v=12" />
</head>
<body>

  <!-- ==================== HEADER / NAVBAR ==================== -->
  <header class="navbar">
    <div class="nav-container">
      <!-- Left: Logo & Navigation Links -->
      <div class="nav-left">
        <a href="home.php" class="nav-brand">
          <img src="assets/images/logo2.png" alt="Station Finds" class="nav-logo-img" />
          <span class="nav-brand-text">
            <span class="brand-station">STATION</span>
            <span class="brand-finds">FINDS</span>
          </span>
        </a>

        <nav class="nav-links">
          <a href="home.php" class="nav-link">Home</a>
          <a href="marketplace.php" class="nav-link active">Marketplace</a>
          <a href="messages.php" class="nav-link">Messages</a>
          <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
          <a href="admin_dashboard.php" class="nav-link" style="color: #f7a827; font-weight: 700;">Admin Dashboard</a>
          <?php endif; ?>
        </nav>
      </div>

      <!-- Middle: Search Bar -->
      <div class="nav-center">
        <form action="search.php" method="GET" class="search-bar">
          <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="text" name="q" class="search-input" placeholder="Search books, gadgets, dorm find, bike rentals, tutors..." />
        </form>
      </div>

      <!-- Right: Action Icons & User Avatar -->
      <div class="nav-right">
        <a href="notifications.php" class="icon-btn" title="Notifications" aria-label="Notifications">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          </svg>
        </a>
        <a href="favorites.php" class="icon-btn" title="Saved Items" aria-label="Saved Items">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
          </svg>
        </a>
        <a href="cart.php" class="icon-btn cart-btn" title="Shopping Cart" aria-label="Shopping Cart">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="21" r="1"></circle>
            <circle cx="20" cy="21" r="1"></circle>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
          </svg>
          <span class="cart-badge" style="display: none;"></span>
        </a>

        <!-- User Profile Avatar & Dropdown -->
        <div class="user-menu-wrapper">
          <button type="button" class="user-avatar-btn" id="userMenuBtn" onclick="toggleUserDropdown()" aria-label="User profile">
            <?php echo sf_nav_avatar_html(); ?>
          </button>
          <div class="user-dropdown" id="userDropdown">
            <div class="user-dropdown-header">
              <div class="dropdown-avatar"><?php echo sf_nav_avatar_html(); ?></div>
              <div class="dropdown-user-details">
                <span class="dropdown-username"><?php echo htmlspecialchars($username); ?></span>
                <span class="dropdown-email"><?php echo htmlspecialchars($email); ?></span>
              </div>
            </div>
            <div class="dropdown-divider"></div>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="admin_dashboard.php" class="dropdown-item" style="color: #f7a827; font-weight: 700;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
              Admin Dashboard
            </a>
            <?php endif; ?>
            <a href="account.php?tab=profile" class="dropdown-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
              My Profile
            </a>
            <div class="dropdown-divider"></div>
            <a href="logout.php" class="dropdown-item logout-link">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
              Log out
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- ==================== BREADCRUMB ==================== -->
  <div class="breadcrumb-container">
    <nav class="breadcrumb-nav">
      <a href="marketplace.php" class="breadcrumb-link">Marketplace</a>
      <span class="breadcrumb-sep">&rsaquo;</span>
      <a href="marketplace.php?tab=services" class="breadcrumb-link">Services</a>
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
      </div>

      <!-- RIGHT COLUMN: SERVICE INFO & ACTIONS -->
      <div class="detail-info-col">
        <!-- Badges -->
        <div class="detail-badges">
          <span class="badge-gold">SERVICE</span>
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

        <?php if ($item['description'] !== ''): ?>
        <!-- Description (sellers' own listings) -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Description</h4>
          <p class="detail-description"><?php echo $e($item['description']); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($item['details']): ?>
        <!-- Service coverage -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Service coverage</h4>
          <div class="specs-grid-2x2">
            <?php foreach ($item['details'] as $label => $value): ?>
            <div class="spec-prop"><span class="spec-label"><?php echo $e($label); ?>:</span> <span class="spec-value"><?php echo $e($value); ?></span></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Hours / shoots / slides / sessions -->
        <div class="detail-qty-row">
          <?php if (count($item['units']) > 1): ?>
          <span class="qty-label"><?php echo $e(ucfirst($unitWord)); ?>:</span>
          <div class="qty-stepper">
            <button type="button" class="qty-btn" onclick="changeUnits(-1)" aria-label="Fewer <?php echo $e($unitWord); ?>">&minus;</button>
            <span id="unitsVal" class="qty-num"></span>
            <button type="button" class="qty-btn" onclick="changeUnits(1)" aria-label="More <?php echo $e($unitWord); ?>">&#43;</button>
          </div>
          <?php endif; ?>
          <span style="font-size: 13px; color: var(--text-muted);"><?php echo $item['from'] ? 'Starting price' : 'Estimated total'; ?>: <strong id="serviceTotal" style="color: var(--text-dark);"></strong></span>
        </div>

        <!-- Request service button -->
        <div>
          <button type="button" class="btn-primary-full" id="requestBtn" onclick="openServiceRequest()">Request service</button>
        </div>

        <!-- Message provider -->
        <div>
          <?php if ($item['seller_id'] === (int) $_SESSION['user_id']): ?>
          <a href="listing_form.php?id=<?php echo (int) substr($item['id'], 8); ?>" class="detail-message-link">This is your listing &middot; Edit it</a>
          <?php else: ?>
          <a href="<?php echo $item['seller_id'] ? 'messages.php?with=' . $item['seller_id'] : 'messages.php'; ?>" class="detail-message-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            Message provider to get a custom quote
          </a>
          <div class="report-row">
            <button type="button" class="report-link" data-report-type="listing" data-report-id="<?php echo (int) substr($item['id'], 8); ?>" data-report-name="<?php echo $e($item['title']); ?>" data-report-reasons="<?php echo $e(json_encode(SF_REPORT_REASONS['listing'])); ?>">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
              Report listing
            </button>
          </div>
          <?php endif; ?>
        </div>

        <!-- Service provider information -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Service provider information</h4>
          <div class="seller-card">
            <div class="seller-left">
              <div class="presence"><div class="seller-avatar avatar-rc"><?php echo sf_avatar_html($item['seller'], $item['seller_avatar']); ?></div><?php echo sf_online_dot($item['seller_presence']); ?></div>
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

  <!-- ==================== REQUEST SERVICE MODAL ==================== -->
  <?php $unitOne = sf_unit_word($item['unit'], 1); ?>
  <dialog class="rent-modal" id="serviceModal" aria-labelledby="serviceTitle">
    <form id="serviceForm" novalidate>
      <div class="rent-modal-head with-sub">
        <div>
          <h2 id="serviceTitle">Request Service</h2>
          <p>Find a service that fits your schedule and budget.</p>
        </div>
        <button type="button" class="rent-close" onclick="closeServiceRequest()" aria-label="Close">&times;</button>
      </div>
      <label class="rent-label" for="srvItem">Service</label>
      <input type="text" id="srvItem" class="rent-input" value="<?php echo $e($item['title']); ?>" readonly />

      <label class="rent-label" for="srvType">Service type</label>
      <input type="text" id="srvType" class="rent-input" value="<?php echo $e($item['term']); ?>" readonly />

      <?php if (count($item['units']) > 1): ?>
      <label class="rent-label" for="srvUnits"><?php echo $e(ucfirst($unitWord)); ?></label>
      <select id="srvUnits" class="rent-input">
        <?php foreach ($item['units'] as $n): ?>
        <option value="<?php echo (int) $n; ?>"<?php echo (int) $n === (int) $item['default_units'] ? ' selected' : ''; ?>><?php echo (int) $n . ' ' . $e(sf_unit_word($item['unit'], $n)); ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <div class="rent-grid">
        <div>
          <label class="rent-label" for="srvDate">Preferred date</label>
          <input type="date" id="srvDate" class="rent-input" min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime('+90 days')); ?>" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required />
        </div>
        <div>
          <label class="rent-label" for="srvTime">Preferred time</label>
          <select id="srvTime" class="rent-input">
            <?php for ($m = 7 * 60; $m <= 21 * 60; $m += 30): $t = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); ?>
            <option value="<?php echo $t; ?>"<?php echo $t === '10:00' ? ' selected' : ''; ?>><?php echo date('g:i A', strtotime($t)); ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>

      <label class="rent-label" for="srvLocation">Location</label>
      <input type="text" id="srvLocation" class="rent-input" maxlength="150" placeholder="Enter address or meetup spot" required />

      <label class="rent-label" for="srvDescription">Description</label>
      <textarea id="srvDescription" class="rent-input" rows="3" maxlength="800" placeholder="Describe what you need…"></textarea>

      <div class="rent-summary">
        <div class="rate-strong"><span>Base Service Rate:</span><strong><?php echo $e(sf_peso($item['price'])) . ' / ' . $e($unitOne === 'hour' ? 'hr' : $unitOne); ?></strong></div>
        <?php if (count($item['units']) > 1): ?><div class="total"><span>Estimated total:</span><strong id="srvTotal"></strong></div><?php endif; ?>
      </div>
      <p class="rent-error" id="srvError" role="alert" hidden></p>

      <div class="rent-actions">
        <button type="button" class="rent-cancel" onclick="closeServiceRequest()">Cancel</button>
        <button type="submit" class="rent-submit compact" id="srvSubmit">Submit Request</button>
      </div>
      <p class="rent-hint center">Your request is sent to the provider in Messages, and the booking goes to checkout for payment.</p>
    </form>
  </dialog>

  <script src="js/cart.js?v=5"></script>
  <script src="js/inbox.js?v=3"></script>
  <script src="js/report.js?v=2"></script>
  <script>
    const ITEM_ID = <?php echo json_encode($item['id']); ?>;
    const RATE = <?php echo json_encode($item['price']); ?>;
    const UNIT_OPTIONS = <?php echo json_encode(array_values($item['units'])); ?>;   // allowed hours / shoots / slides

    // Units stepper (e.g. 1–8 hours)
    let unitIndex = Math.max(0, UNIT_OPTIONS.indexOf(<?php echo (int) $item['default_units']; ?>));
    function changeUnits(delta) {
      unitIndex = Math.min(UNIT_OPTIONS.length - 1, Math.max(0, unitIndex + delta));
      const n = UNIT_OPTIONS[unitIndex];
      const val = document.getElementById('unitsVal');
      if (val) val.textContent = n;
      document.getElementById('serviceTotal').textContent = '₱' + (RATE * n).toLocaleString('en-US');
    }
    changeUnits(0);

    // ── Request Service modal ───────────────────────────────────
    // Submitting adds the booking to the cart, sends the provider the details in
    // Messages, then goes to checkout to pay.
    const SELLER_ID = <?php echo (int) $item['seller_id']; ?>;
    const ITEM_TITLE = <?php echo json_encode($item['title'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const UNIT_WORDS = <?php echo json_encode([sf_unit_word($item['unit'], 1), sf_unit_word($item['unit'], 2)]); ?>;
    const serviceModal = document.getElementById('serviceModal');
    const srvUnits = document.getElementById('srvUnits');   // only when there's a choice
    const peso = n => '₱' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const chosenUnits = () => srvUnits ? Number(srvUnits.value) : UNIT_OPTIONS[unitIndex];
    function updateServiceRequest() {
      const total = document.getElementById('srvTotal');
      if (total) total.textContent = peso(RATE * chosenUnits());
    }
    function openServiceRequest() {
      if (srvUnits) srvUnits.value = UNIT_OPTIONS[unitIndex];   // start from the page's choice
      document.getElementById('srvError').hidden = true;
      updateServiceRequest();
      serviceModal.showModal();
    }
    function closeServiceRequest() { serviceModal.close(); }
    serviceModal.addEventListener('click', e => { if (e.target === serviceModal) serviceModal.close(); });
    if (srvUnits) srvUnits.addEventListener('change', updateServiceRequest);

    document.getElementById('serviceForm').addEventListener('submit', async e => {
      e.preventDefault();
      const error = document.getElementById('srvError');
      const date = document.getElementById('srvDate');
      const where = document.getElementById('srvLocation');
      const fail = (text, field) => { error.textContent = text; error.hidden = false; field.focus(); };
      if (!date.value || date.value < date.min || date.value > date.max) return fail('Choose a date between today and ' + date.max + '.', date);
      if (!where.value.trim()) return fail('Enter where the service should happen.', where);

      const units = chosenUnits();
      const time = document.getElementById('srvTime');
      const note = document.getElementById('srvDescription').value.trim();
      const day = new Date(date.value + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
      const btn = document.getElementById('srvSubmit');
      btn.disabled = true;
      try {
        await SFCart.buyNow(ITEM_ID, 1, units);   // just this booking, the cart is left alone
        const text = 'Service request: ' + ITEM_TITLE + ' (' + units + ' ' + UNIT_WORDS[units === 1 ? 0 : 1] + ')\n'
          + 'When: ' + day + ', ' + time.options[time.selectedIndex].text + '\n'
          + 'Where: ' + where.value.trim() + '\n'
          + 'Estimated total ' + peso(RATE * units)
          + (note ? '\nDetails: ' + note : '');
        const res = await fetch('api/inbox_handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'send', to: SELLER_ID, text })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Your message could not be sent.');
        window.location.href = 'checkout.php';
      } catch (err) {
        // SFCart already showed why the booking couldn't be added; show message errors here
        if (err && err.message) { error.textContent = err.message; error.hidden = false; }
        btn.disabled = false;
      }
    });

    // Thumbnail switcher
    function switchMedia(btn, src) {
      document.querySelectorAll('.detail-thumb-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const mainImg = document.getElementById('mainDetailImg');
      if (mainImg) mainImg.src = src;
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
</body>
</html>