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

require_once 'shop.php';

// Today's picks: the newest listings from sellers
$picks = array_slice(sf_catalog(), 0, 4, true);
$listingCounts = array_count_values(array_column(sf_catalog(), 'type')) + ['products' => 0, 'rentals' => 0, 'services' => 0];
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
  <link rel="stylesheet" href="css/home.css?v=5" />
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
          <a href="home.php" class="nav-link active">Home</a>
          <a href="marketplace.php" class="nav-link">Marketplace</a>
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
          <input
            type="text"
            name="q"
            class="search-input"
            placeholder="Search books, gadgets, dorm find, bike rentals, tutors..."
          />
        </form>
      </div>

      <!-- Right: Action Icons & User Avatar -->
      <div class="nav-right">
        <!-- Notification Bell -->
        <a href="notifications.php" class="icon-btn" title="Notifications" aria-label="Notifications">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          </svg>
        </a>

        <!-- Wishlist Heart -->
        <a href="favorites.php" class="icon-btn" title="Saved Items" aria-label="Saved Items">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
          </svg>
        </a>

        <!-- Shopping Cart with Badge -->
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
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
              </svg>
              My Profile
            </a>
            <a href="account.php?tab=seller" class="dropdown-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                <line x1="3" y1="6" x2="21" y2="6"></line>
              </svg>
              My Listings
            </a>
            <div class="dropdown-divider"></div>
            <a href="logout.php" class="dropdown-item logout-link">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
              </svg>
              Log out
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- ==================== MAIN CONTENT ==================== -->
  <main class="main-wrapper">
    <div class="main-container">

      <!-- Location Badge Pill -->
      <div class="location-pill">
        <svg class="location-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
          <circle cx="12" cy="10" r="3"></circle>
        </svg>
        <span class="location-text"><strong>Quezon City, PH</strong></span>
      </div>

      <!-- ==================== SECTION: TODAY'S PICKS ==================== -->
      <section class="picks-section">
        <h2 class="section-title">Today's picks for you</h2>

        <div class="picks-grid">
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

          <?php if (!$picks): ?>
          <p class="picks-empty">No listings yet. <a href="listing_form.php">Be the first to sell something</a> or <a href="marketplace.php">browse the marketplace</a>.</p>
          <?php endif; ?>
        </div>
      </section>

      <!-- ==================== SECTION: BROWSE BY CATEGORY ==================== -->
      <section class="category-section">
        <h2 class="section-title">Browse by category</h2>

        <div class="category-grid">
          <!-- Category 1: Products -->
          <div class="category-card" onclick="window.location.href='marketplace.php?tab=products'" style="cursor: pointer;">
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
          <div class="category-card" onclick="window.location.href='marketplace.php?tab=rentals'" style="cursor: pointer;">
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
          <div class="category-card" onclick="window.location.href='marketplace.php?tab=services'" style="cursor: pointer;">
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
  <script src="js/favorites.js?v=2"></script>
  <script src="js/cart.js?v=4"></script>
  <script src="js/inbox.js?v=2"></script>
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
</body>
</html>
