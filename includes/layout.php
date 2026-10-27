<?php
/**
 * Site header, phone tab bar and footer, shared by every buyer/seller page.
 * Header styles: css/header.css (the same size and look on every page).
 */

// Where to come back to after logging in: this page, e.g. "product_detail.php?id=listing-12"
function sf_current_page() {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return basename(parse_url($uri, PHP_URL_PATH) ?: 'home.php') . (($q = parse_url($uri, PHP_URL_QUERY)) ? '?' . $q : '');
}

// The site header. $active highlights: home | market | messages | notifications | cart.
// Guests (not logged in) see Log in / Sign up instead of the account menu, and js/guest.js
// asks them to log in when they open notifications, messages, the cart and other account features.
function sf_render_navbar($cartCount = 0, $active = '') {
    $guest    = empty($_SESSION['user_id']);
    $username = $_SESSION['username'] ?? 'User';
    $email    = $_SESSION['email'] ?? '';
    $isAdmin  = ($_SESSION['role'] ?? '') === 'admin';
    $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $on  = fn($key) => $active === $key ? ' active' : '';
    $loginLink = 'index.php?next=' . rawurlencode(sf_current_page());
    $search = $e(trim((string) ($_GET['q'] ?? '')));
    ?>
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
          <a href="home.php" class="nav-link<?php echo $on('home'); ?>">Home</a>
          <a href="marketplace.php" class="nav-link<?php echo $on('market'); ?>">Marketplace</a>
          <a href="messages.php" class="nav-link<?php echo $on('messages'); ?>">Messages</a>
          <?php if ($isAdmin): ?>
          <a href="admin_dashboard.php" class="nav-link nav-admin">Admin Dashboard</a>
          <?php endif; ?>
        </nav>
      </div>

      <div class="nav-center">
        <form action="search.php" method="GET" class="search-bar" role="search">
          <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" name="q" class="search-input" value="<?php echo basename($_SERVER['SCRIPT_NAME'] ?? '') === 'search.php' ? $search : ''; ?>" placeholder="Search books, gadgets, dorm find, bike rentals, tutors..." aria-label="Search listings" />
        </form>
      </div>

      <div class="nav-right">
        <a href="notifications.php" class="icon-btn<?php echo $on('notifications'); ?>" title="Notifications" aria-label="Notifications">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
        </a>
        <a href="favorites.php" class="icon-btn" title="Saved Items" aria-label="Saved Items">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
        </a>
        <a href="cart.php" class="icon-btn cart-btn<?php echo $on('cart'); ?>" title="Shopping Cart" aria-label="Shopping Cart">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
          <span class="cart-badge"<?php echo $cartCount > 0 ? '' : ' style="display: none;"'; ?>><?php echo $cartCount > 9 ? '9+' : (int) $cartCount; ?></span>
        </a>

        <?php if ($guest): ?>
        <a href="<?php echo $e($loginLink); ?>" class="nav-auth-btn"><span class="nav-auth-long">Log in / Sign up</span><span class="nav-auth-short">Log in</span></a>
        <?php else: ?>
        <div class="user-menu-wrapper">
          <button type="button" class="user-avatar-btn" id="userMenuBtn" onclick="document.getElementById('userDropdown').classList.toggle('show')" aria-label="User profile"><?php echo sf_nav_avatar_html(); ?></button>
          <div class="user-dropdown" id="userDropdown">
            <div class="user-dropdown-header">
              <div class="dropdown-avatar"><?php echo sf_nav_avatar_html(); ?></div>
              <div>
                <span class="dropdown-username"><?php echo $e($username); ?></span>
                <span class="dropdown-email"><?php echo $e($email); ?></span>
              </div>
            </div>
            <div class="dropdown-divider"></div>
            <?php if ($isAdmin): ?>
            <a href="admin_dashboard.php" class="dropdown-item nav-admin">Admin Dashboard</a>
            <?php endif; ?>
            <a href="account.php?tab=profile" class="dropdown-item">My Profile</a>
            <div class="dropdown-divider"></div>
            <a href="logout.php" class="dropdown-item logout-link">Log out</a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </header>
  <?php if ($guest): ?>
  <script>window.SF_GUEST = true; window.SF_LOGIN = <?php echo json_encode($loginLink); ?>;</script>
  <script src="js/guest.js?v=3" defer></script>
  <?php else: ?>
  <script>
    // Close the account menu when clicking anywhere else
    document.addEventListener('click', e => {
      const menu = document.querySelector('.navbar .user-menu-wrapper');
      if (menu && !menu.contains(e.target)) document.getElementById('userDropdown').classList.remove('show');
    });
  </script>
  <?php endif; ?>
    <?php
}

