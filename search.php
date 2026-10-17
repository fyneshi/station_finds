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

$username = $_SESSION['username'] ?? 'User';
$email    = $_SESSION['email'] ?? '';
$initials = !empty($username) ? strtoupper(substr($username, 0, 2)) : 'MC';

require_once __DIR__ . '/includes/shop.php';

$search_query = trim((string) ($_GET['q'] ?? ''));

/* ──────────────────────────────────────────────────────────────────
   SEARCH
   Every word typed must appear somewhere in the listing (title, seller,
   condition, type, description, location, details or specs). An empty search shows everything.
   Title matches are listed first.
   ────────────────────────────────────────────────────────────────── */
$typeWords = [
    'products' => 'product products item buy',
    'rentals'  => 'rental rentals rent renting',
    'services' => 'service services hire',
];
$badgeTypes = ['products' => 'PRODUCT', 'rentals' => 'RENTAL', 'services' => 'SERVICE'];
$queryWords = preg_split('/\s+/', mb_strtolower($search_query), -1, PREG_SPLIT_NO_EMPTY);

$items = [];
foreach (sf_catalog() as $id => $listing) {
    $titleText = mb_strtolower($listing['title'] . ' ' . $listing['detail_title']);
    $haystack  = $titleText . ' ' . mb_strtolower(implode(' ', array_merge(
        [$listing['seller'], $listing['term'], $listing['description'], $listing['location'], $typeWords[$listing['type']]],
        array_keys($listing['details']), array_values($listing['details']), $listing['specs']
    )));

    $score = 0;
    foreach ($queryWords as $word) {
        if (mb_strpos($haystack, $word) === false) continue 2;    // a word is missing: not a match
        if (mb_strpos($titleText, $word) !== false) $score++;
    }

    $price = '₱' . number_format($listing['price']);
    if ($listing['from']) {
        $price = 'From ' . $price;
    } elseif ($listing['unit'] !== '') {
        $price .= '/' . $listing['unit'];
    }

    $items[] = [
        'id'            => $id,
        'type'          => $listing['type'],
        'title'         => $listing['title'],
        'price'         => $listing['price'],
        'price_display' => $price,
        'rating'        => $listing['rating'],
        'badge_type'    => $badgeTypes[$listing['type']],
        'badge_sub'     => mb_strtoupper($listing['term']),
        'stock'         => sf_stock_status($listing),   // [class, label] for products, null otherwise
        'image'         => $listing['image'],
        'link'          => $listing['link'],
        'score'         => $score,
    ];
}
// Best title matches first; otherwise keep catalog order
usort($items, fn($a, $b) => $b['score'] <=> $a['score']);

