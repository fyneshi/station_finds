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

// Which rental to show: rental_detail.php?id=<listing id>
// (unknown or missing id = back to the marketplace)
$item = sf_get_item($_GET['id'] ?? '', 'rentals');
if (!$item) {
    header('Location: marketplace.php?tab=rentals');
    exit;
}

$username = $_SESSION['username'] ?? 'User';
$email    = $_SESSION['email'] ?? '';
$initials = !empty($username) ? strtoupper(substr($username, 0, 2)) : 'MC';

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$photos = sf_gallery($item);
$isPlaceholder = $item['image'] === SF_PLACEHOLDER_IMAGE;

$userId    = (int) ($_SESSION['user_id'] ?? 0);
$listingId = (int) substr($item['id'], 8);
$pageLink  = 'rental_detail.php?id=' . $item['id'];
$isOwner   = $item['seller_id'] === $userId;
// Renting needs a verified ID: '' (guests see Log in instead) | verified | pending | none
$idState = '';
if ($userId && !$isOwner) {
    $idRow   = $conn->query("SELECT id_status FROM users WHERE id = " . $userId)->fetch_assoc();
    $idState = sf_id_verified($conn, $userId) ? 'verified' : (($idRow['id_status'] ?? '') === 'pending' ? 'pending' : 'none');
}