// Bottom tab bar for phones (css/responsive.css shows it at 768px and below).
// $active: home | market | messages | account. The Messages tab is a .nav-link to messages.php,
// so js/inbox.js adds the unread count to it like it does in the top bar.
function sf_render_tabbar($active = '') {
    $tabs = [
        'home'     => ['home.php', 'Home', '<path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>'],
        'market'   => ['marketplace.php', 'Marketplace', '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 13.5V21h14v-7.5"/>'],
        'messages' => ['messages.php', 'Messages', '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
        'account'  => ['account.php', 'Account', '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'],
    ];
    echo '<nav class="sf-tabbar" aria-label="Main">';
    foreach ($tabs as $key => [$href, $label, $icon]) {
        $current = $key === $active;
        echo '<a href="' . $href . '" class="sf-tab' . ($key === 'messages' ? ' nav-link' : '') . ($current ? ' active' : '') . '"'
           . ($current ? ' aria-current="page"' : '') . '>'
           . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg>'
           . '<span>' . $label . '</span></a>';
    }
    echo '</nav>';
}

function sf_render_footer() {
    ?>
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
  <script>
    // Close the user dropdown when clicking outside it
    document.addEventListener('click', function (e) {
      const wrapper = document.querySelector('.user-menu-wrapper');
      if (wrapper && !wrapper.contains(e.target)) document.getElementById('userDropdown').classList.remove('show');
    });
  </script>
    <?php
}

// Order summary card used on both cart.php and checkout.php
function sf_render_summary(array $t, $mode = 'cart') {
    ?>
        <h2 class="summary-title">Order summary</h2>
        <?php if ($mode === 'cart'): ?>
        <div id="promoBox">
          <div class="promo-applied" id="promoApplied"<?php echo $t['promo_code'] ? '' : ' style="display: none;"'; ?>>
            <span id="promoAppliedText"><?php echo htmlspecialchars($t['promo_code'] . ' · ' . $t['promo_label']); ?></span>
            <button type="button" class="link-btn" onclick="removePromo()">Remove</button>
          </div>
          <form id="promoForm" onsubmit="applyPromo(event)"<?php echo $t['promo_code'] ? ' style="display: none;"' : ''; ?>>
            <label class="promo-label" for="promoInput">Promo code</label>
            <div class="promo-row">
              <input type="text" id="promoInput" class="text-input" placeholder="Enter student code" maxlength="30" autocomplete="off" />
              <button type="submit" class="btn-small">Apply</button>
            </div>
          </form>
          <p class="promo-msg" id="promoMsg" role="status"></p>
        </div>
        <?php endif; ?>
        <div class="summary-rows">
          <div class="summary-row"><span><?php echo $mode === 'cart' ? 'Subtotal' : 'Subtotal (<span data-total="count">' . $t['count'] . '</span> item' . ($t['count'] == 1 ? '' : 's') . ')'; ?></span><strong data-total="subtotal"><?php echo sf_peso($t['subtotal']); ?></strong></div>
          <div class="summary-row discount" data-row="discount"<?php echo $t['discount'] > 0 ? '' : ' style="display: none;"'; ?>><span>Promo discount</span><strong data-total="discount">−<?php echo sf_peso($t['discount']); ?></strong></div>
          <div class="summary-row" data-row="shipping"<?php echo $t['ships'] ? '' : ' style="display: none;"'; ?>><span><?php echo $mode === 'cart' ? 'Shipping / Delivery' : 'Shipping fee'; ?></span><strong data-total="shipping"><?php echo $t['shipping'] > 0 ? sf_peso($t['shipping']) : 'Free'; ?></strong></div>
          <div class="summary-row"><span><?php echo $mode === 'cart' ? 'Platform Tax (6%)' : 'Estimated Tax'; ?></span><strong data-total="tax"><?php echo sf_peso($t['tax']); ?></strong></div>
        </div>
        <div class="summary-total"><span>Total</span><strong data-total="total"><?php echo sf_peso($t['total']); ?></strong></div>
    <?php
}