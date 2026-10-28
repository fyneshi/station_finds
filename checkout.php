<?php
/**
 * Checkout — 3 steps + confirmation
 *   ?step=1  Delivery address
 *   ?step=2  Payment method
 *   ?step=3  Review & place order
 *   ?step=done&order=SF-XXXX   Confirmation
 *
 * Form data is kept in $_SESSION['checkout'] until the order is placed.
 * Totals are always recalculated on the server from the cart, or, after "Buy now" on a
 * listing, from just that one item ($_SESSION['buy_now']); the cart is then left alone.
 */
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
require_once __DIR__ . '/includes/inbox.php';

$userId   = (int) $_SESSION['user_id'];
$checkout = $_SESSION['checkout'] ?? ['delivery' => null, 'payment' => null];
$methods  = sf_payment_methods();
$errors   = [];
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

function go($step, $extra = '') {
    header('Location: checkout.php?step=' . $step . $extra);
    exit;
}

// ── Confirmation page ───────────────────────────────────────────
$step = $_GET['step'] ?? '1';
if ($step === 'done') {
    $orderNo = (string) ($_GET['order'] ?? '');
    $stmt = $conn->prepare("SELECT * FROM orders WHERE order_no = ? AND user_id = ?");
    $stmt->bind_param("si", $orderNo, $userId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        header('Location: cart.php');
        exit;
    }
}

// ── Everything else needs something to buy: the cart, or the "Buy now" item ──
if (($_GET['from'] ?? '') === 'cart') unset($_SESSION['buy_now']);   // "Proceed to checkout" in the cart
$buyNow = !empty($_SESSION['buy_now']);
$items  = $buyNow ? sf_buy_now_items($userId) : sf_cart_items($conn, $userId);
if ($buyNow && !$items && $step !== 'done') {
    unset($_SESSION['buy_now']);   // that listing isn't for sale any more
    header('Location: cart.php');
    exit;
}
$totals = sf_cart_totals($items, $_SESSION['promo_code'] ?? '');
if ($step !== 'done' && !$items) {
    header('Location: cart.php');
    exit;
}
// Something sold out or the quantity is above what's left: the order can't be placed until it's fixed
$stockProblem = $step === 'done' ? '' : sf_stock_problems($items);
// Renting needs a verified ID (rentals may have been added before the check existed)
if ($stockProblem === '' && $step !== 'done' && in_array('rentals', array_column($items, 'type'), true) && !sf_id_verified($conn, $userId)) {
    $stockProblem = SF_VERIFY_MESSAGE;
}

