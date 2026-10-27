<?php
session_start();

// Admins only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/admin_layout.php';
sf_admin_require($conn, 'reports');

// ── Export log ──────────────────────────────────────────────────
$exports = $conn->query("SELECT *, UNIX_TIMESTAMP(created_at) AS ts FROM report_exports ORDER BY created_at DESC, id DESC")->fetch_all(MYSQLI_ASSOC);
$monthStart = strtotime(date('Y-m-01'));
$lastStart  = strtotime(date('Y-m-01', strtotime('first day of last month')));
$exportStats = [
    'total' => count($exports),
    'month' => count(array_filter($exports, fn($x) => $x['ts'] >= $monthStart)),
    'last'  => count(array_filter($exports, fn($x) => $x['ts'] >= $lastStart && $x['ts'] < $monthStart)),
];
$latest = $exports[0] ?? null;

// ── Seven-period bar charts (oldest → newest) ───────────────────
// Signups per month
$users = [];
for ($i = 6; $i >= 0; $i--) {
    $start = strtotime(date('Y-m-01', strtotime("-$i months", $monthStart)));
    $end   = strtotime('+1 month', $start);
    $n = (int) $conn->query("SELECT COUNT(*) AS n FROM users WHERE role <> 'admin' AND created_at >= FROM_UNIXTIME($start) AND created_at < FROM_UNIXTIME($end)")->fetch_assoc()['n'];
    $users[] = ['label' => date('M', $start), 'value' => $n, 'text' => $n . ' signup' . ($n === 1 ? '' : 's')];
}
// New listings per week (weeks start on Monday)
$listings = [];
$weekStart = strtotime('monday this week');
for ($i = 6; $i >= 0; $i--) {
    $start = strtotime("-$i weeks", $weekStart);
    $end   = strtotime('+1 week', $start);
    $n = (int) $conn->query("SELECT COUNT(*) AS n FROM listings WHERE created_at >= FROM_UNIXTIME($start) AND created_at < FROM_UNIXTIME($end)")->fetch_assoc()['n'];
    $listings[] = ['label' => 'Week of ' . date('M j', $start), 'value' => $n, 'text' => $n . ' new listing' . ($n === 1 ? '' : 's')];
}
// Sales per day
$sales = [];
for ($i = 6; $i >= 0; $i--) {
    $start = strtotime("today -$i days");
    $end   = strtotime('+1 day', $start);
    $v = (float) $conn->query("SELECT " . sf_net_sales_sql("o.created_at >= FROM_UNIXTIME($start) AND o.created_at < FROM_UNIXTIME($end)") . " AS v")->fetch_assoc()['v'];
    $sales[] = ['label' => date('D, M j', $start), 'value' => $v, 'text' => sf_peso($v)];
}