$total_count = count($items);
$products_count = count(array_filter($items, fn($i) => $i['type'] === 'products'));
$rentals_count  = count(array_filter($items, fn($i) => $i['type'] === 'rentals'));
$services_count = count(array_filter($items, fn($i) => $i['type'] === 'services'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Search results for "<?php echo htmlspecialchars($search_query); ?>" — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/search.css?v=3" />
</head>
<body>

  <!-- ==================== HEADER / NAVBAR ==================== -->
  <header class="navbar">
    <div class="nav-container">
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
          <a href="marketplace.php" class="nav-link">Marketplace</a>
          <a href="messages.php" class="nav-link">Messages</a>
          <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
          <a href="admin_dashboard.php" class="nav-link" style="color: #f7a827; font-weight: 700;">Admin Dashboard</a>
          <?php endif; ?>
        </nav>
      </div>

      <!-- Search Bar -->
      <div class="nav-center">
        <form action="search.php" method="GET" class="search-bar" onsubmit="handleNavSearch(event)">
          <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input
            type="text"
            name="q"
            id="navSearchInput"
            class="search-input"
            value="<?php echo htmlspecialchars($search_query); ?>"
            placeholder="Search books, gadgets, dorm find, bike rentals, tutors..."
          />
        </form>
      </div>

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

        <!-- User Menu -->
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
            <a href="account.php?tab=seller" class="dropdown-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line></svg>
              My Listings
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

  <!-- ==================== MAIN SEARCH CONTENT ==================== -->
  <main class="search-wrapper">
    <!-- Header Row -->
    <div class="search-header-row">
      <div>
        <h1 class="search-title">Search results</h1>
        <p class="search-subtitle">
          <span id="displayedCount"><?php echo $total_count; ?></span> results<?php if ($search_query !== ''): ?> for "<strong class="search-query-tag"><?php echo htmlspecialchars($search_query); ?></strong>"<?php endif; ?>
        </p>
      </div>

      <!-- Sort Dropdown -->
      <div class="sort-select-wrapper">
        <select class="sort-select" id="sortSelect" onchange="handleSort(this.value)">
          <option value="relevance" selected>Sort: Relevance</option>
          <option value="price-asc">Price: Low to High</option>
          <option value="price-desc">Price: High to Low</option>
          <option value="rating-desc">Rating: High to Low</option>
        </select>
        <svg class="sort-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
      </div>
    </div>

    <!-- Category Filter Pills -->
    <div class="filter-pills-row">
      <button type="button" class="filter-pill-btn active" data-type="all" onclick="filterSearch(this, 'all')">
        All (<?php echo $total_count; ?>)
      </button>
      <button type="button" class="filter-pill-btn" data-type="products" onclick="filterSearch(this, 'products')">
        Products (<?php echo $products_count; ?>)
      </button>
      <button type="button" class="filter-pill-btn" data-type="rentals" onclick="filterSearch(this, 'rentals')">
        Rentals (<?php echo $rentals_count; ?>)
      </button>
      <button type="button" class="filter-pill-btn" data-type="services" onclick="filterSearch(this, 'services')">
        Services (<?php echo $services_count; ?>)
      </button>
    </div>

    <!-- Results Grid -->
    <div class="search-grid" id="searchGrid">
      <?php foreach ($items as $item): ?>
      <article
        class="search-card"
        data-id="<?php echo htmlspecialchars($item['id']); ?>"
        data-type="<?php echo $item['type']; ?>"
        data-price="<?php echo $item['price']; ?>"
        data-rating="<?php echo $item['rating']; ?>"
        onclick="window.location.href='<?php echo htmlspecialchars($item['link'], ENT_QUOTES); ?>'"
      >
        <div class="card-media">
          <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="card-img" />
          <button type="button" class="card-fav-btn" aria-label="Save item" onclick="event.stopPropagation(); toggleFavorite(this)">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
            </svg>
          </button>
        </div>

        <div class="card-body">
          <div class="card-badges">
            <?php if ($item['badge_type'] === 'PRODUCT'): ?>
              <span class="badge-product"><?php echo $item['badge_type']; ?></span>
            <?php elseif ($item['badge_type'] === 'RENTAL'): ?>
              <span class="badge-rental"><?php echo $item['badge_type']; ?></span>
            <?php else: ?>
              <span class="badge-service"><?php echo $item['badge_type']; ?></span>
            <?php endif; ?>
            <span class="badge-subtle"><?php echo $item['badge_sub']; ?></span>
            <?php if ($item['stock']): ?><span class="badge-stock-<?php echo $item['stock'][0]; ?>"><?php echo htmlspecialchars(mb_strtoupper($item['stock'][1])); ?></span><?php endif; ?>
          </div>

          <h3 class="card-title"><?php echo htmlspecialchars($item['title']); ?></h3>

          <div class="card-footer">
            <span class="card-price"><?php echo htmlspecialchars($item['price_display']); ?></span>
            <div class="card-rating">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
              <span><?php echo number_format($item['rating'], 1); ?></span>
            </div>
          </div>
        </div>
      </article>
      <?php endforeach; ?>

      <?php if (!$items): ?>
      <div class="no-results-box">
        <svg class="no-results-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <h2 class="no-results-title">No results for "<?php echo htmlspecialchars($search_query); ?>"</h2>
        <p class="no-results-desc">Try a different word, or <a href="marketplace.php">browse the marketplace</a>.</p>
      </div>
      <?php endif; ?>
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

  <!-- Favorite hearts (saved to the database, shared with Home / Marketplace / Favorites) -->
  <script src="js/favorites.js?v=3"></script>
  <script src="js/cart.js?v=5"></script>
  <script src="js/inbox.js?v=3"></script>
  <script>
    let currentFilter = 'all';

    // Filter by category pills
    function filterSearch(btn, type) {
      currentFilter = type;
      document.querySelectorAll('.filter-pills-row .filter-pill-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      const cards = document.querySelectorAll('.search-card');
      let visibleCount = 0;

      cards.forEach(card => {
        const cardType = card.getAttribute('data-type');
        const show = (type === 'all' || cardType === type);
        card.style.display = show ? 'flex' : 'none';
        if (show) visibleCount++;
      });

      document.getElementById('displayedCount').textContent = visibleCount;
    }

    // Sort listings
    function handleSort(sortVal) {
      const grid = document.getElementById('searchGrid');
      const cards = Array.from(grid.querySelectorAll('.search-card'));

      cards.sort((a, b) => {
        if (sortVal === 'price-asc') {
          return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
        } else if (sortVal === 'price-desc') {
          return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
        } else if (sortVal === 'rating-desc') {
          return parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating);
        } else {
          return parseInt(a.dataset.id) - parseInt(b.dataset.id);
        }
      });

      cards.forEach(card => grid.appendChild(card));
    }

    // Navigation search handler
    function handleNavSearch(e) {
      e.preventDefault();
      const q = document.getElementById('navSearchInput').value.trim();
      window.location.href = 'search.php?q=' + encodeURIComponent(q);
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