// ── Form submissions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sf_check_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'delivery') {
            $d = [];
            foreach (['full_name', 'phone', 'address_line1', 'address_line2', 'city', 'province', 'zip_code'] as $f) {
                $d[$f] = trim(preg_replace('/\s+/', ' ', (string) ($_POST[$f] ?? '')));
            }
            if ($d['full_name'] === '' || mb_strlen($d['full_name']) > 100) $errors['full_name'] = 'Please enter your full name.';
            $digits = preg_replace('/[\s\-()]/', '', $d['phone']);
            if (!preg_match('/^(\+63|0)9\d{9}$/', $digits)) $errors['phone'] = 'Enter a PH mobile number, e.g. 0917 123 4567.';
            if ($d['address_line1'] === '' || mb_strlen($d['address_line1']) > 255) $errors['address_line1'] = 'Please enter your address.';
            if (mb_strlen($d['address_line2']) > 255) $errors['address_line2'] = 'Address line 2 is too long.';
            if ($d['city'] === '' || mb_strlen($d['city']) > 100) $errors['city'] = 'Please enter your city.';
            if ($d['province'] === '' || mb_strlen($d['province']) > 100) $errors['province'] = 'Please enter your province.';
            if (!preg_match('/^\d{4}$/', $d['zip_code'])) $errors['zip_code'] = 'Zip code must be 4 digits.';

            if (!$errors) {
                $checkout['delivery'] = $d;          // only valid details are saved
                $_SESSION['checkout'] = $checkout;
                go(2);
            }
            $postedDelivery = $d;                    // re-show what they typed
            $step = '1';
        }

        if ($action === 'payment') {
            $method = $_POST['payment_method'] ?? '';
            if (!isset($methods[$method])) {
                $errors['payment_method'] = 'Please choose a payment method.';
                $step = '2';
            } else {
                $checkout['payment'] = $method;
                $_SESSION['checkout'] = $checkout;
                go(3);
            }
        }

        if ($action === 'place_order') {
            if (empty($checkout['delivery']) || empty($checkout['payment'])) go(1);
            $d = $checkout['delivery'];

            $conn->begin_transaction();
            try {
                if ($stockProblem !== '') throw new SfStockException($stockProblem);
                // First take the items out of stock: throws if someone else got the last ones,
                // and the rollback below then undoes everything (including the stock already taken)
                sf_reserve_stock($conn, $items);

                $orderNo = 'SF-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $promo   = $totals['promo_code'];
                $status  = $checkout['payment'] === 'cod' ? 'pending' : 'awaiting_payment';
                $stmt = $conn->prepare(
                    "INSERT INTO orders (order_no, user_id, full_name, phone, address_line1, address_line2, city, province, zip_code,
                                         payment_method, promo_code, subtotal, discount, shipping, tax, total, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sisssssssssddddds",
                    $orderNo, $userId, $d['full_name'], $d['phone'], $d['address_line1'], $d['address_line2'],
                    $d['city'], $d['province'], $d['zip_code'], $checkout['payment'], $promo,
                    $totals['subtotal'], $totals['discount'], $totals['shipping'], $totals['tax'], $totals['total'], $status);
                $stmt->execute();
                $orderId = $stmt->insert_id;
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO order_items (order_id, item_key, item_type, title, seller, unit_price, units, quantity, line_total, stock_deducted)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($items as $it) {
                    $deducted = $it['type'] === 'products' ? 1 : 0;   // taken from stock above, given back if it's cancelled
                    $stmt->bind_param("issssdiidi", $orderId, $it['key'], $it['type'], $it['title'], $it['seller'],
                        $it['price'], $it['units'], $it['quantity'], $it['line_total'], $deducted);
                    $stmt->execute();
                }
                $stmt->close();

                // Checking out the cart empties it; "Buy now" leaves the cart alone
                if (!$buyNow) {
                    $stmt = $conn->prepare("DELETE FROM cart_items WHERE user_id = ?");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();
            } catch (SfStockException $ex) {
                $conn->rollback();
                $errors['form'] = $ex->getMessage();
                $step = '3';
            } catch (Throwable $ex) {
                $conn->rollback();
                $errors['form'] = 'We could not place your order. Please try again.';
                $step = '3';
            }

            if (!$errors) {
                $titles = array_column($items, 'title');
                $what   = $titles[0] . (count($titles) > 1 ? ' + ' . (count($titles) - 1) . ' more' : '');
                sf_notify($conn, $userId, 'order', 'Order placed',
                    'Your order #' . $orderNo . ' (' . $what . ') for ' . sf_peso($totals['total']) . ' was sent to the seller.',
                    'checkout.php?step=done&order=' . urlencode($orderNo));
                // Tell each seller what was bought from their listings
                foreach ($items as $it) {
                    if ($it['seller_id'] > 0) {
                        sf_notify($conn, $it['seller_id'], 'order', 'New order received',
                            $d['full_name'] . ' ordered ' . $it['title'] . ' (' . sf_term_label($it) . ', ' . sf_peso($it['line_total'])
                            . ') in order #' . $orderNo . '. Update it from your Seller Dashboard.', 'account.php?tab=seller');
                        if ($it['type'] === 'products') {
                            $left = sf_listing_stock($conn, (int) substr($it['key'], 8));
                            if ($left === 0) {
                                sf_notify($conn, $it['seller_id'], 'order', 'Sold out',
                                    $it['title'] . ' is out of stock, so buyers can\'t order it. Add more stock from your Seller Dashboard.', 'account.php?tab=seller');
                            } elseif ($left <= SF_LOW_STOCK && $it['stock'] > SF_LOW_STOCK) {
                                sf_notify($conn, $it['seller_id'], 'order', 'Low stock',
                                    'Only ' . $left . ' of ' . $it['title'] . ' ' . ($left === 1 ? 'is' : 'are') . ' left. Restock it from your Seller Dashboard.', 'account.php?tab=seller');
                            }
                        }
                    }
                }
                unset($_SESSION['checkout'], $_SESSION['buy_now']);
                if (!$buyNow) unset($_SESSION['promo_code']);   // a code applied in the cart stays for the cart
                go('done', '&order=' . urlencode($orderNo));
            }
        }
    }
}

