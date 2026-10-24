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

$username = $_SESSION['username'] ?? 'User';
$email    = $_SESSION['email'] ?? '';
$initials = !empty($username) ? strtoupper(substr($username, 0, 2)) : 'MC';

/* ──────────────────────────────────────────────────────────────────
   LISTING DATA
   Every card is a listing posted by a seller (see shop.php).
   'filters' become data-* attributes on each card; the filter modals
   read these to decide which cards to show.
   ────────────────────────────────────────────────────────────────── */

// Times each listing was ordered, for Sort: Most popular
$orderCounts = [];
$res = $conn->query("SELECT item_key, COUNT(*) AS n FROM order_items GROUP BY item_key");
while ($res && $row = $res->fetch_assoc()) $orderCounts[$row['item_key']] = (int) $row['n'];

// Newest first in each tab
$sellerCards = ['products' => [], 'rentals' => [], 'services' => []];
$popular     = [];
// ?loc= from the Home page location filter: only listings in that place
$locOptions = sf_location_options(sf_catalog());
$loc       = sf_location_param($locOptions);
$locLabel  = sf_location_label($locOptions, $loc);
foreach (sf_filter_location(sf_catalog(), $loc) as $id => $listing) {
    $type  = $listing['type'];
    $price = htmlspecialchars(sf_peso($listing['price']), ENT_QUOTES, 'UTF-8')
           . ($listing['unit'] !== '' ? '<span class="price-unit"> / ' . htmlspecialchars(sf_unit_word($listing['unit'])) . '</span>' : '');
    $card  = [
        'id'         => $id,
        'title'      => htmlspecialchars($listing['title'], ENT_QUOTES, 'UTF-8'),
        'price'      => $listing['price'],
        'price_html' => $price,
        'image'      => $listing['image'] !== SF_PLACEHOLDER_IMAGE ? $listing['image'] : '',
    ];
    if ($listing['rating'] > 0) $card['rating'] = $listing['rating'];   // star rating from buyer reviews
    $term = htmlspecialchars(mb_strtoupper($listing['term']), ENT_QUOTES, 'UTF-8');
    if ($type === 'products') {
        [$stockClass, $stockText] = sf_stock_status($listing);
        $card['badges']  = [['badge-product', 'PRODUCT'], ['badge-condition', $term], ['badge-stock-' . $stockClass, mb_strtoupper($stockText)]];
        $card['filters'] = ['category' => $listing['category'], 'condition' => $listing['condition'],
                            'location' => mb_strtolower(trim($listing['location'])), 'seller-rating' => $listing['seller_rating'],
                            'available' => $listing['stock'] > 0 ? 1 : 0, 'pickup' => 1, 'verified' => 1, 'negotiable' => 0];
    } elseif ($type === 'rentals') {
        $card['meta']    = 'Available now · rent by the day';
        $card['badges']  = [['badge-available', 'AVAILABLE NOW'], ['badge-condition', $term]];
        $days = $listing['units'] ?? SF_RENTAL_DAYS;
        $fits = array_filter(['1-3-days' => min($days) <= 3, '1-week' => in_array(7, $days), '2-weeks' => max($days) >= 14, 'monthly' => max($days) >= 30]);
        $card['filters'] = ['start' => date('Y-m-d'), 'end' => date('Y-m-d', strtotime('+60 days')), 'duration' => implode(' ', array_keys($fits)),
                            'available' => 1, 'instant' => 1, 'deposit' => 'none'];
    } else {
        $card['badges']  = [['badge-service-cat', $term], ['badge-seller', htmlspecialchars(mb_strtoupper($listing['seller']), ENT_QUOTES, 'UTF-8')]];
        $card['filters'] = ['category' => $listing['category'], 'schedule' => 'today weekdays evenings weekends',
                            'provider' => 'same-campus peer-mentor', 'verified' => 1, 'repeat' => 1];
    }
    // For the Sort menu: when it was posted and how often it was ordered
    $card['filters'] = ($card['filters'] ?? []) + ['posted' => strtotime($listing['posted']), 'orders' => $orderCounts[$id] ?? 0];
    $sellerCards[$type][] = $card;
    if (count($popular) < 4) $popular[] = $card + ['type' => $type];   // newest few of any type, for the overview
}
$products = $sellerCards['products'];
$rentals  = $sellerCards['rentals'];
$services = $sellerCards['services'];

// Places products can be picked up from (Products filter → Location)
$productLocations = [];
foreach (sf_catalog() as $listing) {
    $place = trim($listing['location']);
    if ($listing['type'] === 'products' && $place !== '') $productLocations[mb_strtolower($place)] = $place;
}
ksort($productLocations);

// Category buttons in the Products and Services filters
$filterCategories = sf_categories(true);

// Overview: real listing counts
$counts = array_map('count', $sellerCards);

function listing_count_label($n) {
    return number_format($n) . ' listing' . ($n === 1 ? '' : 's');
}

/* ──────────────────────────────────────────────────────────────────
   CARD RENDERING
   ────────────────────────────────────────────────────────────────── */
const SVG_PLACEHOLDER = '<div class="mockup-img-placeholder">
                <svg class="mockup-placeholder-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                  <circle cx="8.5" cy="8.5" r="1.5"></circle>
                  <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
                <span class="mockup-placeholder-text">Mockup Image</span>
              </div>';
const SVG_HEART = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';
const SVG_STAR = '<svg class="star-icon" width="16" height="16" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>';
const SVG_CALENDAR = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
const SVG_CLOCK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';

