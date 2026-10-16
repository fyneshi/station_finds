<?php
session_start();

// Admins only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

require_once 'db_setup.php';
require_once 'shop.php';
require_once 'inbox.php';
require_once 'admin_layout.php';
sf_admin_require($conn, 'activity');

$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$icon = fn($paths, $size = 18) => '<svg aria-hidden="true" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';

// Icon per kind of action (same drawings as the sidebar)
$menu      = sf_admin_menu();
$kindIcons = [
    'seller'   => $menu['seller-apps'][2],
    'user'     => $menu['users'][2],
    'listing'  => $menu['listings'][2],
    'report'   => $menu['moderation'][2],
    'feedback' => $menu['feedback'][2],
    'dispute'  => $menu['transactions'][2],
    'payout'   => $menu['payouts'][2],
    'category' => $menu['categories'][2],
    'export'   => $menu['reports'][2],
];
$pulse = '<path d="M2 12h5l3-9 4 18 3-9h5"/>';

// Badge colour: good news green, removals red, holds amber, the rest outlined
function badge_class($badge) {
    $badge = strtolower($badge);
    if (in_array($badge, ['approved', 'done', 'resolved', 'added', 'active', 'reopened'], true)) return 'good';
    if (in_array($badge, ['suspended', 'deleted', 'rejected', 'refunded'], true)) return 'bad';
    if (in_array($badge, ['flagged', 'escalated', 'inactive'], true)) return 'warn';
    return '';
}

