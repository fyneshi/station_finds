<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?next=' . rawurlencode(basename($_SERVER['REQUEST_URI'] ?? '')));
    exit;
}

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';

$username = $_SESSION['username'] ?? 'User';
$email    = $_SESSION['email'] ?? '';
$initials = !empty($username) ? strtoupper(substr($username, 0, 2)) : 'MC';

// Load this user's saved items (newest first)
$favorites = [];
$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare(
    "SELECT item_key, item_type, title, price, price_display, rating, badge_sub, image, link
     FROM favorites WHERE user_id = ? ORDER BY created_at DESC, id DESC"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $favorites[] = $row;
}
$stmt->close();

// Show the listing's current cover photo (it may have been added or changed since it was saved)
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/layout.php';
foreach ($favorites as &$fav) {
    parse_str((string) parse_url($fav['link'], PHP_URL_QUERY), $query);
    $listing = sf_get_item($query['id'] ?? '');
    if ($listing && $listing['image'] !== SF_PLACEHOLDER_IMAGE) {
        $fav['image'] = $listing['image'];
    }
}
unset($fav);

$badgeLabels = [
    'products' => ['class' => 'badge-product', 'text' => 'PRODUCT'],
    'rentals'  => ['class' => 'badge-rental',  'text' => 'RENTAL'],
    'services' => ['class' => 'badge-service', 'text' => 'SERVICE'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Favorites — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/favorites.css?v=5" />
  <link rel="stylesheet" href="css/header.css?v=2" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
  <style>
    /* Placeholder for saved items that have no photo (e.g. marketplace mockups) */
    .fav-img-placeholder {
      width: 100%;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
      color: #94a3b8;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.6px;
      text-transform: uppercase;
    }
    .browse-link {
      display: inline-block;
      margin-top: 16px;
      padding: 8px 18px;
      border-radius: 9999px;
      background-color: var(--primary-yellow);
      color: var(--text-dark);
      font-size: 13px;
      font-weight: 700;
      text-decoration: none;
    }
  </style>
</head>
<body>

  <!-- ==================== HEADER / NAVBAR ==================== -->
  <?php sf_render_navbar(sf_cart_count($conn, (int) ($_SESSION['user_id'] ?? 0)), ''); ?>

  <!-- ==================== MAIN FAVORITES CONTENT ==================== -->
  <main class="favorites-wrapper">
    <!-- Header Row -->
    <div class="favorites-header-row">
      <div>
        <h1 class="favorites-title">FAVORITES</h1>
        <p class="favorites-subtitle">Keep track of items you are considering</p>
      </div>

      <!-- Sort Dropdown -->
      <div class="sort-select-wrapper">
        <select class="sort-select" id="sortSelect" onchange="handleSort(this.value)">
          <option value="date-saved" selected>Sort: Date Saved</option>
          <option value="price-asc">Price: Low to High</option>
          <option value="price-desc">Price: High to Low</option>
          <option value="rating-desc">Rating: High to Low</option>
        </select>
        <svg class="sort-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
      </div>
    </div>

    <!-- Category Filter Pills -->
    <div class="filter-pills-row">
      <button type="button" class="filter-pill-btn active" data-type="all" onclick="filterFavorites(this, 'all')">
        All
      </button>
      <button type="button" class="filter-pill-btn" data-type="products" onclick="filterFavorites(this, 'products')">
        Products
      </button>
      <button type="button" class="filter-pill-btn" data-type="rentals" onclick="filterFavorites(this, 'rentals')">
        Rentals
      </button>
      <button type="button" class="filter-pill-btn" data-type="services" onclick="filterFavorites(this, 'services')">
        Services
      </button>
    </div>

    <!-- Favorites Grid -->
    <div class="favorites-grid" id="favoritesGrid">
      <?php foreach ($favorites as $index => $item):
        $badge = $badgeLabels[$item['item_type']] ?? $badgeLabels['products'];
      ?>
      <article
        class="fav-card"
        data-fav-key="<?php echo htmlspecialchars($item['item_key']); ?>"
        data-order="<?php echo $index; ?>"
        data-type="<?php echo htmlspecialchars($item['item_type']); ?>"
        data-price="<?php echo (float) $item['price']; ?>"
        data-rating="<?php echo (float) $item['rating']; ?>"
        onclick="window.location.href='<?php echo htmlspecialchars($item['link'], ENT_QUOTES); ?>'"
      >
        <div class="card-media">
          <?php if (!empty($item['image'])): ?>
            <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="card-img" />
          <?php else: ?>
            <div class="fav-img-placeholder">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                <polyline points="21 15 16 10 5 21"></polyline>
              </svg>
              <span>Mockup Image</span>
            </div>
          <?php endif; ?>
          <button type="button" class="card-fav-btn active" aria-label="Remove from favorites" onclick="event.stopPropagation(); removeFavorite(this)">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="#ef4444" stroke="#ef4444" stroke-width="2">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
            </svg>
          </button>
        </div>

        <div class="card-body">
          <div class="card-badges">
            <span class="<?php echo $badge['class']; ?>"><?php echo $badge['text']; ?></span>
            <?php if (!empty($item['badge_sub'])): ?>
            <span class="badge-subtle"><?php echo htmlspecialchars($item['badge_sub']); ?></span>
            <?php endif; ?>
          </div>

          <h3 class="card-title"><?php echo htmlspecialchars($item['title']); ?></h3>

          <div class="card-footer">
            <span class="card-price"><?php echo htmlspecialchars($item['price_display']); ?></span>
            <?php if ((float) $item['rating'] > 0): ?>
            <div class="card-rating">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
              <span><?php echo number_format((float) $item['rating'], 1); ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>

      <!-- Empty State (no favorites at all, or none in the selected category) -->
      <div class="no-favorites-box" id="noFavoritesBox" style="<?php echo empty($favorites) ? 'display: block;' : 'display: none;'; ?>">
        <svg class="no-favorites-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
        </svg>
        <h3 class="no-favorites-title" id="noFavoritesTitle">No saved items yet</h3>
        <p class="no-favorites-desc">Tap the heart on any listing to save it here.</p>
        <a href="marketplace.php" class="browse-link">Browse the marketplace</a>
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

  <script src="js/cart.js?v=7"></script>
  <script src="js/inbox.js?v=5"></script>
  <script>
    let currentFilter = 'all';
    const typeLabels = { products: 'products', rentals: 'rentals', services: 'services' };

    // Filter by category pills
    function filterFavorites(btn, type) {
      currentFilter = type;
      document.querySelectorAll('.filter-pills-row .filter-pill-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      applyFilter();
    }

    function applyFilter() {
      let visible = 0;
      document.querySelectorAll('.fav-card').forEach(card => {
        const show = (currentFilter === 'all' || card.dataset.type === currentFilter);
        card.style.display = show ? 'flex' : 'none';
        if (show) visible++;
      });
      updateEmptyState(visible);
    }

    // Show the empty box when nothing is visible
    function updateEmptyState(visibleCount) {
      const box = document.getElementById('noFavoritesBox');
      const title = document.getElementById('noFavoritesTitle');
      if (!box) return;

      title.textContent = (currentFilter === 'all')
        ? 'No saved items yet'
        : 'No saved ' + typeLabels[currentFilter] + ' yet';
      box.style.display = (visibleCount === 0) ? 'block' : 'none';
    }

    // Sort listings
    function handleSort(sortVal) {
      const grid = document.getElementById('favoritesGrid');
      const cards = Array.from(grid.querySelectorAll('.fav-card'));
      const emptyBox = document.getElementById('noFavoritesBox');

      cards.sort((a, b) => {
        if (sortVal === 'price-asc') {
          return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
        } else if (sortVal === 'price-desc') {
          return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
        } else if (sortVal === 'rating-desc') {
          return parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating);
        } else {
          return parseInt(a.dataset.order) - parseInt(b.dataset.order);
        }
      });

      cards.forEach(card => grid.insertBefore(card, emptyBox));
    }

    // Remove from favorites (saved in the database)
    function removeFavorite(btn) {
      const card = btn.closest('.fav-card');
      const key = card.dataset.favKey;
      btn.disabled = true;

      // Animate card removal
      card.style.transition = 'all 0.25s ease';
      card.style.transform = 'scale(0.92)';
      card.style.opacity = '0';

      fetch('api/favorite_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove', key: key })
      })
        .then(r => r.json())
        .then(r => {
          if (!r.success) throw new Error();
          setTimeout(() => {
            card.remove();
            applyFilter();
          }, 250);
        })
        .catch(() => {
          // Put the card back if the server could not remove it
          card.style.transform = '';
          card.style.opacity = '';
          btn.disabled = false;
          alert('Could not remove this item. Please try again.');
        });
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

    // Clean up data from the old "removed favorites" approach
    try { localStorage.removeItem('sf_removed_favorites'); } catch (e) {}
    document.cookie = 'sf_removed_favorites=; path=/; max-age=0';
  </script>
  <?php sf_render_tabbar(''); ?>
</body>
</html>