function sf_attr($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function render_card(array $item, string $type) {
    $title      = $item['title'];                                   // may contain entities, e.g. &amp;
    // Every card opens its own listing, e.g. rental_detail.php?id=mini-projector
    $id         = $item['id'];
    $link       = $id !== null ? sf_item_link($id, $type) : '';
    $plainTitle = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $priceHtml  = $item['price_html'] ?? '';
    $favPrice   = $item['fav_price']
        ?? ($type === 'rentals' && $priceHtml === ''
            ? '₱' . number_format($item['price']) . '/day'
            : trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($priceHtml), ENT_QUOTES, 'UTF-8'))));
    // Sub-label shown on the Favorites page: skip the generic PRODUCT/RENTAL/SERVICE badge
    $typeBadges = ['badge-product', 'badge-rental', 'badge-service'];
    $badgeSub   = in_array($item['badges'][0][0], $typeBadges, true) ? ($item['badges'][1][1] ?? '') : $item['badges'][0][1];
    $badgeSub   = html_entity_decode($badgeSub, ENT_QUOTES, 'UTF-8');

    $attrs = [
        'type'          => $type,
        'title'         => $plainTitle,
        'price'         => $item['price'],
        'price-display' => $favPrice,
        'rating'        => $item['rating'] ?? 0,
        'badge-sub'     => $badgeSub,
        'link'          => $link,
    ];
    foreach (($item['filters'] ?? []) as $k => $v) {
        $attrs[$k] = $v;
    }

    $classes = 'listing-card' . ($type === 'services' && isset($item['filters']) ? ' service-card' : '');
    $html = '<article class="' . $classes . '"';
    foreach ($attrs as $k => $v) {
        $html .= ' data-' . $k . '="' . sf_attr($v) . '"';
    }
    if ($link !== '') {
        $html .= ' onclick="window.location.href=\'' . sf_attr($link) . '\'" style="cursor: pointer;"';
    }
    $html .= ">\n";

    // Media
    $media = !empty($item['image'])
        ? '<img src="' . sf_attr($item['image']) . '" alt="' . sf_attr($plainTitle) . '" class="card-img" loading="lazy" />'
        : SVG_PLACEHOLDER;
    $html .= '            <div class="card-media">' . "\n              " . $media . "\n";
    if ($type === 'services' && isset($item['filters'])) {
        $html .= '              <div class="card-overlay-label">' . $title . "</div>\n";
    }
    $html .= '              <button type="button" class="card-fav-btn" aria-label="Save item" onclick="event.stopPropagation(); toggleFavorite(this)">' . SVG_HEART . "</button>\n";
    $html .= "            </div>\n";

    // Body
    $html .= '            <div class="card-body">' . "\n";
    $html .= '              <div class="card-badges">';
    foreach ($item['badges'] as [$cls, $text]) {
        $html .= '<span class="badge ' . $cls . '">' . $text . '</span>';
    }
    $html .= "</div>\n";
    $html .= '              <h3 class="card-title">' . $title . "</h3>\n";

    if (!empty($item['meta'])) {
        $icon = $type === 'rentals' ? SVG_CALENDAR : SVG_CLOCK;
        $html .= '              <div class="card-meta">' . $icon . '<span>' . $item['meta'] . "</span></div>\n";
    }
    if ($priceHtml !== '') {
        $html .= '              <div class="card-footer">' . "\n";
        $html .= '                <span class="card-price">' . $priceHtml . "</span>\n";
        if (isset($item['rating'])) {
            $html .= '                <div class="card-rating">' . SVG_STAR . '<span class="rating-val">' . number_format($item['rating'], 1) . "</span></div>\n";
        }
        $html .= "              </div>\n";
    }
    if (!empty($item['detail'])) {
        $html .= '              <div class="card-detail-line">' . $item['detail'] . "</div>\n";
    }
    $html .= "            </div>\n          </article>\n";
    return $html;
}

function render_tab_bar(string $type) {
    return '
        <div class="tab-bar">
          <div class="tab-pills">
            <button class="tab-pill" onclick="switchTab(\'products\')">Products</button>
            <button class="tab-pill" onclick="switchTab(\'rentals\')">Rentals</button>
            <button class="tab-pill" onclick="switchTab(\'services\')">Services</button>
          </div>
          <div class="tab-controls">
            <label class="sort-dropdown">
              <span>Sort:</span>
              <select class="sort-select" data-sort-for="' . $type . '" aria-label="Sort ' . $type . '" onchange="sortGrid(\'' . $type . '\', this.value)">
                <option value="newest">Newest</option>
                <option value="popular">Most popular</option>
                <option value="price-asc">Price: low to high</option>
                <option value="price-desc">Price: high to low</option>
                <option value="rating">Top rated</option>
              </select>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </label>
            <button type="button" class="filter-btn" id="filterBtn-' . $type . '" aria-haspopup="dialog" aria-expanded="false" aria-controls="modal-filter-' . $type . '" onclick="openFilterModal(\'' . $type . '\')">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
              <span class="filter-btn-label">Filters</span>
            </button>
          </div>
        </div>';
}

// Shown when nobody has listed anything of this type yet
function render_empty_tab(string $type) {
    $what = ['all' => 'listings', 'products' => 'products', 'rentals' => 'rentals', 'services' => 'services'][$type];
    return '
          <div class="no-results-msg" style="grid-column: 1 / -1; text-align: center; padding: 48px 20px; background: #ffffff; border-radius: 16px; border: 1px solid #ece7dc; color: #6b7280; font-size: 14px;">
            <p style="font-weight: 700; color: #111827; font-size: 16px; margin-bottom: 4px;">No ' . $what . ' yet</p>
            <p>Have something to sell, rent out or offer? Be the first to list it.</p>
            <a href="listing_form.php" class="filter-btn" style="margin: 14px auto 0; display: inline-flex; text-decoration: none;">Create a listing</a>
          </div>';
}

