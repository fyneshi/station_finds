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
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/layout.php';

$userId = (int) $_SESSION['user_id'];
$items  = sf_cart_items($conn, $userId);
$totals = sf_cart_totals($items, $_SESSION['promo_code'] ?? '');
if ($totals['count'] === 0) unset($_SESSION['promo_code']);

$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Your cart — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=5" />
  <link rel="stylesheet" href="css/header.css?v=1" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
</head>
<body>

  <?php sf_render_navbar($totals['count'], 'cart'); ?>

  <main class="shop-wrapper">
    <h1 class="shop-title">Your cart &ndash; <span id="cartCountTitle"><?php echo $totals['count']; ?></span> item<span id="cartCountPlural"><?php echo $totals['count'] == 1 ? '' : 's'; ?></span></h1>

    <!-- Empty state (also shown after removing the last item) -->
    <div class="card empty-cart" id="emptyCart" style="margin-top: 24px;<?php echo $items ? ' display: none;' : ''; ?>">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
      <h2>Your cart is empty</h2>
      <p>Find something you like in the marketplace and add it here.</p>
      <a href="marketplace.php" class="btn-primary inline">Browse the marketplace</a>
    </div>

    <?php if ($items): ?>
    <div class="shop-grid" id="cartGrid">
      <!-- Cart lines -->
      <div class="cart-list">
        <?php foreach ($items as $item): ?>
        <article class="card cart-line" data-key="<?php echo $e($item['key']); ?>">
          <a href="<?php echo $e($item['link']); ?>"><img src="<?php echo $e($item['image']); ?>" alt="<?php echo $e($item['title']); ?>" class="cart-thumb" /></a>
          <div class="cart-info">
            <a href="<?php echo $e($item['link']); ?>" class="cart-item-title"><?php echo $e($item['title']); ?></a>
            <div class="cart-item-meta">Seller: <?php echo $e($item['seller']); ?></div>
            <div class="cart-item-meta">Condition / Term: <?php echo $e($item['term_label']); ?></div>
            <div class="cart-stock-warn" data-stock-warn<?php echo $item['stock_problem'] === '' ? ' hidden' : ''; ?>><?php echo $e($item['stock_problem']); ?></div>
          </div>
          <div class="cart-line-total" data-line-total><?php echo sf_peso($item['line_total']); ?></div>
          <div class="qty-stepper" aria-label="Quantity">
            <button type="button" class="qty-btn" aria-label="Decrease quantity" onclick="changeQty(this, -1)"<?php echo $item['quantity'] <= 1 ? ' disabled' : ''; ?>>&minus;</button>
            <span class="qty-num" data-qty><?php echo $item['quantity']; ?></span>
            <button type="button" class="qty-btn" aria-label="Increase quantity" onclick="changeQty(this, 1)" data-max="<?php echo $item['max_qty']; ?>"<?php echo $item['quantity'] >= $item['max_qty'] ? ' disabled' : ''; ?>>+</button>
          </div>
          <button type="button" class="remove-btn" aria-label="Remove <?php echo $e($item['title']); ?>" onclick="removeLine(this)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>
          </button>
        </article>
        <?php endforeach; ?>
      </div>

      <!-- Order summary -->
      <aside class="card card-pad summary-card">
        <?php sf_render_summary($totals, 'cart'); ?>
        <?php $stockProblem = sf_stock_problems($items); ?>
        <p class="cart-stock-warn" id="checkoutBlocked"<?php echo $stockProblem === '' ? ' hidden' : ''; ?>>Some items don't have enough stock. Fix them to check out.</p>
        <a href="checkout.php?from=cart" class="btn-primary<?php echo $stockProblem !== '' ? ' is-disabled' : ''; ?>" id="checkoutLink"<?php echo $stockProblem !== '' ? ' aria-disabled="true"' : ''; ?>>Proceed to checkout</a>
        <a href="marketplace.php" class="btn-ghost">&larr; Continue shopping</a>
      </aside>
    </div>
    <?php endif; ?>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <script>

    // Apply the server's response to the page
    function render(r) {
      SFCart.setBadge(r.count);
      document.getElementById('cartCountTitle').textContent = r.count;
      document.getElementById('cartCountPlural').textContent = r.count === 1 ? '' : 's';

      Object.entries(r.totals).forEach(([k, v]) => {
        const el = document.querySelector('[data-total="' + k + '"]');
        if (el) el.textContent = v;
      });
      document.querySelector('[data-row="discount"]').style.display = r.totals.discount ? '' : 'none';
      document.querySelector('[data-row="shipping"]').style.display = r.totals.ships ? '' : 'none';   // only products ship

      const applied = !!r.totals.promo_code;
      document.getElementById('promoApplied').style.display = applied ? '' : 'none';
      document.getElementById('promoForm').style.display = applied ? 'none' : '';
      if (applied) document.getElementById('promoAppliedText').textContent = r.totals.promo_code + ' · ' + r.totals.promo_label;

      document.querySelectorAll('.cart-line').forEach(line => {
        const data = r.lines[line.dataset.key];
        if (!data) { line.remove(); return; }
        line.querySelector('[data-qty]').textContent = data.quantity;
        line.querySelector('[data-line-total]').textContent = data.line_total;
        const [minus, plus] = line.querySelectorAll('.qty-btn');
        plus.dataset.max = data.max_qty;
        minus.disabled = data.quantity <= 1;
        plus.disabled = data.quantity >= data.max_qty;
        const warn = line.querySelector('[data-stock-warn]');
        warn.textContent = data.stock_problem;
        warn.hidden = !data.stock_problem;
      });
      const blocked = !!r.stock_problem;
      const link = document.getElementById('checkoutLink');
      if (link) {
        link.classList.toggle('is-disabled', blocked);
        if (blocked) link.setAttribute('aria-disabled', 'true'); else link.removeAttribute('aria-disabled');
        document.getElementById('checkoutBlocked').hidden = !blocked;
      }

      if (r.count === 0) {
        document.getElementById('cartGrid').remove();
        document.getElementById('emptyCart').style.display = '';
      }
    }

    async function send(payload, line) {
      if (line) line.classList.add('is-busy');
      try {
        const r = await SFCart.post(payload);
        if (!r.success) throw new Error(r.message);
        render(r);
        return r;
      } catch (e) {
        SFCart.toast(e.message || 'Something went wrong. Please try again.', true);
        throw e;
      } finally {
        if (line && line.isConnected) line.classList.remove('is-busy');
      }
    }

    function changeQty(btn, delta) {
      const line = btn.closest('.cart-line');
      const qty = parseInt(line.querySelector('[data-qty]').textContent, 10) + delta;
      const max = parseInt(line.querySelectorAll('.qty-btn')[1].dataset.max, 10);
      if (qty < 1 || (delta > 0 && qty > max)) return;   // lowering always works, even if it's still above the stock
      send({ action: 'update', key: line.dataset.key, qty: qty }, line).catch(() => {});
    }

    function removeLine(btn) {
      const line = btn.closest('.cart-line');
      send({ action: 'remove', key: line.dataset.key }, line).catch(() => {});
    }

    function showPromoMsg(text, ok) {
      const msg = document.getElementById('promoMsg');
      msg.textContent = text;
      msg.className = 'promo-msg ' + (ok ? 'ok' : 'err');
    }

    async function applyPromo(e) {
      e.preventDefault();
      const input = document.getElementById('promoInput');
      try {
        const r = await SFCart.post({ action: 'apply_promo', code: input.value });
        if (!r.success) { showPromoMsg(r.message, false); return; }
        render(r);
        input.value = '';
        showPromoMsg(r.message, true);
      } catch (err) {
        showPromoMsg('Could not apply the code. Please try again.', false);
      }
    }

    function removePromo() {
      send({ action: 'remove_promo' }).then(() => showPromoMsg('', true)).catch(() => {});
    }
  </script>
  <?php sf_render_tabbar(''); ?>
</body>
</html>