$totals = $conn->query("SELECT
    (SELECT COUNT(*) FROM users WHERE role <> 'admin') AS users,
    (SELECT COUNT(*) FROM listings) AS listings,
    (SELECT COUNT(*) FROM listings WHERE status = 'flagged') AS flagged,
    " . sf_net_sales_sql() . " AS revenue,
    (SELECT COUNT(*) FROM order_items WHERE dispute_status = 'open') AS disputes")->fetch_assoc();
$thisMonth = end($users)['value'];
$lastMonth = $users[5]['value'];
$growth = $lastMonth > 0 ? (($thisMonth >= $lastMonth ? '+' : '') . round(($thisMonth - $lastMonth) / $lastMonth * 100) . '%') : ($thisMonth ? '+' . $thisMonth . ' new' : '0%');

$cards = [
    'users' => [
        'title' => 'User reports', 'cadence' => 'Monthly', 'desc' => 'Signups, accounts, sellers and orders placed by each user.',
        'bars' => $users, 'left' => ['Total users', number_format($totals['users'])], 'right' => ['Growth', $growth, 'good'],
        'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    ],
    'listings' => [
        'title' => 'Listing reports', 'cadence' => 'Weekly', 'desc' => 'Every listing with its category, status, orders, rating and reports.',
        'bars' => $listings, 'left' => ['Total listings', number_format($totals['listings'])], 'right' => ['Flagged', number_format($totals['flagged']), 'bad'],
        'icon' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    ],
    'transactions' => [
        'title' => 'Transaction reports', 'cadence' => 'Daily', 'desc' => 'Orders with totals, payment method, status and disputes.',
        'bars' => $sales, 'left' => ['Revenue', sf_peso($totals['revenue'])], 'right' => ['Disputes', number_format($totals['disputes']), 'warn'],
        'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
    ],
];

$typeIcons = array_map(fn($c) => $c['icon'], $cards) + ['summary' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>'];
$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reports — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=25" />
  <link rel="stylesheet" href="css/admin-shell.css?v=5" />
</head>
<body>

  <?php sf_admin_shell_start('reports', 'Search report exports…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Reports</h1>
          <p>Generate and export reports across the marketplace.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="reportFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <form method="post" action="admin_report_export.php" class="report-form">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
            <input type="hidden" name="type" value="summary" />
            <input type="hidden" name="period" value="30" class="period-input" />
            <button type="submit" class="export-button">Export Reports</button>
          </form>
        </div>
      </header>

      <div class="dashboard-content">
        <div class="filter-panel" id="reportFilters" hidden>
          <label for="periodFilter">Reports cover</label>
          <select id="periodFilter">
            <option value="7">Last 7 days</option>
            <option value="30" selected>Last 30 days</option>
            <option value="month"><?php echo date('F Y'); ?></option>
            <option value="all">All time</option>
          </select>
          <span class="filter-hint">Applies to Generate report and Export Reports (by sign-up, posting or order date).</span>
        </div>

        <section class="stats-grid">
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Reports generated</span><div class="stat-icon-wrapper"><?php echo $icon($typeIcons['summary']); ?></div></div>
            <div class="stat-value"><?php echo number_format($exportStats['total']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> +<?php echo $exportStats['month']; ?> this month</p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Exports this month</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M2 12h5l3-9 4 18 3-9h5"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($exportStats['month']); ?></div>
            <?php $diff = $exportStats['month'] - $exportStats['last']; ?>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo ($diff >= 0 ? '+' : '') . $diff; ?> from last month</p>
          </div>
          <div class="stat-card revenue-card">
            <div class="stat-card-top"><span class="stat-label">Last export</span><div class="stat-icon-wrapper"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>'); ?></div></div>
            <div class="stat-value"><?php echo $latest ? sf_time_ago($latest['ts']) : 'None yet'; ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo $latest ? $e($latest['name']) : 'Generate your first report below'; ?></p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Data freshness</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>'); ?></div></div>
            <div class="stat-value">Live</div>
            <p class="stat-trend attention">⊙ Updated <?php echo date('g:i A'); ?></p>
          </div>
        </section>

        <section class="report-cards">
          <?php foreach ($cards as $type => $c):
            $max = max(array_column($c['bars'], 'value')) ?: 1;
          ?>
          <article class="report-card">
            <div class="report-card-top">
              <span class="stat-icon-wrapper"><?php echo $icon($c['icon']); ?></span>
              <span class="category-chip"><?php echo $c['cadence']; ?></span>
            </div>
            <h2><?php echo $c['title']; ?></h2>
            <p><?php echo $c['desc']; ?></p>
            <div class="spark-bars" role="img" aria-label="<?php echo $e($c['title'] . ': ' . implode(', ', array_map(fn($b) => $b['label'] . ' ' . $b['text'], $c['bars']))); ?>">
              <?php foreach ($c['bars'] as $i => $b): ?>
              <span class="bar<?php echo $i === 6 ? ' now' : ($b['value'] > 0 ? ' some' : ''); ?>" style="height: <?php echo max(8, round($b['value'] / $max * 100)); ?>%" title="<?php echo $e($b['label'] . ': ' . $b['text']); ?>"></span>
              <?php endforeach; ?>
            </div>
            <div class="report-figures">
              <div><span><?php echo $c['left'][0]; ?></span><strong><?php echo $e($c['left'][1]); ?></strong></div>
              <div class="right"><span><?php echo $c['right'][0]; ?></span><strong class="<?php echo $c['right'][2]; ?>"><?php echo $e($c['right'][1]); ?></strong></div>
            </div>
            <form method="post" action="admin_report_export.php" class="report-form">
              <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
              <input type="hidden" name="type" value="<?php echo $type; ?>" />
              <input type="hidden" name="period" value="30" class="period-input" />
              <button type="submit" class="dark-button wide">Generate report</button>
            </form>
          </article>
          <?php endforeach; ?>
        </section>

        <section class="activity-card">
          <div class="section-heading"><h2><span class="heading-icon"><?php echo $icon($typeIcons['summary']); ?></span>Recent Report Exports</h2><span class="time-badge"><?php echo count($exports) > 10 ? 'Latest 10' : count($exports) . ' total'; ?></span></div>
          <div class="table-scroll">
            <table class="users-table plain-head">
              <thead><tr><th>Report name</th><th>Generated by</th><th>Date</th><th>Rows</th><th>Format</th><th>Status</th></tr></thead>
              <tbody id="exportBody">
                <?php foreach (array_slice($exports, 0, 10) as $x): ?>
                <tr data-search="<?php echo $e(mb_strtolower($x['name'] . ' ' . $x['admin_name'])); ?>">
                  <td><div class="listing-cell"><span class="report-icon"><?php echo $icon($typeIcons[$x['report_type']] ?? $typeIcons['summary']); ?></span><strong><?php echo $e($x['name']); ?></strong></div></td>
                  <td class="muted-cell"><?php echo $e($x['admin_name']); ?></td>
                  <td class="muted-cell"><?php echo date('M j, Y g:i A', $x['ts']); ?></td>
                  <td class="muted-cell"><?php echo number_format($x['row_count']); ?></td>
                  <td><span class="category-chip caps"><?php echo $e(strtoupper($x['format'])); ?></span></td>
                  <td><span class="status-chip caps active">Done</span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyExports"<?php echo $exports ? ' hidden' : ''; ?>><?php echo $exports ? 'No exports match your search.' : 'No reports exported yet. Use Generate report above; each download is listed here.'; ?></p>
        </section>
      </div>

  <?php sf_admin_shell_end(); ?>

  <script>
    // The period chosen in Filters goes with every report download
    const period = document.getElementById('periodFilter');
    period.addEventListener('change', () => {
      document.querySelectorAll('.period-input').forEach(i => { i.value = period.value; });
    });
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('reportFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // After a download starts, refresh so it shows up in Recent Report Exports
    document.querySelectorAll('.report-form').forEach(form => form.addEventListener('submit', () => {
      setTimeout(() => window.location.reload(), 1500);
    }));

    // Search (top bar) over the export log
    const rows = [...document.querySelectorAll('#exportBody tr')];
    window.onAdminSearch = q => {
      rows.forEach(r => { r.hidden = !r.dataset.search.includes(q); });
      if (rows.length) document.getElementById('emptyExports').hidden = rows.some(r => !r.hidden);
    };
  </script>
</body>
</html>
