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
sf_admin_require($conn, 'payouts');

$tabs = ['all' => 'All', 'pending' => 'Pending', 'flagged' => 'Flagged', 'paid' => 'Paid', 'rejected' => 'Rejected'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';

// Payout status => chip style
$chips = ['pending' => 'pending', 'flagged' => 'flagged', 'paid' => 'active', 'rejected' => 'draft'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Decision on a request (rules in sf_decide_payout, seller.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } else {
        $requestId = (int) ($_POST['id'] ?? 0);
        $decision  = (string) ($_POST['decision'] ?? '');
        $note      = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['note'] ?? ''))), 0, 255);
        $error     = sf_decide_payout($conn, $requestId, $decision, $note);
        $done      = ['paid' => 'marked as paid', 'flagged' => 'flagged for review', 'rejected' => 'rejected'];
        if (!$error) {
            $req = $conn->query("SELECT p.amount, COALESCE(s.store_name, u.username) AS seller FROM payout_requests p
                JOIN users u ON u.id = p.seller_id LEFT JOIN seller_profiles s ON s.user_id = p.seller_id WHERE p.id = " . $requestId)->fetch_assoc();
            sf_admin_log($conn, 'payout', ['paid' => 'Processed payout', 'flagged' => 'Flagged payout', 'rejected' => 'Rejected payout'][$decision] ?? 'Updated payout',
                '₱' . number_format((float) $req['amount']) . ' to ' . $req['seller'], $decision === 'paid' ? 'done' : $decision, $decision === 'rejected');
        }
        $_SESSION['flash'] = $error
            ? ['err', $error]
            : ['ok', sf_txn_no($requestId) . ' was ' . ($done[$decision] ?? 'updated') . '. The seller has been notified.'];
        header('Location: admin_payouts.php?tab=' . $tab);
        exit;
    }
}

// ── All requests ────────────────────────────────────────────────
$requests = $conn->query("SELECT p.*, UNIX_TIMESTAMP(p.created_at) AS ts, UNIX_TIMESTAMP(p.processed_at) AS processed_ts,
           u.username, u.full_name, u.email, s.store_name
    FROM payout_requests p
    JOIN users u ON u.id = p.seller_id
    LEFT JOIN seller_profiles s ON s.user_id = p.seller_id
    WHERE p.method = 'gcash'   -- withdrawals are paid to GCash only
    ORDER BY FIELD(p.status, 'flagged', 'pending', 'paid', 'rejected'), p.created_at DESC, p.id DESC")->fetch_all(MYSQLI_ASSOC);

// Each seller's decided requests, newest first, for Payout History in the review modal
$history = [];
foreach ($requests as $r) {
    if (in_array($r['status'], ['paid', 'rejected'], true)) $history[$r['seller_id']][] = $r;
}
foreach ($history as &$list) usort($list, fn($a, $b) => ($b['processed_ts'] ?: $b['ts']) <=> ($a['processed_ts'] ?: $a['ts']));
unset($list);

$monthStart = strtotime(date('Y-m-01'));
$lastStart  = strtotime(date('Y-m-01', strtotime('first day of last month')));
$sumWhere = fn($test) => array_sum(array_map(fn($r) => (float) $r['amount'], array_filter($requests, $test)));
$paidIn   = fn($from, $to = PHP_INT_MAX) => $sumWhere(fn($r) => $r['status'] === 'paid' && $r['processed_ts'] >= $from && $r['processed_ts'] < $to);
$stats = [
    'pending'        => $sumWhere(fn($r) => $r['status'] === 'pending'),
    'pending_count'  => count(array_filter($requests, fn($r) => $r['status'] === 'pending')),
    'paid_month'     => $paidIn($monthStart),
    'paid_last'      => $paidIn($lastStart, $monthStart),
    'flagged'        => count(array_filter($requests, fn($r) => $r['status'] === 'flagged')),
];
$monthTrend = $stats['paid_last'] > 0
    ? (($stats['paid_month'] >= $stats['paid_last'] ? '+' : '') . round(($stats['paid_month'] - $stats['paid_last']) / $stats['paid_last'] * 100) . '% vs last month')
    : ($stats['paid_month'] > 0 ? 'First payouts this month' : 'Nothing paid out yet this month');

$tabCounts = ['all' => count($requests)];
foreach (['pending', 'flagged', 'paid', 'rejected'] as $s) $tabCounts[$s] = count(array_filter($requests, fn($r) => $r['status'] === $s));
$shown = $tab === 'all' ? $requests : array_values(array_filter($requests, fn($r) => $r['status'] === $tab));

$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$dollar = '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>';
$initials = fn($name) => strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($name)), 0, 2))) ?: '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Payout Oversight — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=28" />
  <link rel="stylesheet" href="css/admin-shell.css?v=6" />