// Questions & Comments: post a question or a reply (comments.php), then come back to the list
[$commentError, $commentDraft] = sf_handle_comment_post($conn, $item, $userId, $pageLink);
$comments  = sf_listing_comments($conn, $listingId);
$reviews   = sf_listing_reviews($conn, $item['id']);
$csrf      = sf_csrf_token();
$condition = SF_CONDITIONS[$item['condition']] ?? '';
$when      = fn($ts) => date('M j, Y', $ts);
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
  <link rel="stylesheet" href="css/detail.css?v=15" />
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
      <a href="marketplace.php?tab=rentals" class="breadcrumb-link">Rentals</a>
      <span class="breadcrumb-sep">&rsaquo;</span>
      <span class="breadcrumb-current"><?php echo $e($item['title']); ?></span>
    </nav>
  </div>

  <!-- ==================== MAIN CONTENT ==================== -->
  <main class="detail-wrapper">
    <div class="detail-grid">

      <!-- LEFT COLUMN: PHOTOS, QUESTIONS, REVIEWS -->
      <div class="detail-media-col">
        <div class="detail-main-img-box">
          <img id="mainDetailImg" src="<?php echo $e($photos[0]['main']); ?>" alt="<?php echo $e($item['detail_title']); ?>" class="detail-main-img"<?php echo $isPlaceholder ? ' style="object-fit: contain; padding: 40px;"' : ''; ?> />
        </div>

        <?php if (count($photos) > 1): ?>
        <div class="detail-thumbnails-row">
          <?php foreach ($photos as $i => $photo): ?>
          <button type="button" class="detail-thumb-btn<?php echo $i === 0 ? ' active' : ''; ?>" onclick="switchMedia(this, '<?php echo $e($photo['main']); ?>')" aria-label="Show photo <?php echo $i + 1; ?>">
            <img src="<?php echo $e($photo['thumb']); ?>" alt="" class="detail-thumb-img" />
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Comments & Suggestions (saved; comments.php) -->
        <?php sf_render_comments($item, $comments, $pageLink, $csrf, $commentError, $commentDraft, ['Questions & Comments', 'Ask a question before booking…', 'No questions yet. Ask the owner anything before you book.']); ?>

        <!-- Ratings & Reviews -->
        <?php sf_render_reviews($item, $reviews); ?>
      </div>

      <!-- RIGHT COLUMN: RENTAL INFO, BOOKING, OWNER, REPORTS -->
      <div class="detail-info-col">
        <div class="detail-badges">
          <span class="badge-gold">RENTAL</span>
          <span class="badge-subtle">AVAILABLE NOW</span>
        </div>

        <h1 class="detail-title"><?php echo $e($item['detail_title']); ?></h1>
        <?php if ($item['rating'] > 0): ?>
        <!-- Rating (same as the product and service pages) -->
        <div class="detail-rating-row">
          <span class="rating-star">★ <?php echo number_format($item['rating'], 1); ?></span>
          <?php if ($item['reviews'] > 0): ?>
          <span class="rating-count">(<?php echo (int) $item['reviews']; ?> review<?php echo $item['reviews'] == 1 ? '' : 's'; ?>)</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="detail-price-main"><?php echo sf_price_html($item); ?></div>

        <?php if ($item['description'] !== ''): ?>
        <!-- Description (same section as the product and service pages) -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Description</h4>
          <p class="detail-description"><?php echo nl2br($e($item['description'])); ?></p>
        </div>
        <?php endif; ?>

        <!-- Rental specifications -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Rental specifications</h4>
          <div class="specs-grid-2x2">
            <div class="spec-prop"><span class="spec-label">Category:</span> <span class="spec-value"><?php echo $e($item['details']['Category'] ?? 'Other'); ?></span></div>
            <div class="spec-prop"><span class="spec-label">Condition:</span> <span class="spec-value"><?php echo $e($condition ?: '—'); ?></span></div>
            <div class="spec-prop"><span class="spec-label">Location:</span> <span class="spec-value"><?php echo $e($item['location'] ?: 'Ask the owner'); ?></span></div>
            <div class="spec-prop"><span class="spec-label">Rent for:</span> <span class="spec-value"><?php echo (int) min($item['units']) . '–' . (int) max($item['units']); ?> days</span></div>
          </div>
        </div>

        <!-- Duration & dates -->
        <div class="detail-section">
          <h4 class="detail-section-heading small">Select duration &amp; dates</h4>
          <div class="booking-row">
            <select id="pageDays" class="booking-control" aria-label="Rental duration">
              <?php foreach ($item['units'] as $days): ?>
              <option value="<?php echo (int) $days; ?>"<?php echo (int) $days === (int) $item['default_units'] ? ' selected' : ''; ?>><?php echo (int) $days . ($days == 1 ? ' Day' : ' Days'); ?></option>
              <?php endforeach; ?>
            </select>
            <label class="booking-control booking-dates">
              <span id="pageRange"></span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
              <input type="date" id="pageStart" min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime('+60 days')); ?>" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" aria-label="Start date" />
            </label>
          </div>
          <p class="booking-total">Estimated total: <strong id="rentalTotal"></strong></p>
        </div>

        <?php if ($isOwner): ?>
        <a href="listing_form.php?id=<?php echo $listingId; ?>" class="btn-primary-full as-link">This is your listing · Edit it</a>
        <?php else: ?>
        <?php if ($idState === 'none'): ?>
        <a href="<?php echo SF_VERIFY_LINK; ?>" class="btn-primary-full as-link">Verify your ID to rent</a>
        <p class="id-verify-note">Renting needs a verified ID. Upload a valid ID once; an admin checks it, then you can rent anything.</p>
        <?php elseif ($idState === 'pending'): ?>
        <button type="button" class="btn-primary-full" disabled>Request rental</button>
        <p class="id-verify-note">Your ID is being reviewed. You can request rentals as soon as an admin approves it.</p>
        <?php else: ?>
        <button type="button" class="btn-primary-full" id="buyNowBtn" onclick="openRentalRequest()">Request rental</button>
        <?php endif; ?>
        <a href="<?php echo $item['seller_id'] ? 'messages.php?with=' . $item['seller_id'] : 'messages.php'; ?>" class="detail-message-link">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
          Message owner about terms &amp; deposit
        </a>

        <!-- Report listing -->
        <form class="report-card" data-report-type="listing" data-report-id="<?php echo $listingId; ?>" novalidate>
          <div class="report-card-head"><h4>Report Listing</h4><span>Listing content</span></div>
          <label for="reportListingReason">Reason</label>
          <select id="reportListingReason" name="reason" required>
            <option value="">Choose a reason…</option>
            <?php foreach (SF_REPORT_REASONS['listing'] as $reason): ?><option><?php echo $e($reason); ?></option><?php endforeach; ?>
          </select>
          <label for="reportListingDetails">Details</label>
          <textarea id="reportListingDetails" name="details" maxlength="1000" placeholder="Add context about the listing, photos, or communication…"></textarea>
          <div class="report-card-foot"><span>Open → Escalated / Resolved</span><button type="submit">Submit</button></div>
          <p class="report-card-msg" role="status"></p>
          <p class="report-card-note">Content Moderation notice: Reports are reviewed by admins and may result in listing removal or account action.</p>
        </form>
        <?php endif; ?>

        <!-- Owner information -->
        <div class="detail-section">
          <h4 class="detail-section-heading">Owner information</h4>
          <div class="seller-card">
            <div class="seller-left">
              <div class="presence"><div class="seller-avatar avatar-mj"><?php echo sf_avatar_html($item['seller'], $item['seller_avatar']); ?></div><?php echo sf_online_dot($item['seller_presence']); ?></div>
              <div class="seller-info">
                <h5 class="seller-name"><?php if ($item['seller_id']): ?><a href="profile.php?id=<?php echo (int) $item['seller_id']; ?>" class="seller-profile-link"><?php echo $e($item['seller']); ?></a><?php else: ?><?php echo $e($item['seller']); ?><?php endif; ?></h5>
                <span class="seller-meta"><?php echo $e($item['seller_meta']); ?></span>
                <?php $active = sf_active_text($item['seller_presence']); if ($active !== ''): ?><span class="seller-active<?php echo $active === 'Active now' ? ' active-now' : ''; ?>"><?php echo $active; ?></span><?php endif; ?>
              </div>
            </div>
            <?php if ($item['seller_rating'] > 0): ?>
            <div class="seller-rating">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
              <span><?php echo number_format($item['seller_rating'], 1); ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$isOwner && $item['seller_id']): ?>
        <!-- Report user -->
        <form class="report-card" data-report-type="user" data-report-id="<?php echo (int) $item['seller_id']; ?>" novalidate>
          <div class="report-card-head"><h4>Report User</h4><span>Owner conduct</span></div>
          <label for="reportUserReason">Reason</label>
          <select id="reportUserReason" name="reason" required>
            <option value="">Choose a reason…</option>
            <?php foreach (SF_REPORT_REASONS['user'] as $reason): ?><option><?php echo $e($reason); ?></option><?php endforeach; ?>
          </select>
          <label for="reportUserDetails">Details</label>
          <textarea id="reportUserDetails" name="details" maxlength="1000" placeholder="Describe the issue and include any relevant messages or dates…"></textarea>
          <div class="report-card-foot"><span>Open → Escalated / Resolved</span><button type="submit">Submit</button></div>
          <p class="report-card-msg" role="status"></p>
          <p class="report-card-note">Content Moderation notice: Reports are reviewed by admins and may result in listing removal or account action.</p>
        </form>
        <?php endif; ?>
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

  <!-- ==================== REQUEST RENTAL MODAL ==================== -->
  <dialog class="rent-modal" id="rentModal" aria-labelledby="rentTitle">
    <form id="rentForm" novalidate>
      <div class="rent-modal-head">
        <h2 id="rentTitle">Request Rental</h2>
        <button type="button" class="rent-close" onclick="closeRentalRequest()" aria-label="Close">&times;</button>
      </div>
      <label class="rent-label" for="rentItem">Item</label>
      <input type="text" id="rentItem" class="rent-input" value="<?php echo $e($item['title']); ?>" readonly />

      <label class="rent-label" for="rentDays">Duration</label>
      <select id="rentDays" class="rent-input">
        <?php foreach ($item['units'] as $days): ?>
        <option value="<?php echo (int) $days; ?>"<?php echo (int) $days === (int) $item['default_units'] ? ' selected' : ''; ?>><?php echo (int) $days . ($days == 1 ? ' Day' : ' Days'); ?></option>
        <?php endforeach; ?>
      </select>

      <div class="rent-grid">
        <div>
          <label class="rent-label" for="rentStart">Start date</label>
          <input type="date" id="rentStart" class="rent-input" min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime('+60 days')); ?>" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required />
        </div>
        <div>
          <label class="rent-label" for="rentEnd">End date</label>
          <input type="date" id="rentEnd" class="rent-input" readonly tabindex="-1" aria-describedby="rentEndHint" />
        </div>
      </div>
      <p class="rent-hint" id="rentEndHint">The end date follows from the start date and duration.</p>

      <span class="rent-label" id="rentOptionLabel">Pickup / Delivery Option</span>
      <div class="rent-options" role="radiogroup" aria-labelledby="rentOptionLabel">
        <label><input type="radio" name="rentOption" value="Pickup on campus" checked /> Pickup on campus</label>
        <label><input type="radio" name="rentOption" value="Delivery" /> Delivery</label>
      </div>

      <label class="rent-label" for="rentMessage">Message for owner</label>
      <textarea id="rentMessage" class="rent-input" rows="3" maxlength="500" placeholder="Add a message for the owner…"></textarea>

      <div class="rent-summary">
        <div><span>Rate details:</span><strong id="rentRate"></strong></div>
        <div class="total"><span>Estimated Total:</span><strong id="rentTotal"></strong></div>
      </div>
      <p class="rent-error" id="rentError" role="alert" hidden></p>

      <div class="rent-actions">
        <button type="button" class="rent-cancel" onclick="closeRentalRequest()">Cancel</button>
        <button type="submit" class="rent-submit" id="rentSubmit">Submit Request</button>
      </div>
      <p class="rent-hint center">Your request is sent to the owner in Messages, and the rental goes to checkout for payment.</p>
    </form>
  </dialog>

  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <script src="js/report.js?v=3"></script>
  <script>
    const ITEM_ID = <?php echo json_encode($item['id']); ?>;
    const DAILY_RATE = <?php echo json_encode($item['price']); ?>;
    const DAY_OPTIONS = <?php echo json_encode(array_values($item['units'])); ?>;   // allowed rental lengths

    // Thumbnail switcher
    function switchMedia(btn, src) {
      document.querySelectorAll('.detail-thumb-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const mainImg = document.getElementById('mainDetailImg');
      if (mainImg) mainImg.src = src;
    }

    // Duration & dates on the page: the range, the estimate, and what the modal starts from
    const pageDays  = document.getElementById('pageDays');
    const pageStart = document.getElementById('pageStart');
    const addDays = (iso, n) => {
      const d = new Date(iso + 'T00:00:00');
      d.setDate(d.getDate() + n);
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    };
    const shortDate = iso => new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    function updatePageBooking() {
      const days = Number(pageDays.value);
      if (pageStart.value) document.getElementById('pageRange').textContent = shortDate(pageStart.value) + ' – ' + shortDate(addDays(pageStart.value, days));
      document.getElementById('rentalTotal').textContent = '₱' + (DAILY_RATE * days).toLocaleString('en-US');
    }
    pageDays.addEventListener('change', updatePageBooking);
    pageStart.addEventListener('change', updatePageBooking);
    // The whole date box opens the calendar
    pageStart.closest('label').addEventListener('click', e => {
      if (e.target !== pageStart && pageStart.showPicker) { e.preventDefault(); try { pageStart.showPicker(); } catch (_) { pageStart.focus(); } }
    });
    updatePageBooking();

    // ── Request Rental modal ────────────────────────────────────
    // Submitting adds the rental to the cart for the chosen days, sends the owner the
    // details in Messages, then goes to checkout to pay.
    const SELLER_ID = <?php echo (int) $item['seller_id']; ?>;
    const ITEM_TITLE = <?php echo json_encode($item['title'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const rentModal = document.getElementById('rentModal');
    const peso = n => '₱' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const longDate = iso => new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

    function updateRentalRequest() {
      const days = Number(document.getElementById('rentDays').value);
      const start = document.getElementById('rentStart').value;
      if (start) {
        const end = new Date(start + 'T00:00:00');
        end.setDate(end.getDate() + days);
        document.getElementById('rentEnd').value = end.getFullYear() + '-' + String(end.getMonth() + 1).padStart(2, '0') + '-' + String(end.getDate()).padStart(2, '0');
      }
      document.getElementById('rentRate').textContent = peso(DAILY_RATE) + ' / day × ' + days + (days === 1 ? ' day' : ' days');
      document.getElementById('rentTotal').textContent = peso(DAILY_RATE * days);
    }
    function openRentalRequest() {
      // Start from the duration and dates picked on the page
      document.getElementById('rentDays').value = pageDays.value;
      if (pageStart.value) document.getElementById('rentStart').value = pageStart.value;
      document.getElementById('rentError').hidden = true;
      updateRentalRequest();
      rentModal.showModal();
    }
    function closeRentalRequest() { rentModal.close(); }
    rentModal.addEventListener('click', e => { if (e.target === rentModal) rentModal.close(); });
    document.getElementById('rentDays').addEventListener('change', updateRentalRequest);
    document.getElementById('rentStart').addEventListener('change', updateRentalRequest);

    document.getElementById('rentForm').addEventListener('submit', async e => {
      e.preventDefault();
      const error = document.getElementById('rentError');
      const start = document.getElementById('rentStart');
      if (!start.value || start.value < start.min || start.value > start.max) {
        error.textContent = 'Choose a start date between today and ' + longDate(start.max) + '.';
        error.hidden = false;
        start.focus();
        return;
      }
      const days = Number(document.getElementById('rentDays').value);
      const option = document.querySelector('input[name="rentOption"]:checked').value;
      const note = document.getElementById('rentMessage').value.trim();
      const btn = document.getElementById('rentSubmit');
      btn.disabled = true;
      try {
        await SFCart.buyNow(ITEM_ID, 1, days);   // just this rental, the cart is left alone
        const text = 'Rental request: ' + ITEM_TITLE + '\n'
          + 'Dates: ' + longDate(start.value) + ' to ' + longDate(document.getElementById('rentEnd').value) + ' (' + days + (days === 1 ? ' day' : ' days') + ')\n'
          + option + ' · Estimated total ' + peso(DAILY_RATE * days)
          + (note ? '\nMessage: ' + note : '');
        const res = await fetch('api/inbox_handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'send', to: SELLER_ID, text })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Your message could not be sent.');
        window.location.href = 'checkout.php';
      } catch (err) {
        // SFCart already showed why the rental couldn't be added; show message errors here
        if (err && err.message) { error.textContent = err.message; error.hidden = false; }
        btn.disabled = false;
      }
    });

    // Report Listing / Report User: sent to the admins' Content Moderation
    document.querySelectorAll('.report-card').forEach(form => form.addEventListener('submit', async e => {
      e.preventDefault();
      const msg = form.querySelector('.report-card-msg');
      const reason = form.elements.reason;
      const show = (text, ok) => { msg.textContent = text; msg.className = 'report-card-msg ' + (ok ? 'ok' : 'err'); };
      if (!reason.value) { show('Choose a reason first.', false); reason.focus(); return; }
      const btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      try {
        const res = await fetch('api/report_handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'report', type: form.dataset.reportType, id: Number(form.dataset.reportId),
                                 reason: reason.value, details: form.elements.details.value.trim() })
        });
        const data = await res.json();
        show(data.message || (data.success ? 'Thanks, your report was sent.' : 'Your report could not be sent.'), !!data.success);
        if (data.success) form.reset();
      } catch (err) {
        show('Your report could not be sent. Please try again.', false);
      }
      btn.disabled = false;
    }));

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