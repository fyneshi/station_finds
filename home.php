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

// Location filter (?loc=): every location sellers use; listings without one are "not specified"
$locOptions = sf_location_options();
$loc        = sf_location_param($locOptions);
$locLabel   = sf_location_label($locOptions, $loc);
$listings   = sf_filter_location(sf_catalog(), $loc);
$locQuery   = $loc !== '' ? '&loc=' . rawurlencode($loc) : '';

// Today's picks: the newest listings (leaving out products that are out of stock).
// With a location picked, every listing there is shown.
$inStock = array_filter($listings, fn($item) => $item['type'] !== 'products' || $item['stock'] > 0);
$picks   = $loc === '' ? array_slice($inStock, 0, 8, true) : $inStock;   // 4 show; very wide screens show up to 8 (css/responsive.css)
$listingCounts = array_count_values(array_column($listings, 'type')) + ['products' => 0, 'rentals' => 0, 'services' => 0];
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$username = $_SESSION['username'] ?? 'User';
$email    = $_SESSION['email'] ?? '';
// User initials for the top right avatar (e.g. "YO" or "MC")
$initials = !empty($username) ? strtoupper(substr($username, 0, 2)) : 'MC';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Station Finds — Home</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/home.css?v=8" />
  <link rel="stylesheet" href="css/header.css?v=1" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
  <script>
    // Home animations (js/home-motion.js): hide cards only when they'll be animated in.
    // If that script doesn't run, everything is shown after a moment anyway.
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      document.documentElement.classList.add('js-motion');
      setTimeout(function () { if (!window.sfHomeMotion) document.documentElement.classList.remove('js-motion'); }, 2500);
    }
  </script>