</head>
<body>

  <?php sf_admin_shell_start('payouts', 'Search payouts, sellers, transactions…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Payout Oversight</h1>
          <p>Review and approve seller payout requests.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="payoutFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="export-button" id="exportPayouts">Export Payouts</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <div class="filter-panel" id="payoutFilters" hidden>
          <label for="methodFilter">Method</label>
          <select id="methodFilter">
            <option value="all">All methods</option>
            <option value="gcash">GCash</option>
            <option value="bank">Bank transfer</option>
          </select>
        </div>

        <section class="stats-grid three">
          <a href="admin_payouts.php?tab=pending" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Pending payouts</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>'); ?></div></div>
            <div class="stat-value"><?php echo $e(sf_peso($stats['pending'])); ?></div>
            <p class="stat-trend<?php echo $stats['pending_count'] ? ' attention' : ''; ?>"><?php echo $stats['pending_count'] ? '⊙ ' . $stats['pending_count'] . ' request' . ($stats['pending_count'] === 1 ? '' : 's') . ' need attention' : 'Nothing waiting'; ?></p>
          </a>
          <a href="admin_payouts.php?tab=paid" class="stat-card revenue-card">
            <div class="stat-card-top"><span class="stat-label">Total this month</span><div class="stat-icon-wrapper"><?php echo $icon('<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>'); ?></div></div>
            <div class="stat-value"><?php echo $e(sf_peso($stats['paid_month'])); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo $e($monthTrend); ?></p>
          </a>
          <a href="admin_payouts.php?tab=flagged" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Flagged withdrawals</span><div class="stat-icon-wrapper flagged-icon"><?php echo $icon('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['flagged']); ?></div>
            <p class="stat-trend<?php echo $stats['flagged'] ? ' danger' : ''; ?>"><?php echo $stats['flagged'] ? '⊙ Requires review' : 'None flagged'; ?></p>
          </a>
        </section>

        <nav class="users-tabs" aria-label="Filter payout requests">
          <?php foreach ($tabs as $key => $label): ?>
          <a href="admin_payouts.php?tab=<?php echo $key; ?>" class="users-tab<?php echo $tab === $key ? ' active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo $label; ?> <span><?php echo $tabCounts[$key]; ?></span></a>
          <?php endforeach; ?>
        </nav>

        <section class="activity-card">
          <div class="section-heading"><h2><span class="heading-icon"><?php echo $icon($dollar); ?></span>Payout Requests</h2><span class="time-badge"><?php echo date('M Y'); ?></span></div>
          <div class="table-scroll">
            <table class="users-table plain-head">
              <thead><tr><th>Seller</th><th>Amount</th><th>Method</th><th>Requested</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="payoutBody">
                <?php foreach ($shown as $r):
                  [$label] = SF_PAYOUT_STATUS[$r['status']];
                  $name    = $r['full_name'] !== '' ? $r['full_name'] : $r['username'];
                  $open    = in_array($r['status'], ['pending', 'flagged'], true);
                  $info = [
                      'id' => (int) $r['id'], 'txn' => sf_txn_no($r['id']), 'name' => $name, 'store' => $r['store_name'] ?? '',
                      'email' => $r['email'], 'amount' => sf_peso_exact($r['amount']), 'destination' => $r['destination'],
                      'requested' => date('M j, Y g:i A', $r['ts']), 'status' => $r['status'], 'statusLabel' => $label,
                      'note' => $r['admin_note'], 'processed' => $r['processed_ts'] ? date('M j, Y', $r['processed_ts']) : '',
                      'method' => $r['method'] === 'gcash' ? 'GCash' : 'Bank transfer',
                      'history' => array_map(fn($h) => [date('M d, Y', $h['processed_ts'] ?: $h['ts']), sf_peso_exact($h['amount']), $h['status'] === 'paid' ? 'Completed' : 'Rejected'],
                          array_slice(array_values(array_filter($history[$r['seller_id']] ?? [], fn($h) => $h['id'] !== $r['id'])), 0, 3)),
                  ];
                ?>
                <tr class="<?php echo $r['status'] === 'flagged' ? 'row-flagged' : ''; ?>" data-search="<?php echo $e(mb_strtolower($name . ' ' . ($r['store_name'] ?? '') . ' ' . $r['email'] . ' ' . sf_txn_no($r['id']))); ?>" data-method="<?php echo $e($r['method']); ?>">
                  <td>
                    <div class="user-cell">
                      <span class="user-avatar <?php echo $r['status'] === 'paid' ? 'approved' : ($r['status'] === 'flagged' ? 'rejected' : ($r['status'] === 'rejected' ? 'muted' : '')); ?>"><?php echo $e($initials($name)); ?></span>
                      <span><strong><?php echo $e($name); ?></strong><span><?php echo sf_txn_no($r['id']); ?><?php echo $r['store_name'] ? ' · ' . $e($r['store_name']) : ''; ?></span></span>
                    </div>
                  </td>
                  <td class="price-cell"><?php echo $e(sf_peso($r['amount'])); ?></td>
                  <td class="muted-cell"><?php echo $r['method'] === 'gcash' ? 'GCash' : 'Bank transfer'; ?></td>
                  <td class="muted-cell"><?php echo date('M j, Y', $r['ts']); ?></td>
                  <td><span class="status-chip caps <?php echo $chips[$r['status']]; ?>"><?php echo $label; ?></span></td>
                  <td class="col-action"><button type="button" class="manage-btn<?php echo $r['status'] === 'flagged' ? ' danger' : ''; ?>" onclick='openPayout(<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Review</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyPayouts"<?php echo $shown ? ' hidden' : ''; ?>><?php echo $shown ? 'No payout requests match your search.' : 'No ' . ($tab === 'all' ? '' : strtolower($tabs[$tab]) . ' ') . 'payout requests.'; ?></p>
        </section>
      </div>

  <!-- ==================== REVIEW PAYOUT MODAL ==================== -->
  <dialog class="adm-modal wide review-modal payout-modal" id="payoutModal" aria-labelledby="pHeading">
    <div class="review-head">
      <h2 id="pHeading">Review Payout</h2>
      <span class="review-no" id="pNo"></span>
      <button type="button" class="adm-modal-close" onclick="closePayout()" aria-label="Close">&times;</button>
    </div>
    <dl class="card-facts payout-facts">
      <div><dt>Seller</dt><dd id="pName"></dd></div>
      <div><dt>Amount</dt><dd id="pAmount"></dd></div>
      <div><dt>Method</dt><dd><span id="pMethod"></span><small id="pDestination"></small></dd></div>
      <div><dt>Requested</dt><dd id="pRequested"></dd></div>
      <div><dt>Status</dt><dd><span class="outline-chip" id="pStatus"></span></dd></div>
    </dl>

    <h3 class="payout-sub">Payout History</h3>
    <table class="payout-history">
      <tbody id="pHistory"></tbody>
    </table>
    <p class="payout-history-empty" id="pHistoryEmpty">No earlier payouts for this seller.</p>
    <div class="review-preview log" id="pNoteBox">
      <p class="review-preview-label">Admin note</p>
      <p id="pNote"></p>
    </div>

    <form method="post" id="pForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="id" id="pId" />
      <div class="adm-field">
        <label for="pNoteInput">Admin Notes</label>
        <textarea id="pNoteInput" name="note" maxlength="255" rows="3" placeholder="Write internal notes about your review here…"></textarea>
        <span class="adm-field-hint">Sent to the seller with your decision; required to reject. Station Finds doesn't send money automatically: transfer it first, then approve.</span>
      </div>
      <div class="review-actions">
        <button type="submit" name="decision" value="paid" class="export-button" id="pPay">Approve Payout</button>
        <button type="submit" name="decision" value="flagged" class="filter-button" id="pFlag">Flag Payout</button>
        <button type="submit" name="decision" value="rejected" class="filter-button danger-outline" id="pReject">Reject Request</button>
      </div>
    </form>
  </dialog>

  <?php sf_admin_shell_end(); ?>

  <script>
    const modal = document.getElementById('payoutModal');
    function closePayout() { modal.close(); }
    modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });

    function openPayout(p) {
      const set = (id, text) => { document.getElementById(id).textContent = text; };
      set('pNo', '#' + p.txn);
      set('pName', p.name + (p.store ? ' · ' + p.store : ''));
      set('pAmount', p.amount);
      set('pMethod', p.method);
      set('pDestination', p.destination);
      set('pRequested', p.requested);
      const status = document.getElementById('pStatus');
      status.textContent = p.statusLabel;
      status.className = 'outline-chip ' + p.status;

      // Earlier decided requests from the same seller
      const body = document.getElementById('pHistory');
      body.innerHTML = '';
      p.history.forEach(h => {
        const row = body.insertRow();
        h.forEach((text, i) => { const cell = row.insertCell(); cell.textContent = text; if (i === 2 && text === 'Rejected') cell.className = 'danger-text'; });
      });
      document.getElementById('pHistoryEmpty').hidden = p.history.length > 0;
      set('pNote', p.note + (p.processed ? ' (' + p.processed + ')' : ''));
      document.getElementById('pNoteBox').hidden = !p.note;

      document.getElementById('pId').value = p.id;
      const note = document.getElementById('pNoteInput');
      note.value = '';
      note.classList.remove('has-error');
      const open = p.status === 'pending' || p.status === 'flagged';
      document.getElementById('pForm').hidden = !open;
      document.getElementById('pFlag').hidden = p.status !== 'pending';
      document.getElementById('pPay').dataset.confirm = 'Approve ' + p.txn + ' and mark it as paid? Only do this after sending ' + p.amount + '.';
      modal.showModal();
    }

    document.getElementById('pForm').addEventListener('submit', e => {
      const decision = e.submitter ? e.submitter.value : '';
      const note = document.getElementById('pNoteInput');
      if (decision === 'rejected' && !note.value.trim()) {
        e.preventDefault();
        note.classList.add('has-error');
        note.focus();
      } else if (decision === 'paid' && !confirm(document.getElementById('pPay').dataset.confirm)) {
        e.preventDefault();
      }
    });

    // Search (top bar) and method filter
    const rows = [...document.querySelectorAll('#payoutBody tr')];
    const method = document.getElementById('methodFilter');
    let query = '';
    function applyFilters() {
      rows.forEach(r => { r.hidden = !r.dataset.search.includes(query) || (method.value !== 'all' && r.dataset.method !== method.value); });
      if (rows.length) document.getElementById('emptyPayouts').hidden = rows.some(r => !r.hidden);
    }
    window.onAdminSearch = q => { query = q; applyFilters(); };
    method.addEventListener('change', applyFilters);
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('payoutFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // Export the visible payout requests as a CSV file
    document.getElementById('exportPayouts').addEventListener('click', () => {
      const lines = [['Request', 'Seller', 'Amount', 'Method', 'Requested', 'Status']];
      rows.filter(r => !r.hidden).forEach(r => {
        const c = r.cells;
        lines.push([c[0].querySelector('strong + span').textContent.split(' · ')[0], c[0].querySelector('strong').textContent,
                    c[1].textContent, c[2].textContent, c[3].textContent, c[4].textContent].map(v => v.trim()));
      });
      const csv = lines.map(row => row.map(v => '"' + String(v).replaceAll('"', '""') + '"').join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
      const link = document.createElement('a');
      link.href = url; link.download = 'payouts.csv'; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  </script>
</body>
</html>