function render_no_results(string $type) {
    return '
          <div class="no-results-msg" id="noResults-' . $type . '" style="display: none; grid-column: 1 / -1; text-align: center; padding: 48px 20px; background: #ffffff; border-radius: 16px; border: 1px solid #ece7dc; color: #6b7280; font-size: 14px;">
            <p style="font-weight: 700; color: #111827; font-size: 16px; margin-bottom: 4px;">No listings match your filters</p>
            <p>Try removing a filter or two.</p>
            <button type="button" class="filter-btn" style="margin: 14px auto 0;" onclick="clearFilters(\'' . $type . '\')">Clear filters</button>
          </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Station Finds — Marketplace</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/marketplace.css?v=11" />
  <link rel="stylesheet" href="css/header.css?v=1" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
</head>
<body>

  <!-- ==================== HEADER / NAVBAR ==================== -->
  <?php sf_render_navbar(sf_cart_count($conn, (int) ($_SESSION['user_id'] ?? 0)), 'market'); ?>

  <!-- ==================== MAIN CONTENT ==================== -->
  <main class="main-wrapper">
    <div class="main-container">

      <!-- Page Title Row -->
      <div class="marketplace-header">
        <div>
          <h1 class="marketplace-title">Marketplace</h1>
          <p class="marketplace-subtitle" id="marketplaceSubtitle"></p>
          <?php if ($loc !== ''): ?>
          <p class="location-chip">Showing listings <?php echo $loc === SF_LOCATION_NONE ? 'without a location' : 'in <strong>' . htmlspecialchars($locLabel, ENT_QUOTES, 'UTF-8') . '</strong>'; ?> &middot; <a href="marketplace.php">Show all locations</a></p>
          <?php endif; ?>
        </div>
        <span class="results-count" id="resultsCount"></span>
      </div>

      <!-- ========== OVERVIEW SECTION (default landing) ========== -->
      <div id="view-overview" class="mp-view active">

        <!-- Category Summary Cards -->
        <div class="overview-categories">
          <!-- Products -->
          <div class="overview-cat-card" onclick="switchTab('products')">
            <div class="overview-cat-top">
              <div class="overview-cat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                  <line x1="3" y1="6" x2="21" y2="6"></line>
                  <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
              </div>
              <div>
                <h3 class="overview-cat-name">Products</h3>
                <span class="overview-cat-count"><?php echo listing_count_label($counts['products']); ?></span>
              </div>
            </div>
            <p class="overview-cat-desc">Find student essentials: cheap books, study lamps, second-hand calculators, and dorm gear.</p>
            <button class="overview-cat-btn" onclick="event.stopPropagation(); switchTab('products')">Browse all products</button>
          </div>

          <!-- Rentals -->
          <div class="overview-cat-card" onclick="switchTab('rentals')">
            <div class="overview-cat-top">
              <div class="overview-cat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="7.5" cy="15.5" r="5.5"></circle>
                  <path d="m21 2-9.6 9.6"></path>
                  <path d="m15.5 7.5 3 3L22 7l-3-3"></path>
                </svg>
              </div>
              <div>
                <h3 class="overview-cat-name">Rentals</h3>
                <span class="overview-cat-count"><?php echo listing_count_label($counts['rentals']); ?></span>
              </div>
            </div>
            <p class="overview-cat-desc">Borrow tools, appliances, gear, and transit options. Keep campus costs down.</p>
            <button class="overview-cat-btn" onclick="event.stopPropagation(); switchTab('rentals')">Browse all rentals</button>
          </div>

          <!-- Services -->
          <div class="overview-cat-card" onclick="switchTab('services')">
            <div class="overview-cat-top">
              <div class="overview-cat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                  <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                </svg>
              </div>
              <div>
                <h3 class="overview-cat-name">Services</h3>
                <span class="overview-cat-count"><?php echo listing_count_label($counts['services']); ?></span>
              </div>
            </div>
            <p class="overview-cat-desc">On-demand student labor: moving support, thesis proofreading, custom code debugging, peer tutorials.</p>
            <button class="overview-cat-btn" onclick="event.stopPropagation(); switchTab('services')">Browse all services</button>
          </div>
        </div>

        <!-- Popular Listings -->
        <h2 class="section-title">Popular Listings</h2>
        <div class="listing-grid">
          <?php foreach ($popular as $item) echo render_card($item, $item['type']); ?>
          <?php if (!$popular) echo render_empty_tab('all'); ?>
        </div>
      </div>

      <!-- ========== PRODUCTS TAB ========== -->
      <div id="view-products" class="mp-view">
        <?php echo render_tab_bar('products'); ?>

        <div class="listing-grid listing-grid-full">
          <?php foreach ($products as $item) echo render_card($item, 'products'); ?>
          <?php if (!$products) echo render_empty_tab('products'); ?>
          <?php echo render_no_results('products'); ?>
        </div>
      </div>

      <!-- ========== RENTALS TAB ========== -->
      <div id="view-rentals" class="mp-view">
        <?php echo render_tab_bar('rentals'); ?>

        <div class="listing-grid listing-grid-full">
          <?php foreach ($rentals as $item) echo render_card($item, 'rentals'); ?>
          <?php if (!$rentals) echo render_empty_tab('rentals'); ?>
          <?php echo render_no_results('rentals'); ?>
        </div>
      </div>

      <!-- ========== SERVICES TAB ========== -->
      <div id="view-services" class="mp-view">
        <?php echo render_tab_bar('services'); ?>

        <div class="listing-grid listing-grid-full">
          <?php foreach ($services as $item) echo render_card($item, 'services'); ?>
          <?php if (!$services) echo render_empty_tab('services'); ?>
          <?php echo render_no_results('services'); ?>
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

  <!-- ==================== FILTER MODAL: RENTALS ==================== -->
  <div id="modal-filter-rentals" class="filter-modal-backdrop" onclick="closeFilterModalOnBackdrop(event, 'rentals')">
    <div class="filter-modal-dialog rental-filter" role="dialog" aria-modal="true" aria-labelledby="rentalModalTitle">
      <div class="filter-modal-header">
        <h3 id="rentalModalTitle" class="filter-modal-title">Filter rentals</h3>
        <p class="filter-modal-subtitle">Find an item that fits your dates and budget.</p>
        <button type="button" class="filter-modal-close" onclick="closeFilterModal('rentals')" aria-label="Close modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
        </button>
      </div>

      <div class="filter-modal-body">
        <!-- Availability -->
        <div class="filter-section">
          <span class="filter-section-title">Availability</span>
          <div class="filter-checkbox-row">
            <label class="filter-checkbox-label">
              <input type="checkbox" class="filter-checkbox" data-filter="available" checked />
              <span>Available now</span>
            </label>
            <label class="filter-checkbox-label">
              <input type="checkbox" class="filter-checkbox" data-filter="instant" />
              <span>Instant booking</span>
            </label>
          </div>
        </div>

        <!-- Rental dates -->
        <div class="filter-section">
          <span class="filter-section-title">Rental dates</span>
          <div class="filter-grid-2">
            <div class="filter-select-wrapper">
              <select class="filter-select" data-filter="from" aria-label="Start date">
                <?php for ($d = 0; $d <= 14; $d++): $day = strtotime("+$d days"); ?>
                <option value="<?php echo date('Y-m-d', $day); ?>"<?php echo $d === 0 ? ' selected' : ''; ?>><?php echo date('M j, Y', $day); ?></option>
                <?php endfor; ?>
              </select>
              <svg class="filter-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
            <div class="filter-select-wrapper">
              <select class="filter-select" data-filter="to" aria-label="End date">
                <?php foreach ([1, 2, 3, 5, 7, 10, 14, 21, 30, 45, 60] as $d): $day = strtotime("+$d days"); ?>
                <option value="<?php echo date('Y-m-d', $day); ?>"<?php echo $d === 7 ? ' selected' : ''; ?>><?php echo date('M j, Y', $day); ?></option>
                <?php endforeach; ?>
              </select>
              <svg class="filter-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
          </div>
        </div>

        <!-- Duration -->
        <div class="filter-section">
          <span class="filter-section-title">Duration</span>
          <div class="filter-pills-row" data-filter="duration">
            <button type="button" class="filter-pill-btn" data-value="1-3-days" onclick="toggleFilterPill(this)">1-3 days</button>
            <button type="button" class="filter-pill-btn" data-value="1-week" onclick="toggleFilterPill(this)">1 week</button>
            <button type="button" class="filter-pill-btn" data-value="2-weeks" onclick="toggleFilterPill(this)">2+ weeks</button>
            <button type="button" class="filter-pill-btn" data-value="monthly" onclick="toggleFilterPill(this)">Monthly</button>
          </div>
        </div>

        <!-- Daily rate -->
        <div class="filter-section">
          <span class="filter-section-title">Daily rate</span>
          <div class="filter-grid-2">
            <div class="filter-select-wrapper">
              <select class="filter-select" data-filter="minPrice" aria-label="Minimum daily rate">
                <option value="0" selected>₱0 minimum</option>
                <option value="100">₱100 minimum</option>
                <option value="250">₱250 minimum</option>
                <option value="500">₱500 minimum</option>
              </select>
              <svg class="filter-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
            <div class="filter-select-wrapper">
              <select class="filter-select" data-filter="maxPrice" aria-label="Maximum daily rate">
                <option value="500" selected>₱500 maximum</option>
                <option value="1000">₱1,000 maximum</option>
                <option value="2000">₱2,000 maximum</option>
                <option value="any">Any rate</option>
              </select>
              <svg class="filter-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
          </div>
        </div>

        <!-- Deposit -->
        <div class="filter-section">
          <span class="filter-section-title">Deposit</span>
          <div class="filter-checkbox-row">
            <label class="filter-checkbox-label">
              <input type="checkbox" class="filter-checkbox" data-filter="noDeposit" />
              <span>No deposit</span>
            </label>
            <label class="filter-checkbox-label">
              <input type="checkbox" class="filter-checkbox" data-filter="refundableOnly" checked />
              <span>Refundable deposit only</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="filter-modal-footer">
        <button type="button" class="filter-btn-reset" onclick="resetFilters('rentals')">Reset all</button>
        <button type="button" class="filter-btn-submit" onclick="applyFilters('rentals')">Show results</button>
      </div>
    </div>
  </div>

  <!-- ==================== FILTER MODAL: SERVICES ==================== -->
  <div id="modal-filter-services" class="filter-modal-backdrop" onclick="closeFilterModalOnBackdrop(event, 'services')">
    <div class="filter-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="serviceModalTitle">
      <div class="filter-modal-header">
        <h3 id="serviceModalTitle" class="filter-modal-title">Filter services</h3>
        <p class="filter-modal-subtitle">Match with a trusted student provider.</p>
        <button type="button" class="filter-modal-close" onclick="closeFilterModal('services')" aria-label="Close modal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
        </button>
      </div>

      <div class="filter-modal-body">
        <!-- Category -->
        <div class="filter-section">
          <span class="filter-section-title">Category</span>
          <div class="filter-pills-row" data-filter="category">
            <button type="button" class="filter-pill-btn active" data-value="all" onclick="toggleFilterPill(this)">All</button>
            <?php foreach ($filterCategories['services'] as $slug => $name): ?>
            <button type="button" class="filter-pill-btn" data-value="<?php echo sf_attr($slug); ?>" onclick="toggleFilterPill(this)"><?php echo sf_attr($name); ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Provider -->
        <div class="filter-section">
          <span class="filter-section-title">Provider</span>
          <div class="filter-select-wrapper">
            <select class="filter-select" data-filter="provider" aria-label="Provider">
              <option value="any" selected>Any verified student provider</option>
              <option value="top-rated">Top rated student providers only</option>
              <option value="same-campus">Same campus providers</option>
              <option value="peer-mentor">Peer mentors &amp; tutors</option>
            </select>
            <svg class="filter-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </div>
        </div>

        <!-- Schedule -->
        <div class="filter-section">
          <span class="filter-section-title">Schedule</span>
          <div class="filter-pills-row" data-filter="schedule">
            <button type="button" class="filter-pill-btn" data-value="today" onclick="toggleFilterPill(this)">Today</button>
            <button type="button" class="filter-pill-btn active" data-value="weekdays" onclick="toggleFilterPill(this)">Weekdays</button>
            <button type="button" class="filter-pill-btn" data-value="evenings" onclick="toggleFilterPill(this)">Evenings</button>
            <button type="button" class="filter-pill-btn" data-value="weekends" onclick="toggleFilterPill(this)">Weekends</button>
          </div>
        </div>

        <!-- Pricing -->
        <div class="filter-section">
          <span class="filter-section-title">Pricing</span>
          <div class="filter-grid-2">
            <div class="filter-select-wrapper">
              <select class="filter-select" data-filter="minPrice" aria-label="Minimum price">
                <option value="0" selected>₱0 minimum</option>
                <option value="150">₱150 minimum</option>
                <option value="300">₱300 minimum</option>
                <option value="500">₱500 minimum</option>
              </select>
              <svg class="filter-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
            <div class="filter-select-wrapper">
              <select class="filter-select" data-filter="maxPrice" aria-label="Maximum price">
                <option value="1000" selected>₱1,000 maximum</option>
                <option value="1500">₱1,500 maximum</option>
                <option value="2500">₱2,500 maximum</option>
                <option value="any">Any rate</option>
              </select>
              <svg class="filter-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
          </div>
        </div>

        <!-- Rating (the service's own reviews) -->
        <div class="filter-section">
          <span class="filter-section-title star-filter-title" id="serviceStarsLabel">Rating</span>
          <div class="star-filter" data-filter="minStars" role="radiogroup" aria-labelledby="serviceStarsLabel">
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="1" aria-label="1 star and up" title="Services rated 1+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="2" aria-label="2 stars and up" title="Services rated 2+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="3" aria-label="3 stars and up" title="Services rated 3+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="4" aria-label="4 stars and up" title="Services rated 4+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="5" aria-label="5 stars and up" title="Services rated 5+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
          </div>
        </div>

        <!-- Checkboxes -->
        <div class="filter-checkbox-row">
          <label class="filter-checkbox-label">
            <input type="checkbox" class="filter-checkbox" data-filter="verified" checked />
            <span>Verified providers</span>
          </label>
          <label class="filter-checkbox-label">
            <input type="checkbox" class="filter-checkbox" data-filter="repeat" />
            <span>Repeat bookings</span>
          </label>
        </div>
      </div>

      <!-- Footer -->
      <div class="filter-modal-footer">
        <button type="button" class="filter-btn-reset" onclick="resetFilters('services')">Reset all</button>
        <button type="button" class="filter-btn-submit" onclick="applyFilters('services')">Show results</button>
      </div>
    </div>
  </div>

  <!-- ==================== FILTER MODAL: PRODUCTS ==================== -->
  <div id="modal-filter-products" class="filter-modal-backdrop" onclick="closeFilterModalOnBackdrop(event, 'products')">
    <div class="filter-modal-dialog filter-panel" role="dialog" aria-modal="true" aria-labelledby="productModalTitle">
      <div class="filter-panel-head">
        <h3 id="productModalTitle">Filters</h3>
        <button type="button" class="filter-clear-link" onclick="clearFilters('products')">Clear all</button>
        <button type="button" class="filter-modal-close" onclick="closeFilterModal('products')" aria-label="Close filters">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <div class="filter-modal-body filter-panel-body">
        <!-- Category: any of the ticked ones -->
        <fieldset class="filter-panel-section filter-check-group" data-filter="category">
          <legend>Category</legend>
          <?php foreach ($filterCategories['products'] as $slug => $name): ?>
          <label class="filter-panel-check"><input type="checkbox" value="<?php echo sf_attr($slug); ?>" /> <?php echo sf_attr($name); ?></label>
          <?php endforeach; ?>
        </fieldset>

        <!-- Price range -->
        <fieldset class="filter-panel-section">
          <legend>Price range</legend>
          <div class="filter-price-row">
            <label class="filter-price-box"><span>Min ₱</span><input type="number" min="0" step="1" inputmode="numeric" data-filter="minPrice" placeholder="0" aria-label="Minimum price" /></label>
            <label class="filter-price-box"><span>Max ₱</span><input type="number" min="0" step="1" inputmode="numeric" data-filter="maxPrice" placeholder="Any" aria-label="Maximum price" /></label>
          </div>
        </fieldset>

        <!-- Condition -->
        <fieldset class="filter-panel-section filter-check-group" data-filter="condition">
          <legend>Condition</legend>
          <?php foreach (SF_CONDITIONS as $key => $label): ?>
          <label class="filter-panel-check"><input type="checkbox" value="<?php echo sf_attr($key); ?>" /> <?php echo sf_attr($label); ?></label>
          <?php endforeach; ?>
        </fieldset>

        <!-- Location -->
        <div class="filter-panel-section">
          <label class="filter-panel-label" for="productLocation">Location</label>
          <div class="filter-select-wrapper">
            <select class="filter-select" id="productLocation" data-filter="location">
              <option value="any">All locations</option>
              <?php foreach ($productLocations as $value => $label): ?>
              <option value="<?php echo sf_attr($value); ?>"><?php echo sf_attr($label); ?></option>
              <?php endforeach; ?>
            </select>
            <svg class="filter-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </div>
        </div>

        <!-- Rating (the seller's reviews) -->
        <div class="filter-panel-section">
          <span class="filter-section-title star-filter-title" id="productStarsLabel">Rating</span>
          <div class="star-filter" data-filter="minStars" role="radiogroup" aria-labelledby="productStarsLabel">
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="1" aria-label="1 star and up" title="Sellers rated 1+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="2" aria-label="2 stars and up" title="Sellers rated 2+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="3" aria-label="3 stars and up" title="Sellers rated 3+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="4" aria-label="4 stars and up" title="Sellers rated 4+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
            <button type="button" class="star-filter-btn" role="radio" aria-checked="false" data-value="5" aria-label="5 stars and up" title="Sellers rated 5+ stars"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></button>
          </div>
        </div>
      </div>

      <div class="filter-modal-footer filter-panel-footer">
        <button type="button" class="filter-btn-submit" onclick="applyFilters('products')">Apply Filters</button>
      </div>
    </div>
  </div>

  <script src="js/favorites.js?v=4"></script>
  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <script>
    /* ── Tab switching logic ──────────────────────────────────── */
    const tabConfig = {
      overview: { subtitle: '' },
      products: { subtitle: '' },
      rentals:  { subtitle: 'Borrow what you need, only for as long as you need it.' },
      services: { subtitle: 'Book student talent for study, creative work, and everyday help.' }
    };
    let currentTab = 'overview';

    function switchTab(tab) {
      if (!tabConfig[tab]) tab = 'overview';
      currentTab = tab;

      // Hide all views
      document.querySelectorAll('.mp-view').forEach(v => v.classList.remove('active'));
      // Show target
      const target = document.getElementById('view-' + tab);
      if (target) target.classList.add('active');

      // Update header info
      document.getElementById('marketplaceSubtitle').textContent = tabConfig[tab].subtitle;
      updateResultsCount();

      // Highlight active pills inside the view
      if (target) {
        target.querySelectorAll('.tab-pill').forEach(pill => {
          pill.classList.toggle('active', pill.textContent.trim().toLowerCase() === tab);
        });
      }
    }

    /* ── User dropdown toggle ─────────────────────────────────── */
    function toggleUserDropdown() {
      document.getElementById('userDropdown').classList.toggle('show');
    }
    document.addEventListener('click', function(e) {
      const wrapper = document.querySelector('.user-menu-wrapper');
      if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('userDropdown').classList.remove('show');
      }
    });

    /* ── Filters ──────────────────────────────────────────────────
       Each control in a modal has data-filter="<name>". The rules below
       decide whether a card (its data-* attributes) passes that control.
       Filters only take effect after "Show results" is clicked.        */
    const num  = v => parseFloat(v) || 0;
    const tags = (card, attr) => (card.dataset[attr] || '').split(/\s+/);
    const flag = attr => card => card.dataset[attr] === '1';
    const minPrice = (c, v) => num(c.dataset.price) >= num(v);
    const maxPrice = (c, v) => v === 'any' || num(c.dataset.price) <= num(v);
    const minRating = (c, v) => v === 'any' || num(c.dataset.rating) >= num(v);

    const FILTER_RULES = {
      products: {
        category:   (c, v) => v.includes(c.dataset.category),      // ticked categories (any of them)
        condition:  (c, v) => v.includes(c.dataset.condition),
        minPrice, maxPrice,
        location:   (c, v) => v === 'any' || c.dataset.location === v,
        minStars:   (c, v) => num(c.dataset.sellerRating) >= num(v)   // seller's rating
      },
      rentals: {
        available:      flag('available'),
        instant:        flag('instant'),
        from:           (c, v) => c.dataset.end >= v,     // listing still available on/after start date
        to:             (c, v) => c.dataset.start <= v,   // listing starts on/before end date
        duration:       (c, v) => tags(c, 'duration').includes(v),
        minPrice, maxPrice,
        noDeposit:      c => c.dataset.deposit === 'none',
        refundableOnly: c => c.dataset.deposit !== 'non-refundable'
      },
      services: {
        category: (c, v) => v === 'all' || c.dataset.category === v,
        provider: (c, v) => v === 'any' || (v === 'top-rated' ? num(c.dataset.rating) >= 4.8 : tags(c, 'provider').includes(v)),
        schedule: (c, v) => tags(c, 'schedule').includes(v),
        minPrice, maxPrice,
        minStars: (c, v) => num(c.dataset.rating) >= num(v),   // the service's rating
        verified: flag('verified'),
        repeat:   flag('repeat')
      }
    };

    const appliedFilters = { products: null, rentals: null, services: null };
    const modalSnapshot  = {};

    const getModal = type => document.getElementById('modal-filter-' + type);
    const getCards = type => Array.from(document.querySelectorAll('#view-' + type + ' .listing-card'));

    // Read the current controls of a modal into { filterName: value }
    function readState(type) {
      const modal = getModal(type);
      const state = {};
      modal.querySelectorAll('.filter-pills-row[data-filter]').forEach(row => {
        const active = row.querySelector('.filter-pill-btn.active');
        if (active) state[row.dataset.filter] = active.dataset.value;
      });
      modal.querySelectorAll('select[data-filter]').forEach(sel => {
        state[sel.dataset.filter] = sel.value;
      });
      modal.querySelectorAll('input[type="checkbox"][data-filter]').forEach(cb => {
        if (cb.checked) state[cb.dataset.filter] = true;
      });
      modal.querySelectorAll('.star-filter[data-filter]').forEach(row => {
        if (row.dataset.value) state[row.dataset.filter] = row.dataset.value;
      });
      // Tick lists (any of the ticked values) and typed amounts (left empty = no limit)
      modal.querySelectorAll('.filter-check-group[data-filter]').forEach(group => {
        const values = [...group.querySelectorAll('input:checked')].map(cb => cb.value);
        if (values.length) state[group.dataset.filter] = values;
      });
      modal.querySelectorAll('input[type="number"][data-filter]').forEach(input => {
        if (input.value.trim() !== '') state[input.dataset.filter] = input.value.trim();
      });
      return state;
    }

    // Put a saved state back into the modal controls
    function writeState(type, state) {
      const modal = getModal(type);
      modal.querySelectorAll('.filter-pills-row[data-filter]').forEach(row => {
        row.querySelectorAll('.filter-pill-btn').forEach(p => {
          p.classList.toggle('active', state[row.dataset.filter] === p.dataset.value);
        });
      });
      modal.querySelectorAll('select[data-filter]').forEach(sel => {
        if (state[sel.dataset.filter] !== undefined) sel.value = state[sel.dataset.filter];
      });
      modal.querySelectorAll('input[type="checkbox"][data-filter]').forEach(cb => {
        cb.checked = !!state[cb.dataset.filter];
      });
      modal.querySelectorAll('.filter-check-group[data-filter]').forEach(group => {
        group.querySelectorAll('input').forEach(cb => { cb.checked = (state[group.dataset.filter] || []).includes(cb.value); });
      });
      modal.querySelectorAll('input[type="number"][data-filter]').forEach(input => {
        input.value = state[input.dataset.filter] ?? '';
      });
      modal.querySelectorAll('.star-filter[data-filter]').forEach(row => setStars(row, state[row.dataset.filter] || ''));
    }

    function matches(type, card, state) {
      const rules = FILTER_RULES[type];
      return Object.keys(state).every(name => !rules[name] || rules[name](card, state[name]));
    }

    function countMatches(type, state) {
      return getCards(type).filter(card => matches(type, card, state)).length;
    }

    // Number of controls that actually narrow results (for the "Filters (n)" label)
    function countActive(state) {
      if (!state) return 0;
      return Object.entries(state).filter(([name, v]) =>
        !(v === 'all' || v === 'any' || (name === 'minPrice' && num(v) === 0))
      ).length;
    }

    function renderGrid(type) {
      const state = appliedFilters[type];
      let visible = 0;
      getCards(type).forEach(card => {
        const show = !state || matches(type, card, state);
        card.style.display = show ? '' : 'none';
        if (show) visible++;
      });

      const empty = document.getElementById('noResults-' + type);
      if (empty) empty.style.display = visible === 0 ? 'block' : 'none';

      const n = countActive(state);
      const label = document.querySelector('#filterBtn-' + type + ' .filter-btn-label');
      if (label) label.textContent = n > 0 ? 'Filters (' + n + ')' : 'Filters';

      updateResultsCount();
    }

    function updateResultsCount() {
      const el = document.getElementById('resultsCount');
      if (!FILTER_RULES[currentTab]) { el.textContent = ''; return; }
      const visible = getCards(currentTab).filter(c => c.style.display !== 'none').length;
      el.textContent = visible + ' ' + currentTab + ' found' + (appliedFilters[currentTab] ? ' (filtered)' : '');
    }

    // Live "Show N results" preview while the user changes controls
    function updatePreview(type) {
      const n = countMatches(type, readState(type));
      const btn = getModal(type).querySelector('.filter-btn-submit');
      btn.textContent = (type === 'products' ? 'Apply Filters · ' : 'Show ') + n + ' result' + (n === 1 ? '' : 's');
    }

    // Filters open as a dropdown under their button (the page stays scrollable)
    let openFilterType = null;
    function placeFilterDropdown(type) {
      const btn = document.getElementById('filterBtn-' + type);
      const panel = getModal(type).querySelector('.filter-modal-dialog');
      if (!btn || !panel) return;
      const r = btn.getBoundingClientRect();
      const top = Math.max(8, r.bottom + 8);
      panel.style.top = top + 'px';
      panel.style.right = Math.max(12, window.innerWidth - r.right) + 'px';
      panel.style.maxHeight = Math.max(240, window.innerHeight - top - 16) + 'px';
    }
    function openFilterModal(type) {
      const modal = getModal(type);
      if (!modal) return;
      if (openFilterType && openFilterType !== type) closeFilterModal(openFilterType);
      if (modal.classList.contains('show')) { closeFilterModal(type); return; }   // the button toggles it
      modalSnapshot[type] = readState(type);
      updatePreview(type);
      placeFilterDropdown(type);
      modal.classList.add('show');
      openFilterType = type;
      const btn = document.getElementById('filterBtn-' + type);
      if (btn) btn.setAttribute('aria-expanded', 'true');
      const first = modal.querySelector('.filter-modal-dialog input, .filter-modal-dialog select, .filter-modal-dialog button:not(.filter-modal-close)');
      if (first) first.focus({ preventScroll: true });
    }

    // Closing without applying discards unapplied changes
    function closeFilterModal(type) {
      const modal = getModal(type);
      if (!modal || !modal.classList.contains('show')) return;
      if (modalSnapshot[type]) writeState(type, modalSnapshot[type]);
      modal.classList.remove('show');
      if (openFilterType === type) openFilterType = null;
      const btn = document.getElementById('filterBtn-' + type);
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }
    // Keep it under the button while scrolling or resizing
    window.addEventListener('scroll', () => { if (openFilterType) placeFilterDropdown(openFilterType); }, { passive: true });
    window.addEventListener('resize', () => { if (openFilterType) placeFilterDropdown(openFilterType); });

    function closeFilterModalOnBackdrop(e, type) {
      if (e.target.classList.contains('filter-modal-backdrop')) {
        closeFilterModal(type);
      }
    }

    // Single-select pills; clicking the active pill again clears that filter
    function toggleFilterPill(btn) {
      const row = btn.closest('.filter-pills-row');
      const wasActive = btn.classList.contains('active');
      if (row) row.querySelectorAll('.filter-pill-btn').forEach(b => b.classList.remove('active'));
      if (!wasActive) btn.classList.add('active');
      const modal = btn.closest('.filter-modal-backdrop');
      if (modal) updatePreview(modal.id.replace('modal-filter-', ''));
    }

    // Reset the modal to "show everything"
    function resetFilters(type) {
      const modal = getModal(type);
      if (!modal) return;

      modal.querySelectorAll('.filter-checkbox, .filter-check-group input').forEach(cb => { cb.checked = false; });
      modal.querySelectorAll('input[type="number"][data-filter]').forEach(input => { input.value = ''; });
      modal.querySelectorAll('.star-filter').forEach(row => setStars(row, ''));

      modal.querySelectorAll('select[data-filter]').forEach(sel => {
        const opts = Array.from(sel.options);
        const anyOpt = opts.find(o => o.value === 'any');
        if (anyOpt) sel.value = 'any';
        else if (sel.dataset.filter === 'to') sel.value = opts[opts.length - 1].value;   // widest date range
        else sel.value = opts[0].value;
      });

      modal.querySelectorAll('.filter-pills-row[data-filter]').forEach(row => {
        row.querySelectorAll('.filter-pill-btn').forEach(p => {
          p.classList.toggle('active', p.dataset.value === 'all' || p.dataset.value === 'any');
        });
      });

      updatePreview(type);
    }

    function applyFilters(type) {
      const state = readState(type);
      appliedFilters[type] = countActive(state) > 0 ? state : null;
      modalSnapshot[type] = state;          // keep these settings when the modal closes
      closeFilterModal(type);
      renderGrid(type);
    }

    // "Clear filters" button shown when nothing matches
    function clearFilters(type) {
      resetFilters(type);
      appliedFilters[type] = null;
      modalSnapshot[type] = readState(type);
      renderGrid(type);
    }

    // Star rating filter: the chosen star and those before it are filled
    function setStars(row, value) {
      row.dataset.value = value;
      row.querySelectorAll('.star-filter-btn').forEach(btn => {
        const n = Number(btn.dataset.value);
        btn.classList.toggle('on', value !== '' && n <= Number(value));
        btn.setAttribute('aria-checked', String(String(n) === String(value)));
        btn.tabIndex = (value === '' ? n === 1 : String(n) === String(value)) ? 0 : -1;
      });
      const title = row.previousElementSibling;
      if (title) title.textContent = value ? 'Rating · ' + value + '+ stars' : 'Rating';
    }
    document.querySelectorAll('.star-filter').forEach(row => {
      setStars(row, '');
      row.addEventListener('click', e => {
        const btn = e.target.closest('.star-filter-btn');
        if (!btn) return;
        setStars(row, row.dataset.value === btn.dataset.value ? '' : btn.dataset.value);
        const modal = row.closest('.filter-modal-backdrop');
        if (modal) updatePreview(modal.id.replace('modal-filter-', ''));
      });
      row.addEventListener('keydown', e => {
        const step = { ArrowRight: 1, ArrowUp: 1, ArrowLeft: -1, ArrowDown: -1 }[e.key];
        if (!step) return;
        e.preventDefault();
        const next = Math.min(5, Math.max(1, Number(row.dataset.value || 0) + step));
        setStars(row, String(next));
        row.querySelector('[data-value="' + next + '"]').focus();
        const modal = row.closest('.filter-modal-backdrop');
        if (modal) updatePreview(modal.id.replace('modal-filter-', ''));
      });
      // Hovering previews the stars
      row.querySelectorAll('.star-filter-btn').forEach(btn => {
        btn.addEventListener('mouseenter', () => row.querySelectorAll('.star-filter-btn').forEach(b => b.classList.toggle('hover', Number(b.dataset.value) <= Number(btn.dataset.value))));
      });
      row.addEventListener('mouseleave', () => row.querySelectorAll('.star-filter-btn').forEach(b => b.classList.remove('hover')));
    });

    // Sort menu: reorders the cards in the tab's grid. Ties keep newest first.
    const SORTS = {
      'newest':     (a, b) => num(b.dataset.posted) - num(a.dataset.posted),
      'popular':    (a, b) => num(b.dataset.orders) - num(a.dataset.orders) || num(b.dataset.rating) - num(a.dataset.rating),
      'price-asc':  (a, b) => num(a.dataset.price) - num(b.dataset.price),
      'price-desc': (a, b) => num(b.dataset.price) - num(a.dataset.price),
      'rating':     (a, b) => num(b.dataset.rating) - num(a.dataset.rating) || num(b.dataset.orders) - num(a.dataset.orders),
    };
    function sortGrid(type, how) {
      const cards = getCards(type);
      if (!cards.length || !SORTS[how]) return;
      const grid = cards[0].parentNode;
      const anchor = document.getElementById('noResults-' + type);
      cards.sort((a, b) => SORTS[how](a, b) || SORTS.newest(a, b))
           .forEach(card => grid.insertBefore(card, anchor && anchor.parentNode === grid ? anchor : null));
    }

    // Live preview when selects / checkboxes change
    ['products', 'rentals', 'services'].forEach(type => {
      getModal(type).addEventListener('change', () => updatePreview(type));
      getModal(type).addEventListener('input', () => updatePreview(type));   // typed prices
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        ['products', 'rentals', 'services'].forEach(closeFilterModal);
      }
    });

    // Initialize on page load (support ?tab= query param)
    const urlParams = new URLSearchParams(window.location.search);
    switchTab(urlParams.get('tab') || 'overview');
  </script>
  <?php sf_render_tabbar('market'); ?>
</body>
</html>