</head>
<body>

  <!-- ==================== HEADER / NAVBAR ==================== -->
  <?php sf_render_navbar(sf_cart_count($conn, (int) ($_SESSION['user_id'] ?? 0)), 'home'); ?>

  <!-- ==================== MAIN CONTENT ==================== -->
  <main class="main-wrapper">
    <div class="main-container">

      <!-- Location filter: shows only listings in the chosen place -->
      <form method="get" action="home.php" class="location-filter">
        <label class="location-pill">
          <svg class="location-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
            <circle cx="12" cy="10" r="3"></circle>
          </svg>
          <span class="sr-only">Show listings in</span>
          <select name="loc" class="location-select" onchange="this.form.submit()">
            <option value="">All locations</option>
            <?php foreach ($locOptions as $key => $label): ?>
            <option value="<?php echo $e($key); ?>"<?php echo $loc === $key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
            <?php endforeach; ?>
          </select>
          <svg class="location-caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </label>
        <noscript><button type="submit" class="location-go">Show</button></noscript>
        <?php if ($loc !== ''): ?>
        <a href="home.php" class="location-clear">Clear</a>
        <?php endif; ?>
      </form>

      <!-- ==================== SECTION: TODAY'S PICKS ==================== -->
      <section class="picks-section">
        <h2 class="section-title"><?php echo $loc === '' ? 'Today\'s picks for you' : 'Listings in ' . $e($locLabel); ?></h2>

        <div class="picks-grid<?php echo $loc !== '' ? ' picks-all' : ''; ?>">
          <?php foreach ($picks as $id => $pick):
            $isPhoto = $pick['image'] !== SF_PLACEHOLDER_IMAGE;
          ?>
          <article class="product-card" style="cursor: pointer;"
                   onclick="window.location.href='<?php echo $e($pick['link']); ?>'"
                   data-type="<?php echo $e($pick['type']); ?>"
                   data-title="<?php echo $e($pick['title']); ?>"
                   data-price="<?php echo $e($pick['price']); ?>"
                   data-price-display="<?php echo $e(sf_peso($pick['price']) . ($pick['unit'] !== '' ? '/' . $pick['unit'] : '')); ?>"
                   data-badge-sub="<?php echo $e(mb_strtoupper($pick['term'])); ?>"
                   data-link="<?php echo $e($pick['link']); ?>">
            <div class="card-media">
              <?php if ($isPhoto): ?>
              <img src="<?php echo $e($pick['image']); ?>" alt="<?php echo $e($pick['title']); ?>" class="card-img" loading="lazy" />
              <?php else: ?>
              <div class="mockup-img-placeholder">
                <svg class="mockup-placeholder-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                  <circle cx="8.5" cy="8.5" r="1.5"></circle>
                  <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
                <span class="mockup-placeholder-text">No photo</span>
              </div>
              <?php endif; ?>
              <button type="button" class="card-favorite-btn" aria-label="Save item" onclick="event.stopPropagation(); toggleFavorite(this)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                </svg>
              </button>
            </div>
            <div class="card-content">
              <div class="card-badges">
                <span class="badge badge-<?php echo ['products' => 'product', 'rentals' => 'rental', 'services' => 'service'][$pick['type']]; ?>"><?php echo ['products' => 'PRODUCT', 'rentals' => 'RENTAL', 'services' => 'SERVICE'][$pick['type']]; ?></span>
                <span class="badge badge-condition"><?php echo $e(mb_strtoupper($pick['term'])); ?></span>
                <?php if ($stock = sf_stock_status($pick)): ?><span class="badge badge-stock-<?php echo $stock[0]; ?>"><?php echo $e(mb_strtoupper($stock[1])); ?></span><?php endif; ?>
              </div>
              <h3 class="card-product-title"><?php echo $e($pick['title']); ?></h3>
              <div class="card-footer-info">
                <span class="card-price"><?php echo $e(sf_peso($pick['price'])); ?><?php if ($pick['unit'] !== ''): ?><span class="price-unit">/<?php echo $e($pick['unit']); ?></span><?php endif; ?></span>
              </div>
            </div>
          </article>
          <?php endforeach; ?>

          <?php if (!$picks && $loc !== ''): ?>
          <p class="picks-empty">Nothing available in <?php echo $e($locLabel); ?> right now. <a href="home.php">Show all locations</a></p>
          <?php elseif (!$picks): ?>
          <p class="picks-empty">No listings yet. <a href="listing_form.php">Be the first to sell something</a> or <a href="marketplace.php">browse the marketplace</a>.</p>
          <?php endif; ?>
        </div>
      </section>

      <!-- ==================== SECTION: BROWSE BY CATEGORY ==================== -->
      <section class="category-section">
        <h2 class="section-title">Browse by category</h2>

        <div class="category-grid">
          <!-- Category 1: Products -->
          <div class="category-card" onclick="window.location.href='marketplace.php?tab=products<?php echo $e($locQuery); ?>'" style="cursor: pointer;">
            <div class="category-card-top">
              <h3 class="category-name">Products</h3>
              <span class="category-count"><?php echo number_format($listingCounts['products']) . ' listing' . ($listingCounts['products'] === 1 ? '' : 's'); ?></span>
            </div>
            <p class="category-desc">Buy &amp; sell textbooks, dorm supplies, electronics, clothes.</p>
            <a href="marketplace.php?tab=products" class="category-explore-link">
              Explore category
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </a>
          </div>

          <!-- Category 2: Rental -->
          <div class="category-card" onclick="window.location.href='marketplace.php?tab=rentals<?php echo $e($locQuery); ?>'" style="cursor: pointer;">
            <div class="category-card-top">
              <h3 class="category-name">Rental</h3>
              <span class="category-count"><?php echo number_format($listingCounts['rentals']) . ' listing' . ($listingCounts['rentals'] === 1 ? '' : 's'); ?></span>
            </div>
            <p class="category-desc">Rent bikes, scientific calculators, gowns, photography gear.</p>
            <a href="marketplace.php?tab=rentals" class="category-explore-link">
              Explore category
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </a>
          </div>

          <!-- Category 3: Services -->
          <div class="category-card" onclick="window.location.href='marketplace.php?tab=services<?php echo $e($locQuery); ?>'" style="cursor: pointer;">
            <div class="category-card-top">
              <h3 class="category-name">Services</h3>
              <span class="category-count"><?php echo number_format($listingCounts['services']) . ' listing' . ($listingCounts['services'] === 1 ? '' : 's'); ?></span>
            </div>
            <p class="category-desc">Hire tutors, laundry services, room cleaners, moving help.</p>
            <a href="marketplace.php?tab=services" class="category-explore-link">
              Explore category
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </a>
          </div>
        </div>
      </section>

    </div>
  </main>

  <!-- ==================== FOOTER ==================== -->
  <footer class="footer">
    <div class="footer-container">
      <div class="footer-top">
        <!-- Logo Emblem -->
        <div class="footer-brand-col">
          <div class="footer-emblem-badge">
            <img src="assets/images/logo2.png" alt="Station Finds Emblem" class="footer-emblem-img" />
          </div>
        </div>

        <!-- Links Column 1: About Us -->
        <div class="footer-col">
          <h4 class="footer-heading"><a href="about.php" class="footer-heading-link">ABOUT US</a></h4>
          <ul class="footer-links-list">
            <li><a href="about.php#get-to-know-us" class="footer-link">Get to know us</a></li>
            <li><a href="about.php#our-commitment" class="footer-link">Our commitment</a></li>
          </ul>
        </div>

        <!-- Links Column 2: Contact Us -->
        <div class="footer-col">
          <h4 class="footer-heading"><a href="contact.php" class="footer-heading-link">CONTACT US</a></h4>
          <ul class="footer-links-list">
            <li class="footer-text-item">Katipunan Ave, Quezon City, PH</li>
            <li><a href="mailto:support@stationfinds.edu.ph" class="footer-link">support@stationfinds.edu.ph</a></li>
            <li class="footer-text-item">+63 (2) 8123&ndash;4567</li>
          </ul>
        </div>

        <!-- Links Column 3: Policies -->
        <div class="footer-col">
          <h4 class="footer-heading"><a href="policies.php" class="footer-heading-link">POLICIES</a></h4>
          <ul class="footer-links-list">
            <li><a href="policies.php?page=privacy" class="footer-link">Privacy policy</a></li>
            <li><a href="policies.php?page=terms" class="footer-link">Terms of service</a></li>
            <li><a href="policies.php?page=refund" class="footer-link">Refund policy</a></li>
          </ul>
        </div>
      </div>

      <!-- Divider line -->
      <div class="footer-divider"></div>

      <!-- Bottom Copyright -->
      <div class="footer-bottom">
        <p>&copy; 2026 STATIONFINDS. Developed for student safety, affordability, and convenience.</p>
      </div>
    </div>
  </footer>

  <!-- Favorite hearts (saved to the database, shared with Marketplace / Search / Favorites) -->
  <script src="js/favorites.js?v=4"></script>
  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <script src="js/home-motion.js?v=2"></script>
  <script>
    function toggleUserDropdown() {
      const dropdown = document.getElementById('userDropdown');
      dropdown.classList.toggle('show');
    }

    // Close dropdown on outside click
    document.addEventListener('click', function(e) {
      const menuWrapper = document.querySelector('.user-menu-wrapper');
      if (menuWrapper && !menuWrapper.contains(e.target)) {
        const dropdown = document.getElementById('userDropdown');
        if (dropdown) dropdown.classList.remove('show');
      }
    });
  </script>
  <?php sf_render_tabbar('home'); ?>
</body>
</html>