// ── Step gating: can't skip ahead ───────────────────────────────
if ($step !== 'done') {
    $step = in_array($step, ['1', '2', '3'], true) ? (int) $step : 1;
    if ($step >= 2 && empty($checkout['delivery'])) $step = 1;
    if ($step === 3 && empty($checkout['payment']))  $step = 2;
}

// Prefill delivery: session → last order → account name
$delivery = $postedDelivery ?? $checkout['delivery'] ?? null;
if (!$delivery && $step === 1) {
    $stmt = $conn->prepare("SELECT full_name, phone, address_line1, address_line2, city, province, zip_code FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $delivery = $stmt->get_result()->fetch_assoc() ?: ['full_name' => $_SESSION['username'] ?? ''];
    $stmt->close();
}

$stepTitles = [1 => 'Delivery details', 2 => 'Payment method', 3 => 'Review and submit'];
$csrf = sf_csrf_token();

function field($name, $label, $delivery, $errors, $opts = []) {
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $value = $delivery[$name] ?? '';
    $err = $errors[$name] ?? '';
    $id = 'f_' . $name;
    ?>
          <div class="form-field<?php echo !empty($opts['full']) ? ' full' : ''; ?>">
            <label class="form-label" for="<?php echo $id; ?>"><?php echo $label; ?><?php if (!empty($opts['optional'])): ?> <span class="optional">(Optional)</span><?php endif; ?></label>
            <input class="text-input<?php echo $err ? ' has-error' : ''; ?>" id="<?php echo $id; ?>" name="<?php echo $name; ?>" value="<?php echo $e($value); ?>"
              <?php echo empty($opts['optional']) ? 'required' : ''; ?> <?php echo $opts['attrs'] ?? ''; ?><?php echo $err ? ' aria-invalid="true" aria-describedby="' . $id . '_err"' : ''; ?> />
            <?php if ($err): ?><span class="field-error" id="<?php echo $id; ?>_err"><?php echo $e($err); ?></span><?php endif; ?>
          </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $step === 'done' ? 'Order placed' : 'Checkout'; ?> — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=6" />
  <link rel="stylesheet" href="css/header.css?v=3" />
</head>
<body>

  <?php sf_render_navbar($totals['count'], 'cart'); ?>

  <main class="shop-wrapper">
  <?php if ($step === 'done'): ?>

    <!-- ==================== CONFIRMATION ==================== -->
    <div class="card confirm-card">
      <div class="confirm-icon">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
      </div>
      <h1>Order placed!</h1>
      <p>Thanks, <?php echo $e($order['full_name']); ?>. The sellers have been notified and will confirm your order soon.</p>
      <div class="confirm-details">
        <div class="summary-row"><span>Order number</span><strong><?php echo $e($order['order_no']); ?></strong></div>
        <div class="summary-row"><span>Payment</span><strong><?php echo $e(sf_payment_summary($order['payment_method'], $order['phone'])); ?></strong></div>
        <div class="summary-row"><span>Deliver to</span><strong><?php echo $e($order['address_line1'] . ', ' . $order['city']); ?></strong></div>
        <div class="summary-row"><span>Total</span><strong><?php echo sf_peso($order['total']); ?></strong></div>
      </div>
      <div class="confirm-actions">
        <a href="marketplace.php" class="btn-primary inline">Continue shopping</a>
      </div>
    </div>

  <?php else: ?>

    <h1 class="shop-title">Checkout</h1>
    <p class="shop-subtitle">Step <?php echo $step; ?> of 3 &ndash; <?php echo $stepTitles[$step]; ?></p>

    <!-- Stepper -->
    <nav class="stepper" aria-label="Checkout progress">
      <?php
        $labels = [1 => 'Delivery Address', 2 => 'Payment Method', 3 => 'Review &amp; Place Order'];
        foreach ($labels as $n => $label):
          $state = $n < $step ? 'done' : ($n === $step ? 'current' : '');
          $tag = $state === 'done' ? 'a href="checkout.php?step=' . $n . '"' : 'span';
      ?>
        <?php if ($n > 1): ?><span class="step-line<?php echo $n <= $step ? ' done' : ''; ?>"></span><?php endif; ?>
        <<?php echo $tag; ?> class="step <?php echo $state; ?>"<?php echo $state === 'current' ? ' aria-current="step"' : ''; ?>>
          <span class="step-dot"><?php echo $state === 'done' ? '&#10003;' : $n; ?></span>
          <span class="step-label"><?php echo $label; ?></span>
        </<?php echo $state === 'done' ? 'a' : 'span'; ?>>
      <?php endforeach; ?>
    </nav>

    <div class="shop-grid">
      <div>
        <?php if (!empty($errors['form'])): ?><div class="alert-error"><?php echo $e($errors['form']); ?></div>
        <?php elseif ($stockProblem === SF_VERIFY_MESSAGE): ?><div class="alert-error"><?php echo $e($stockProblem); ?> <a href="<?php echo SF_VERIFY_LINK; ?>">Verify your ID</a></div>
        <?php elseif ($stockProblem !== ''): ?><div class="alert-error"><?php echo $e($stockProblem); ?> <a href="<?php echo $buyNow ? $e($items[0]['link']) : 'cart.php'; ?>"><?php echo $buyNow ? 'Back to the listing' : 'Go to your cart'; ?></a></div><?php endif; ?>

        <?php if ($step === 1): ?>
        <!-- ==================== STEP 1: DELIVERY ==================== -->
        <form method="POST" action="checkout.php?step=1" class="card card-pad" id="checkoutForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>" />
          <input type="hidden" name="action" value="delivery" />
          <h2 class="card-heading">Delivery Address</h2>
          <div class="form-stack">
            <div class="form-grid">
              <?php field('full_name', 'Full Name', $delivery, $errors, ['attrs' => 'autocomplete="name" maxlength="100"']); ?>
              <?php field('phone', 'Phone Number', $delivery, $errors, ['attrs' => 'type="tel" autocomplete="tel" placeholder="+63 917 123 4567" maxlength="20"']); ?>
            </div>
            <?php field('address_line1', 'Address Line 1', $delivery, $errors, ['full' => true, 'attrs' => 'autocomplete="address-line1" maxlength="255"']); ?>
            <?php field('address_line2', 'Address Line 2', $delivery, $errors, ['full' => true, 'optional' => true, 'attrs' => 'autocomplete="address-line2" maxlength="255"']); ?>
            <div class="form-grid-3">
              <?php field('city', 'City', $delivery, $errors, ['attrs' => 'autocomplete="address-level2" maxlength="100"']); ?>
              <?php field('province', 'Province', $delivery, $errors, ['attrs' => 'autocomplete="address-level1" maxlength="100"']); ?>
              <?php field('zip_code', 'Zip Code', $delivery, $errors, ['attrs' => 'inputmode="numeric" autocomplete="postal-code" maxlength="4" pattern="\d{4}"']); ?>
            </div>
          </div>
        </form>

        <?php elseif ($step === 2): $d = $checkout['delivery']; ?>
        <!-- ==================== STEP 2: PAYMENT ==================== -->
        <div class="card deliver-to">
          <div>
            <h4>Deliver to <?php echo $e($d['full_name']); ?></h4>
            <p><?php echo $e(implode(', ', array_filter([$d['address_line1'], $d['address_line2'], $d['city'], $d['province'], $d['zip_code']]))); ?></p>
          </div>
          <a href="checkout.php?step=1">Change</a>
        </div>

        <form method="POST" action="checkout.php?step=2" class="card card-pad" id="checkoutForm">
          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>" />
          <input type="hidden" name="action" value="payment" />
          <h2 class="card-heading">Select Payment Method</h2>
          <?php if (!empty($errors['payment_method'])): ?><div class="alert-error"><?php echo $e($errors['payment_method']); ?></div><?php endif; ?>
          <div class="pay-options" role="radiogroup" aria-label="Payment method">
            <?php
              $icons = [
                'gcash' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>',
                'card'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>',
                'cod'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>',
              ];
              foreach ($methods as $key => $m):
            ?>
            <label class="pay-option">
              <input type="radio" name="payment_method" value="<?php echo $key; ?>" required <?php echo ($checkout['payment'] ?? '') === $key ? 'checked' : ''; ?> />
              <span class="pay-icon"><?php echo $icons[$key]; ?></span>
              <span>
                <span class="pay-name"><?php echo $e($m['label']); ?></span><br />
                <span class="pay-desc"><?php echo $e($m['desc']); ?></span>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
        </form>

        <?php else: $d = $checkout['delivery']; ?>
        <!-- ==================== STEP 3: REVIEW ==================== -->
        <div class="card card-pad">
          <h2 class="card-heading">Review Your Order</h2>

          <div class="review-block">
            <div class="review-label">Delivery address <a href="checkout.php?step=1">Edit</a></div>
            <p class="review-text"><?php echo $e($d['full_name'] . ' • ' . $d['phone'] . ' • ' . implode(', ', array_filter([$d['address_line1'], $d['address_line2'], $d['city'], $d['province'], $d['zip_code']]))); ?></p>
          </div>

          <div class="review-block">
            <div class="review-label">Payment method <a href="checkout.php?step=2">Edit</a></div>
            <p class="review-text"><?php echo $e(sf_payment_summary($checkout['payment'], $d['phone'])); ?></p>
          </div>

          <div class="review-block">
            <div class="review-label"><?php if ($buyNow): ?>Buying just this item <a href="cart.php">Go to cart instead</a><?php else: ?>Items to purchase <a href="cart.php">Edit cart</a><?php endif; ?></div>
            <div class="review-items">
              <?php foreach ($items as $it): ?>
              <div class="review-item">
                <img src="<?php echo $e($it['image']); ?>" alt="" />
                <div>
                  <div class="review-item-title"><?php echo $e($it['title']); ?></div>
                  <div class="review-item-note"><?php echo $e(sf_price_note($it)); ?></div>
                  <?php if ($it['stock_problem'] !== ''): ?><div class="review-item-note stock-warn"><?php echo $e($it['stock_problem']); ?></div><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <form method="POST" action="checkout.php?step=3" id="checkoutForm">
          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>" />
          <input type="hidden" name="action" value="place_order" />
        </form>
        <?php endif; ?>
      </div>

      <!-- Order summary -->
      <aside>
        <div class="card card-pad summary-card">
          <?php sf_render_summary($totals, 'checkout'); ?>
          <?php
            $btnText = [1 => 'Continue to Payment', 2 => 'Continue to Review', 3 => 'Place Order (' . sf_peso($totals['total']) . ')'];
          ?>
          <button type="submit" form="checkoutForm" class="btn-primary" id="checkoutSubmit"<?php echo $step === 3 && $stockProblem !== '' ? ' disabled' : ''; ?>><?php echo $btnText[$step]; ?></button>
        </div>
        <?php if ($step === 3): ?>
        <div class="secure-note">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
          Secure checkout processed via STATIONFINDS Escrow
        </div>
        <?php endif; ?>
      </aside>
    </div>

  <?php endif; ?>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=8"></script>
  <script src="js/inbox.js?v=5"></script>
  <script>
    // Prevent double-submitting (e.g. placing the same order twice)
    const form = document.getElementById('checkoutForm');
    if (form) {
      form.addEventListener('submit', function () {
        const btn = document.getElementById('checkoutSubmit');
        btn.disabled = true;
        btn.textContent = 'Please wait…';
      });
    }
  </script>
</body>
</html>