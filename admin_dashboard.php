<?php
session_start();

// Check if user is authenticated and has admin role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/seller.php';
require_once __DIR__ . '/includes/admin_layout.php';

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ── Marketplace numbers (everything here comes from the live database) ──
$m = $conn->query("SELECT
    (SELECT COUNT(*) FROM users WHERE role <> 'admin') AS users,
    (SELECT COUNT(*) FROM users WHERE role <> 'admin' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS users_month,
    (SELECT COUNT(*) FROM listings WHERE status = 'active') AS listings,
    (SELECT COUNT(*) FROM listings WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS listings_month,
    " . sf_net_sales_sql("o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')") . " AS sales_month,
    " . sf_net_sales_sql("o.created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND o.created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')") . " AS sales_last,
    (SELECT COUNT(*) FROM orders WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS orders_month,
    (SELECT COUNT(*) FROM seller_profiles s JOIN users u ON u.id = s.user_id WHERE u.status = 'active') AS sellers,
    (SELECT COALESCE(AVG(price), 0) FROM listings WHERE status = 'active') AS avg_price")->fetch_assoc();
$counts    = sf_admin_counts($conn);
$attention = array_sum($counts);   // disputes + reports + seller applications + open payouts + listings to review + new feedback

// Finished order items (delivered, returned or completed)
$completed = 0;
$res = $conn->query("SELECT item_type, fulfillment FROM order_items");
while ($row = $res->fetch_assoc()) {
    if (sf_fulfillment_is_final($row['item_type'], $row['fulfillment'])) $completed++;
}

// "↗ +12% vs last month"
function trend_text($now, $last, $suffix) {
    if ($last > 0) {
        $pct = round(($now - $last) / $last * 100);
        return ($pct >= 0 ? '↗ +' : '↘ ') . $pct . '% ' . $suffix;
    }
    return $now > 0 ? '↗ New ' . $suffix : 'No sales yet';
}

// ── Recent activity: newest events across the marketplace ──
$activity = $conn->query("
    (SELECT 'user' AS kind, 'New user registered' AS text, email AS ref, UNIX_TIMESTAMP(created_at) AS ts, 'new' AS status
       FROM users WHERE role <> 'admin')
    UNION ALL
    (SELECT 'seller', 'New seller joined', store_name, UNIX_TIMESTAMP(created_at), 'new' FROM seller_profiles)
    UNION ALL
    (SELECT 'listing', IF(status = 'active', 'Listing published', 'Listing saved as draft'), title, UNIX_TIMESTAMP(created_at),
            IF(status = 'active', 'done', 'pending') FROM listings)
    UNION ALL
    (SELECT 'order', 'Order placed', CONCAT('#', order_no, ' · ', full_name), UNIX_TIMESTAMP(created_at), 'done' FROM orders)
    UNION ALL
    (SELECT 'payout', CONCAT('Payout ', status), CONCAT('TXN-', LPAD(id, 4, '0')), UNIX_TIMESTAMP(created_at),
            CASE status WHEN 'paid' THEN 'done' WHEN 'flagged' THEN 'flagged' WHEN 'rejected' THEN 'dispute' ELSE 'pending' END
       FROM payout_requests)
    UNION ALL
    (SELECT 'feedback', IF(type = 'platform', 'Feedback received', 'Rating left'), name, UNIX_TIMESTAMP(created_at),
            CASE status WHEN 'pending' THEN 'pending' WHEN 'escalated' THEN 'flagged' WHEN 'flagged' THEN 'flagged' ELSE 'done' END FROM feedback)
    ORDER BY ts DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$pillLabels = ['new' => 'New', 'done' => 'Done', 'pending' => 'Pending', 'flagged' => 'Flagged', 'dispute' => 'Rejected'];
$activityIcons = [
    'user'     => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-4a5 5 0 0 1 10 0v4M16 4a3 3 0 0 1 0 6M17 14a4 4 0 0 1 4 4v3"/>',
    'seller'   => '<circle cx="12" cy="8" r="5"/><path d="m9 13-1 8 4-2 4 2-1-8"/>',
    'listing'  => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    'order'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/>',
    'payout'   => '<path d="M12 2v20M17 5H9a3.5 3.5 0 0 0 0 7h6a3.5 3.5 0 0 1 0 7H6"/>',
    'feedback' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
];

// ── Newest published listings ──
$recentListings = array_slice(sf_catalog(), 0, 2, true);
$typeLabels = ['products' => 'Product', 'rentals' => 'Rental', 'services' => 'Service'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Station Finds — Admin Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=23" />
  <link rel="stylesheet" href="css/admin-shell.css?v=3" />
</head>
<body>

  <?php sf_admin_shell_start('overview', 'Search activity and listings…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Dashboard Overview</h1>
          <p>A snapshot of activity across the marketplace.</p>
        </div>
        <div class="header-actions">
          <button class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="activityFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button class="export-button" id="exportReport">Export Report</button>
        </div>
      </header>

      <div class="dashboard-content">
        <div class="filter-panel" id="activityFilters" hidden>
          <label for="statusFilter">Activity status</label>
          <select id="statusFilter">
            <option value="all">All activity</option>
            <?php foreach ($pillLabels as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo $label; ?></option><?php endforeach; ?>
          </select>
          <button id="resetFilters" class="filter-button">Reset</button>
        </div>

        <!-- Marketplace metrics -->
        <section class="stats-grid">
          <a href="admin_users.php" class="stat-card">
            <div class="stat-card-top">
              <span class="stat-label">Total users</span>
              <div class="stat-icon-wrapper"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg></div>
            </div>
            <div class="stat-value" id="statTotalUsers"><?php echo number_format($m['users']); ?></div>
            <p class="stat-trend">↗ +<?php echo (int) $m['users_month']; ?> this month</p>
          </a>

          <div class="stat-card">
            <div class="stat-card-top">
              <span class="stat-label">Active listings</span>
              <div class="stat-icon-wrapper"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg></div>
            </div>
            <div class="stat-value" id="statTotalListings"><?php echo number_format($m['listings']); ?></div>
            <p class="stat-trend">↗ +<?php echo (int) $m['listings_month']; ?> posted this month</p>
          </div>

          <div class="stat-card revenue-card">
            <div class="stat-card-top">
              <span class="stat-label">Sales this month</span>
              <div class="stat-icon-wrapper"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg></div>
            </div>
            <div class="stat-value" id="statRevenue"><?php echo $e(sf_peso($m['sales_month'])); ?></div>
            <p class="stat-trend"><?php echo $e(trend_text((float) $m['sales_month'], (float) $m['sales_last'], 'vs last month')); ?> · <?php echo (int) $m['orders_month']; ?> order<?php echo $m['orders_month'] == 1 ? '' : 's'; ?></p>
          </div>

          <a href="<?php echo $counts['transactions'] ? 'admin_transactions.php' : ($counts['moderation'] ? 'admin_moderation.php' : ($counts['seller-apps'] ? 'admin_sellers.php' : ($counts['payouts'] ? 'admin_payouts.php' : ($counts['listings'] ? 'admin_listings.php' : 'admin_feedback.php')))); ?>" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Needs attention</span><div class="stat-icon-wrapper pending-icon"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg></div></div>
            <div class="stat-value"><?php echo $attention; ?></div>
            <p class="stat-trend<?php echo $attention ? ' attention' : ''; ?>"><?php echo $counts['transactions']; ?> dispute<?php echo $counts['transactions'] === 1 ? '' : 's'; ?> · <?php echo $counts['moderation']; ?> report<?php echo $counts['moderation'] === 1 ? '' : 's'; ?> · <?php echo $counts['seller-apps']; ?> application<?php echo $counts['seller-apps'] === 1 ? '' : 's'; ?> · <?php echo $counts['payouts']; ?> payout<?php echo $counts['payouts'] === 1 ? '' : 's'; ?> · <?php echo $counts['listings']; ?> to review · <?php echo $counts['feedback']; ?> feedback</p>
          </a>
        </section>

        <div class="overview-grid">
          <section class="activity-card" aria-labelledby="activityTitle">
            <div class="section-heading"><h2 id="activityTitle"><span class="heading-icon"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h5l3-9 4 18 3-9h5"/></svg></span>Recent Activity</h2><span class="time-badge">Latest <?php echo count($activity); ?></span></div>
            <div class="table-scroll">
              <table class="activity-table">
                <thead><tr><th>Activity</th><th>User / Reference</th><th>When</th><th>Status</th></tr></thead>
                <tbody>
                  <?php foreach ($activity as $a): ?>
                  <tr data-status="<?php echo $e($a['status']); ?>">
                    <td><div class="activity-description"><span class="activity-icon"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?php echo $activityIcons[$a['kind']]; ?></svg></span><span><?php echo $e(ucfirst($a['text'])); ?></span></div></td>
                    <td><?php echo $e($a['ref']); ?></td>
                    <td><?php echo sf_time_ago($a['ts']); ?></td>
                    <td><span class="status-pill <?php echo $e($a['status']); ?>"><?php echo $pillLabels[$a['status']] ?? $e($a['status']); ?></span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="empty-state" id="emptyActivity"<?php echo $activity ? ' hidden' : ''; ?>><?php echo $activity ? 'No activity matches your search.' : 'Nothing has happened on the marketplace yet.'; ?></p>
          </section>

          <aside class="listing-column" aria-label="Marketplace overview">
            <div class="listing-heading"><h2>Recent Listings</h2></div>
            <?php foreach ($recentListings as $l): ?>
            <article class="listing-card recent-listing">
              <div class="listing-photo"><img src="<?php echo $e($l['image']); ?>" alt="<?php echo $e($l['title']); ?>"<?php echo $l['image'] === SF_PLACEHOLDER_IMAGE ? ' class="placeholder"' : ''; ?> /></div>
              <div class="listing-details">
                <div class="listing-badges"><span><?php echo $typeLabels[$l['type']]; ?></span><span><?php echo $e($l['term']); ?></span></div>
                <h3><?php echo $e($l['title']); ?></h3>
                <div class="listing-price"><strong><?php echo $e(sf_peso($l['price']) . ($l['unit'] !== '' ? '/' . $l['unit'] : '')); ?></strong><span class="listing-seller"><?php echo $e($l['seller']); ?></span></div>
              </div>
            </article>
            <?php endforeach; ?>
            <p class="empty-state" id="emptyListings"<?php echo $recentListings ? ' hidden' : ''; ?>><?php echo $recentListings ? 'No listings match your search.' : 'No published listings yet.'; ?></p>

            <section class="health-card" aria-labelledby="healthTitle">
              <h2 id="healthTitle">Platform Health</h2>
              <dl>
                <div><dt>Active sellers</dt><dd><?php echo number_format($m['sellers']); ?></dd></div>
                <div><dt>Completed order items</dt><dd><?php echo number_format($completed); ?></dd></div>
                <div><dt>Avg. listing price</dt><dd><?php echo $e(sf_peso(round($m['avg_price']))); ?></dd></div>
              </dl>
            </section>
          </aside>
        </div>
      </div>

  <?php sf_admin_shell_end(); ?>

  <script>
    // ── Search (top bar) and status filter for the activity table ──
    const statusFilter = document.getElementById('statusFilter');
    const activityRows = [...document.querySelectorAll('.activity-table tbody tr')];
    const listingCards = [...document.querySelectorAll('.recent-listing')];
    let query = '';
    function filterDashboard() {
      activityRows.forEach(row => {
        row.hidden = !row.textContent.toLowerCase().includes(query) ||
          (statusFilter.value !== 'all' && row.dataset.status !== statusFilter.value);
      });
      if (activityRows.length) document.getElementById('emptyActivity').hidden = activityRows.some(row => !row.hidden);
      listingCards.forEach(card => { card.hidden = !card.textContent.toLowerCase().includes(query); });
      if (listingCards.length) document.getElementById('emptyListings').hidden = listingCards.some(card => !card.hidden);
    }
    window.onAdminSearch = q => { query = q; filterDashboard(); };
    statusFilter.addEventListener('change', filterDashboard);
    document.getElementById('filterToggle').addEventListener('click', event => {
      const panel = document.getElementById('activityFilters');
      panel.hidden = !panel.hidden;
      event.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });
    document.getElementById('resetFilters').addEventListener('click', () => {
      document.getElementById('adminSearch').value = '';
      query = '';
      statusFilter.value = 'all';
      filterDashboard();
    });

    // ── Export the numbers and the visible activity as a CSV file ──
    document.getElementById('exportReport').addEventListener('click', () => {
      const report = [['Metric', 'Value'],
        ['Total users', document.getElementById('statTotalUsers').textContent],
        ['Active listings', document.getElementById('statTotalListings').textContent],
        ['Sales this month', document.getElementById('statRevenue').textContent],
        [], ['Activity (current filters)', 'User / Reference', 'When', 'Status'],
        ...activityRows.filter(row => !row.hidden).map(row => [...row.cells].map(cell => cell.textContent.trim()))];
      const csv = report.map(row => row.map(value => '"' + String(value).replaceAll('"', '""') + '"').join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
      const link = document.createElement('a');
      link.href = url; link.download = 'station-finds-dashboard.csv'; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  </script>
</body>
</html>