// ── Log entries (last 90 days is plenty for the page; Export Logs takes what's shown) ──
$logs = $conn->query("SELECT *, UNIX_TIMESTAMP(created_at) AS ts FROM admin_logs
    WHERE created_at >= NOW() - INTERVAL 90 DAY ORDER BY created_at DESC, id DESC LIMIT 1000")->fetch_all(MYSQLI_ASSOC);

$today     = strtotime('today');
$yesterday = strtotime('yesterday');
$weekStart = strtotime('monday this week');
$lastWeek  = strtotime('-1 week', $weekStart);
$between   = fn($from, $to = PHP_INT_MAX) => array_filter($logs, fn($l) => $l['ts'] >= $from && $l['ts'] < $to);

$todayLogs   = $between($today);
$weekLogs    = $between($weekStart);
$lastWeekN   = count($between($lastWeek, $weekStart));
$critical    = array_filter($between(strtotime('-7 days')), fn($l) => $l['critical']);
$adminsToday = count(array_unique(array_map(fn($l) => $l['admin_id'] ?? $l['admin_name'], $todayLogs)));
$adminCount  = (int) $conn->query("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")->fetch_assoc()['n'];

$todayDiff = count($todayLogs) - count($between($yesterday, $today));
$weekTrend = $lastWeekN > 0
    ? (count($weekLogs) >= $lastWeekN ? '+' : '') . round((count($weekLogs) - $lastWeekN) / $lastWeekN * 100) . '% vs last week'
    : (count($weekLogs) ? 'First logs this week' : 'Nothing logged yet');

// Open on Today when something happened today, otherwise everything
$defaultRange = $todayLogs ? 'today' : 'all';
$ranges = ['today' => 'Today', 'week' => 'This week', '30' => 'Last 30 days', 'all' => 'Last 90 days'];

function log_when($ts) {
    if ($ts >= strtotime('today')) return date('g:i A', $ts);
    if ($ts >= strtotime('yesterday')) return 'Yesterday';
    return date('M j', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Activity / Audit Logs — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="CSS/admin.css?v=22" />
  <link rel="stylesheet" href="CSS/admin-shell.css?v=2" />
</head>
<body>

  <?php sf_admin_shell_start('activity', 'Search actions, admins, details…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Activity / Audit Logs</h1>
          <p>A record of admin actions across the platform.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="logFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="export-button" id="exportLogs">Export Logs</button>
        </div>
      </header>

      <div class="dashboard-content">
        <div class="filter-panel" id="logFilters" hidden>
          <label for="rangeFilter">Show</label>
          <select id="rangeFilter">
            <?php foreach ($ranges as $value => $label): ?>
            <option value="<?php echo $value; ?>"<?php echo $value === $defaultRange ? ' selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>
          <label for="kindFilter">Area</label>
          <select id="kindFilter">
            <option value="">All areas</option>
            <?php foreach (SF_LOG_KINDS as $kind => $label): ?>
            <option value="<?php echo $kind; ?>"><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>
          <label class="check-label"><input type="checkbox" id="criticalFilter" /> Critical only</label>
        </div>

        <section class="stats-grid">
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Actions today</span><div class="stat-icon-wrapper"><?php echo $icon($pulse); ?></div></div>
            <div class="stat-value"><?php echo count($todayLogs); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo ($todayDiff >= 0 ? '+' : '') . $todayDiff; ?> vs yesterday</p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Active admins</span><div class="stat-icon-wrapper"><?php echo $icon($kindIcons['user']); ?></div></div>
            <div class="stat-value"><?php echo $adminsToday; ?></div>
            <p class="stat-trend attention">⊙ <?php echo $adminsToday ? 'Of ' . $adminCount . ' admin' . ($adminCount === 1 ? '' : 's') . ', acted today' : 'No admin activity yet today'; ?></p>
          </div>
          <div class="stat-card revenue-card">
            <div class="stat-card-top"><span class="stat-label">Total logs this week</span><div class="stat-icon-wrapper"><?php echo $icon($pulse); ?></div></div>
            <div class="stat-value"><?php echo count($weekLogs); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo $weekTrend; ?></p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Critical actions</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'); ?></div></div>
            <div class="stat-value"><?php echo count($critical); ?></div>
            <p class="stat-trend attention">⊙ <?php echo $critical ? 'Needs review · last 7 days' : 'None in the last 7 days'; ?></p>
          </div>
        </section>

        <section class="activity-card">
          <div class="section-heading"><h2><span class="heading-icon"><?php echo $icon($pulse); ?></span>Audit Log Entries</h2><span class="time-badge" id="rangeBadge"><?php echo $ranges[$defaultRange]; ?></span></div>
          <div class="table-scroll">
            <table class="users-table plain-head log-table">
              <thead><tr><th>Action</th><th>Performed by</th><th>Time</th><th>Details</th></tr></thead>
              <tbody id="logBody">
                <?php foreach ($logs as $l): ?>
                <tr data-ts="<?php echo (int) $l['ts']; ?>" data-kind="<?php echo $e($l['kind']); ?>" data-critical="<?php echo (int) $l['critical']; ?>"
                    data-search="<?php echo $e(mb_strtolower($l['action'] . ' ' . $l['admin_name'] . ' ' . $l['target'] . ' ' . $l['badge'])); ?>"
                    data-when="<?php echo date('Y-m-d H:i:s', $l['ts']); ?>">
                  <td><div class="listing-cell"><span class="report-icon<?php echo $l['critical'] ? ' escalated' : ''; ?>"><?php echo $icon($kindIcons[$l['kind']] ?? $pulse, 16); ?></span><strong><?php echo $e($l['action']); ?></strong><?php if ($l['critical']): ?><span class="critical-tag" title="Critical action">Critical</span><?php endif; ?></div></td>
                  <td class="muted-cell"><?php echo $e($l['admin_name']); ?></td>
                  <td class="muted-cell" title="<?php echo date('M j, Y g:i A', $l['ts']); ?>"><?php echo log_when($l['ts']); ?></td>
                  <td><div class="log-detail"><span><?php echo $e($l['target']); ?></span><?php if ($l['badge'] !== ''): ?><span class="log-badge <?php echo badge_class($l['badge']); ?>"><?php echo $e($l['badge']); ?></span><?php endif; ?></div></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyLogs"<?php echo $logs ? ' hidden' : ''; ?>><?php echo $logs ? 'No actions match these filters.' : 'No admin actions logged yet. Approvals, suspensions, payouts and other admin actions will appear here.'; ?></p>
        </section>
      </div>

  <?php sf_admin_shell_end(); ?>

  <script>
    const rows   = [...document.querySelectorAll('#logBody tr')];
    const range  = document.getElementById('rangeFilter');
    const kind   = document.getElementById('kindFilter');
    const crit   = document.getElementById('criticalFilter');
    const badge  = document.getElementById('rangeBadge');
    let query    = '';

    // Start of each range, in the visitor's clock (matches the times shown)
    function rangeStart(value) {
      const now = new Date();
      const midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      if (value === 'today') return midnight.getTime() / 1000;
      if (value === 'week')  { const d = new Date(midnight); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return d.getTime() / 1000; }
      if (value === '30')    return now.getTime() / 1000 - 30 * 86400;
      return 0;
    }

    function applyFilters() {
      const from = rangeStart(range.value);
      rows.forEach(r => {
        r.hidden = Number(r.dataset.ts) < from
          || (kind.value && r.dataset.kind !== kind.value)
          || (crit.checked && r.dataset.critical !== '1')
          || (query && !r.dataset.search.includes(query));
      });
      badge.textContent = range.options[range.selectedIndex].text + (kind.value ? ' · ' + kind.options[kind.selectedIndex].text : '') + (crit.checked ? ' · Critical' : '');
      if (rows.length) document.getElementById('emptyLogs').hidden = rows.some(r => !r.hidden);
    }
    [range, kind, crit].forEach(el => el.addEventListener('change', applyFilters));
    window.onAdminSearch = q => { query = q; applyFilters(); };
    applyFilters();

    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('logFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // Export the visible entries as a CSV file (cells that look like formulas are neutralised)
    document.getElementById('exportLogs').addEventListener('click', () => {
      const lines = [['Date', 'Action', 'Performed by', 'Details', 'Status', 'Critical']];
      rows.filter(r => !r.hidden).forEach(r => {
        const c = r.cells;
        const b = c[3].querySelector('.log-badge');
        lines.push([r.dataset.when, c[0].querySelector('strong').textContent, c[1].textContent,
                    c[3].querySelector('span').textContent, b ? b.textContent : '', r.dataset.critical === '1' ? 'Yes' : 'No']);
      });
      const safe = v => { v = String(v).trim(); return /^[=+\-@\t\r]/.test(v) ? "'" + v : v; };
      const csv = lines.map(row => row.map(v => '"' + safe(v).replaceAll('"', '""') + '"').join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
      const link = document.createElement('a');
      link.href = url; link.download = 'audit-logs.csv'; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  </script>
</body>
</html>
