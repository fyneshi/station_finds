<?php
session_start();

// Admins only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php?mode=login');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/seller.php';
require_once __DIR__ . '/includes/admin_layout.php';
sf_admin_require($conn, 'transactions');

$tabs = ['all' => 'All', 'processing' => 'Processing', 'completed' => 'Completed', 'disputed' => 'Disputed'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';

// Order status => [label, chip style]
$statuses = [
    'processing' => ['Processing', 'pending'],
    'completed'  => ['Completed', 'active'],
    'disputed'   => ['Disputed', 'flagged'],
    'cancelled'  => ['Cancelled', 'draft'],   // every item cancelled by the buyer
];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Settle a dispute ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'resolve') {
        $note  = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['note'] ?? ''))), 0, 255);
        $itemId = (int) ($_POST['item'] ?? 0);
        $error  = sf_resolve_dispute($conn, $itemId, (string) ($_POST['outcome'] ?? ''), $note);
        if (!$error) {
            $item   = $conn->query("SELECT oi.title, o.order_no FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.id = " . $itemId)->fetch_assoc();
            $refund = $_POST['outcome'] === 'refund';
            sf_admin_log($conn, 'dispute', $refund ? 'Refunded disputed order' : 'Closed dispute, paid seller',
                $item['title'] . ' · ' . $item['order_no'], $refund ? 'refunded' : 'resolved', $refund);
        }
        $_SESSION['flash'] = $error
            ? ['err', $error]
            : ['ok', ($_POST['outcome'] === 'refund' ? 'Refund approved. Send the money back to the buyer, then you\'re done.' : 'Dispute closed. The seller keeps the sale.') . ' Both sides have been notified.'];
        header('Location: admin_transactions.php?tab=' . $tab);
        exit;
    }
}

// ── Orders and their items ──────────────────────────────────────
$orders = $conn->query("SELECT o.*, UNIX_TIMESTAMP(o.created_at) AS ts, u.username, u.email
                        FROM orders o JOIN users u ON u.id = o.user_id
                        ORDER BY o.created_at DESC, o.id DESC")->fetch_all(MYSQLI_ASSOC);
$itemsByOrder = [];
$res = $conn->query("SELECT oi.*, UNIX_TIMESTAMP(oi.disputed_at) AS disputed_ts, COALESCE(sp.store_name, oi.seller) AS seller_name
                     FROM order_items oi
                     LEFT JOIN listings l ON oi.item_key = CONCAT('listing-', l.id)
                     LEFT JOIN seller_profiles sp ON sp.user_id = l.seller_id
                     ORDER BY oi.id");
while ($row = $res->fetch_assoc()) $itemsByOrder[$row['order_id']][] = $row;

foreach ($orders as &$o) {
    $o['items']  = $itemsByOrder[$o['id']] ?? [];
    $disputed    = array_filter($o['items'], fn($i) => $i['dispute_status'] === 'open');
    $done        = array_filter($o['items'], fn($i) => sf_item_closed($i['fulfillment']) || sf_fulfillment_is_final($i['item_type'], $i['fulfillment']));
    $cancelled   = array_filter($o['items'], fn($i) => $i['fulfillment'] === 'cancelled');
    $o['status'] = $disputed ? 'disputed' : ($o['items'] && count($cancelled) === count($o['items']) ? 'cancelled'
                 : ($o['items'] && count($done) === count($o['items']) ? 'completed' : 'processing'));
    $o['sellers'] = implode(', ', array_unique(array_column($o['items'], 'seller_name')));
}
unset($o);

$monthStart = strtotime(date('Y-m-01'));
$lastStart  = strtotime(date('Y-m-01', strtotime('first day of last month')));
$completedIn = fn($from, $to = PHP_INT_MAX) => count(array_filter($orders, fn($o) => $o['status'] === 'completed' && $o['ts'] >= $from && $o['ts'] < $to));
$stats = [
    'total'      => count($orders),
    'month'      => count(array_filter($orders, fn($o) => $o['ts'] >= $monthStart)),
    'processing' => count(array_filter($orders, fn($o) => $o['status'] === 'processing')),
    'completed'  => count(array_filter($orders, fn($o) => $o['status'] === 'completed')),
    'disputed'   => count(array_filter($orders, fn($o) => $o['status'] === 'disputed')),
];
$cMonth = $completedIn($monthStart);
$cLast  = $completedIn($lastStart, $monthStart);
$completedTrend = $cLast > 0 ? (($cMonth >= $cLast ? '+' : '') . round(($cMonth - $cLast) / $cLast * 100) . '% vs last month')
                             : ($cMonth ? $cMonth . ' this month' : 'None yet this month');

$tabCounts = ['all' => $stats['total'], 'processing' => $stats['processing'], 'completed' => $stats['completed'], 'disputed' => $stats['disputed']];
$shown = $tab === 'all' ? $orders : array_values(array_filter($orders, fn($o) => $o['status'] === $tab));

$methods = sf_payment_methods();
$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$cardIcon = '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>';

// Everything the View / Resolve modal shows for one order
function order_info(array $o, array $methods) {
    return [
        'no' => $o['order_no'], 'date' => date('M j, Y g:i A', $o['ts']), 'day' => date('M j, Y', $o['ts']), 'status' => $o['status'],
        'amount' => sf_peso_exact($o['total']), 'sellers' => implode(', ', array_unique(array_column($o['items'], 'seller_name'))),
        'buyer' => $o['full_name'], 'username' => $o['username'], 'email' => $o['email'], 'phone' => $o['phone'],
        'address' => trim($o['address_line1'] . ', ' . ($o['address_line2'] !== '' ? $o['address_line2'] . ', ' : '') . $o['city'] . ', ' . $o['province'] . ' ' . $o['zip_code']),
        'payment' => $methods[$o['payment_method']]['label'] ?? $o['payment_method'],
        'promo' => $o['promo_code'],
        'totals' => [
            'Subtotal' => sf_peso_exact($o['subtotal']),
            'Discount' => $o['discount'] > 0 ? '−' . sf_peso_exact($o['discount']) : '',
            // Rentals and services aren't shipped: no shipping row unless the order has a product
            'Shipping' => in_array('products', array_column($o['items'], 'item_type'), true) ? sf_peso_exact($o['shipping']) : '',
            'Tax'      => sf_peso_exact($o['tax']),
            'Total'    => sf_peso_exact($o['total']),
        ],
        'items' => array_map(fn($i) => [
            'id' => (int) $i['id'], 'title' => $i['title'], 'seller' => $i['seller_name'],
            'qty' => (int) $i['quantity'], 'units' => (int) $i['units'], 'type' => $i['item_type'],
            'total' => sf_peso_exact($i['line_total']), 'status' => sf_fulfillment_label($i['item_type'], $i['fulfillment']),
            'dispute' => $i['dispute_status'], 'reason' => $i['dispute_reason'], 'details' => $i['dispute_details'],
            'outcome' => $i['dispute_outcome'], 'note' => $i['dispute_note'], 'cancel' => $i['fulfillment'] === 'cancelled' ? ($i['cancel_reason'] !== '' ? $i['cancel_reason'] : 'No reason given') : '',
            'disputed' => $i['disputed_ts'] ? date('M j, Y', $i['disputed_ts']) : '',
            // A rental's return / refund request the owner declined: the owner's reason and the renter's photos
            'declined' => $i['return_status'] === 'declined' ? $i['return_response'] : '',
            'photos' => sf_return_photos($i['return_photos']),
            'returned' => $i['return_status'] === 'accepted' ? $i['return_reason'] : '',
        ], $o['items']),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Transaction Management — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=25" />
  <link rel="stylesheet" href="css/admin-shell.css?v=6" />
</head>
<body>

  <?php sf_admin_shell_start('transactions', 'Search transactions, orders, users…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Transaction Management</h1>
          <p>Monitor and protect marketplace transactions.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="txFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="export-button" id="exportReport">Export Report</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <div class="filter-panel" id="txFilters" hidden>
          <label for="paymentFilter">Payment</label>
          <select id="paymentFilter">
            <option value="all">All methods</option>
            <?php foreach ($methods as $key => $m): ?><option value="<?php echo $key; ?>"><?php echo $e($m['label']); ?></option><?php endforeach; ?>
          </select>
          <label for="periodFilter">Placed</label>
          <select id="periodFilter">
            <option value="all">Any time</option>
            <option value="1">Last 24 hours</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
          </select>
        </div>

        <section class="stats-grid">
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Total transactions</span><div class="stat-icon-wrapper"><?php echo $icon($cardIcon); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> +<?php echo $stats['month']; ?> this month</p>
          </div>
          <a href="admin_transactions.php?tab=processing" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Processing</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['processing']); ?></div>
            <p class="stat-trend<?php echo $stats['processing'] ? ' attention' : ''; ?>"><?php echo $stats['processing'] ? '⊙ Being fulfilled by sellers' : 'Nothing in progress'; ?></p>
          </a>
          <a href="admin_transactions.php?tab=completed" class="stat-card revenue-card">
            <div class="stat-card-top"><span class="stat-label">Completed</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['completed']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo $e($completedTrend); ?></p>
          </a>
          <a href="admin_transactions.php?tab=disputed" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Disputed</span><div class="stat-icon-wrapper flagged-icon"><?php echo $icon('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['disputed']); ?></div>
            <p class="stat-trend<?php echo $stats['disputed'] ? ' danger' : ''; ?>"><?php echo $stats['disputed'] ? '⊙ Requires review' : 'No open disputes'; ?></p>
          </a>
        </section>

        <nav class="users-tabs" aria-label="Filter transactions">
          <?php foreach ($tabs as $key => $label): ?>
          <a href="admin_transactions.php?tab=<?php echo $key; ?>" class="users-tab<?php echo $tab === $key ? ' active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo $label; ?> <span><?php echo $tabCounts[$key]; ?></span></a>
          <?php endforeach; ?>
        </nav>

        <section class="activity-card">
          <div class="section-heading"><h2><span class="heading-icon"><?php echo $icon($cardIcon); ?></span>Transactions</h2><span class="time-badge" id="txCount"><?php echo count($shown); ?> order<?php echo count($shown) === 1 ? '' : 's'; ?></span></div>
          <div class="table-scroll">
            <table class="users-table plain-head">
              <thead><tr><th>Transaction</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="txBody">
                <?php foreach ($shown as $o):
                  [$statusLabel, $chip] = $statuses[$o['status']];
                  $info = order_info($o, $methods);
                ?>
                <tr data-search="<?php echo $e(mb_strtolower($o['order_no'] . ' ' . $o['full_name'] . ' ' . $o['username'] . ' ' . $o['email'] . ' ' . $o['sellers'])); ?>" data-payment="<?php echo $e($o['payment_method']); ?>" data-ts="<?php echo (int) $o['ts']; ?>">
                  <td><div class="listing-cell"><span class="report-icon"><?php echo $icon($cardIcon); ?></span><span><strong>Order #<?php echo $e($o['order_no']); ?></strong><span><?php echo date('M j, Y', $o['ts']); ?> · <?php echo count($o['items']); ?> item<?php echo count($o['items']) === 1 ? '' : 's'; ?></span></span></div></td>
                  <td class="muted-cell"><?php echo $e($o['full_name']); ?></td>
                  <td class="muted-cell"><?php echo $e($o['sellers'] ?: '—'); ?></td>
                  <td class="price-cell"><?php echo $e(sf_peso($o['total'])); ?></td>
                  <td><span class="status-chip caps <?php echo $chip; ?>"><?php echo $statusLabel; ?></span></td>
                  <td class="col-action"><button type="button" class="manage-btn<?php echo $o['status'] === 'disputed' ? ' danger' : ''; ?>" onclick='openOrder(<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'><?php echo $o['status'] === 'disputed' ? 'Resolve' : 'View'; ?></button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyTx"<?php echo $shown ? ' hidden' : ''; ?>><?php echo $shown ? 'No transactions match your search.' : 'No ' . ($tab === 'all' ? '' : strtolower($tabs[$tab]) . ' ') . 'transactions.'; ?></p>
        </section>
      </div>

  <!-- ==================== ORDER / DISPUTE MODAL ==================== -->
  <dialog class="adm-modal wide review-modal tx-modal" id="orderModal" aria-labelledby="oHeading">
    <div class="card-modal-head">
      <div>
        <h2 id="oHeading">Transaction Details</h2>
        <p class="card-modal-sub" id="oSub"></p>
      </div>
      <button type="button" class="adm-modal-close" onclick="closeOrder()" aria-label="Close">&times;</button>
    </div>
    <dl class="card-facts tx-facts">
      <div><dt>Transaction</dt><dd id="oTitle"></dd></div>
      <div><dt>Buyer</dt><dd id="oBuyer"></dd></div>
      <div><dt>Seller</dt><dd id="oSeller"></dd></div>
      <div><dt>Amount</dt><dd id="oAmount"></dd></div>
      <div><dt>Status</dt><dd><span class="review-status" id="oStatus"></span></dd></div>
      <div><dt>Date</dt><dd id="oDate"></dd></div>
      <div><dt>Payment Method</dt><dd id="oPayment"></dd></div>
    </dl>
    <h3 class="payout-sub">Order Items</h3>
    <div id="oItems"></div>
    <button type="button" class="filter-button tx-close" onclick="closeOrder()">Close</button>
  </dialog>

  <?php sf_admin_shell_end(); ?>

  <script>
    const CSRF = <?php echo json_encode($csrf); ?>;
    const TAB  = <?php echo json_encode($tab); ?>;
    const modal = document.getElementById('orderModal');
    function closeOrder() { modal.close(); }
    modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });

    // Builds DOM from text only (no HTML from the data)
    function el(tag, cls, text) {
      const node = document.createElement(tag);
      if (cls) node.className = cls;
      if (text !== undefined) node.textContent = text;
      return node;
    }

    const statusLabels = <?php echo json_encode(array_map(fn($s) => $s[0], $statuses)); ?>;
    const statusTones  = { completed: 'resolved', processing: 'pending', disputed: 'flagged', cancelled: 'dismissed' };
    function openOrder(o) {
      const set = (id, text) => { document.getElementById(id).textContent = text; };
      set('oSub', 'Review transaction #' + o.no);
      set('oTitle', 'Order #' + o.no);
      set('oBuyer', o.buyer || o.username);
      document.getElementById('oBuyer').title = o.email;
      set('oSeller', o.sellers);
      set('oAmount', o.amount);
      const status = document.getElementById('oStatus');
      status.textContent = statusLabels[o.status] || o.status;
      status.className = 'review-status ' + (statusTones[o.status] || '');
      set('oDate', o.day);
      document.getElementById('oDate').title = o.date;
      set('oPayment', o.payment);

      const items = document.getElementById('oItems');
      items.innerHTML = '';
      o.items.forEach(i => {
        const box = el('div', 'order-item' + (i.dispute === 'open' ? ' disputed' : ''));
        const top = el('div', 'order-item-top');
        const left = el('div');
        const extra = i.type !== 'products' && i.units > 1 ? ' · ' + i.units + (i.type === 'rentals' ? ' days' : ' units') : '';
        left.append(el('strong', '', i.title), el('span', '', 'Qty: ' + i.qty + extra + ' · ' + (i.dispute === 'open' ? 'Disputed' : i.status)));
        top.append(left, el('strong', 'order-item-price', i.total));
        box.appendChild(top);

        if (i.dispute === 'open') {
          box.appendChild(el('p', 'dispute-text', '“' + i.reason + '” · reported ' + i.disputed + ': ' + i.details));
          if (i.declined) box.appendChild(el('p', 'dispute-text muted', 'The owner declined the renter\'s return / refund request: ' + i.declined));
          if (i.photos.length) {
            const photos = el('div', 'dispute-photos');
            i.photos.forEach(p => { const a = el('a'); a.href = p; a.target = '_blank'; a.rel = 'noopener'; const img = el('img'); img.src = p; img.alt = 'Photo from the renter'; a.append(img); photos.append(a); });
            box.appendChild(photos);
          }
          const form = document.createElement('form');
          form.method = 'post';
          form.className = 'resolve-form';
          form.innerHTML = '<input type="hidden" name="action" value="resolve" />'
            + '<div class="resolve-choices">'
            + '<label><input type="radio" name="outcome" value="refund" required /> Refund buyer</label>'
            + '<label><input type="radio" name="outcome" value="release" /> Release to seller</label></div>'
            + '<div class="adm-field"><label>Note <span>(sent to both the buyer and the seller)</span></label><input type="text" name="note" maxlength="255" required /></div>'
            + '<div class="adm-modal-actions"><button type="submit" class="export-button">Resolve dispute</button></div>';
          const csrf = el('input'); csrf.type = 'hidden'; csrf.name = 'csrf_token'; csrf.value = CSRF;
          const item = el('input'); item.type = 'hidden'; item.name = 'item'; item.value = i.id;
          form.append(csrf, item);
          form.action = 'admin_transactions.php?tab=' + TAB;
          form.addEventListener('submit', e => {
            const note = form.querySelector('[name="note"]');
            if (!form.querySelector('[name="outcome"]:checked') || !note.value.trim()) {
              e.preventDefault();
              note.classList.add('has-error');
              note.placeholder = 'Choose an outcome and add a note';
            }
          });
          box.appendChild(form);
        } else if (i.cancel) {
          box.appendChild(el('p', 'dispute-text muted', 'Cancelled by the buyer: ' + i.cancel));
        } else if (i.returned) {
          box.appendChild(el('p', 'dispute-text muted', 'Refunded by the owner after a return / refund request (“' + i.returned + '”).'));
        } else if (i.dispute === 'resolved') {
          box.appendChild(el('p', 'dispute-text muted', (i.outcome === 'refund' ? 'Refunded' : 'Released to seller') + ' after a dispute (“' + i.reason + '”). Note: ' + i.note));
        }
        items.appendChild(box);
      });

      modal.showModal();
    }

    // Search (top bar), payment and date filters
    const rows = [...document.querySelectorAll('#txBody tr')];
    const payment = document.getElementById('paymentFilter');
    const period = document.getElementById('periodFilter');
    let query = '';
    function applyFilters() {
      const since = period.value === 'all' ? 0 : Date.now() / 1000 - Number(period.value) * 86400;
      rows.forEach(r => {
        r.hidden = !r.dataset.search.includes(query) || (payment.value !== 'all' && r.dataset.payment !== payment.value) || Number(r.dataset.ts) < since;
      });
      const visible = rows.filter(r => !r.hidden).length;
      document.getElementById('txCount').textContent = visible + (visible === 1 ? ' order' : ' orders');
      if (rows.length) document.getElementById('emptyTx').hidden = visible > 0;
    }
    window.onAdminSearch = q => { query = q; applyFilters(); };
    payment.addEventListener('change', applyFilters);
    period.addEventListener('change', applyFilters);
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('txFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // Export the visible transactions as a CSV file
    document.getElementById('exportReport').addEventListener('click', () => {
      const lines = [['Order', 'Date', 'Buyer', 'Seller', 'Amount', 'Status']];
      rows.filter(r => !r.hidden).forEach(r => {
        const c = r.cells;
        lines.push([c[0].querySelector('strong').textContent, c[0].querySelector('strong + span').textContent.split(' · ')[0],
                    c[1].textContent, c[2].textContent, c[3].textContent, c[4].textContent].map(v => v.trim()));
      });
      const csv = lines.map(row => row.map(v => '"' + String(v).replaceAll('"', '""') + '"').join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
      const link = document.createElement('a');
      link.href = url; link.download = 'transactions.csv'; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  </script>
</body>
</html>
