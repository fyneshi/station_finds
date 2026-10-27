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
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/admin_layout.php';
sf_admin_require($conn, 'moderation');

$tabs = ['all' => 'All', 'listing' => 'Listings', 'user' => 'Users', 'comment' => 'Comments'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';

// Report status => [label, chip style]
$statuses = [
    'open'      => ['Open', 'pending'],
    'escalated' => ['Escalated', 'flagged'],
    'resolved'  => ['Resolved', 'active'],
    'dismissed' => ['Dismissed', 'draft'],
];
$outcomes = [
    'listing_flagged' => 'Listing flagged',
    'user_suspended'  => 'User suspended',
    'comment_removed' => 'Comment removed',
    'dismissed'       => 'No action needed',
    'resolved'        => 'Resolved by an admin',
];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function back($tab, $type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin_moderation.php?tab=' . $tab);
    exit;
}

// Close a report and tell the person who filed it
function close_report($conn, array $report, $status, $outcome, $note) {
    $stmt = $conn->prepare("UPDATE reports SET status = ?, outcome = ?, admin_note = ?, resolved_at = NOW() WHERE id = ?");
    $stmt->bind_param("sssi", $status, $outcome, $note, $report['id']);
    $stmt->execute();
    $stmt->close();
    $about = ['user' => 'the user ' . $report['target_name'], 'comment' => 'a comment'][$report['target_type']] ?? '"' . $report['target_name'] . '"';
    sf_notify($conn, (int) $report['reporter_id'], 'info', 'Your report was reviewed',
        $status === 'resolved'
            ? 'Thanks for reporting ' . $about . '. Our team reviewed it and took action.'
            : 'Thanks for reporting ' . $about . '. Our team reviewed it and didn\'t find a problem this time.');
}

// ── Escalate / dismiss / take action ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } else {
        $stmt = $conn->prepare("SELECT * FROM reports WHERE id = ?");
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $report = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$report) back($tab, 'err', 'That report could not be found.');
        if (!in_array($report['status'], ['open', 'escalated'], true)) back($tab, 'err', 'That report is already closed.');

        $note = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['note'] ?? ''))), 0, 255);
        $name = ['user' => $report['target_name'], 'comment' => 'the comment'][$report['target_type']] ?? '"' . $report['target_name'] . '"';

        if ($action === 'escalate') {
            if ($report['status'] === 'escalated') back($tab, 'err', 'That report is already escalated.');
            $stmt = $conn->prepare("UPDATE reports SET status = 'escalated', admin_note = ? WHERE id = ?");
            $stmt->bind_param("si", $note, $id);
            $stmt->execute();
            $stmt->close();
            sf_admin_log($conn, 'report', 'Escalated report', $report['target_name'], 'escalated');
            back($tab, 'ok', 'The report about ' . $name . ' was escalated.');

        } elseif ($action === 'resolve') {
            // Handled without hiding anything (e.g. the seller fixed it after a message)
            close_report($conn, $report, 'resolved', 'resolved', $note);
            sf_admin_log($conn, 'report', 'Resolved report', $report['target_name'], 'resolved');
            back($tab, 'ok', 'The report about ' . $name . ' was marked as resolved. The reporter has been told.');

        } elseif ($action === 'dismiss') {
            close_report($conn, $report, 'dismissed', 'dismissed', $note);
            sf_admin_log($conn, 'report', 'Dismissed report', $report['target_name'], 'dismissed');
            back($tab, 'ok', 'The report about ' . $name . ' was dismissed. The reporter has been told.');

        } elseif ($action === 'take_action') {
            if ($report['target_type'] === 'listing') {
                if ($note === '') back($tab, 'err', 'Add a note: it\'s sent to the seller so they know what to fix.');
                $stmt = $conn->prepare("SELECT * FROM listings WHERE id = ?");
                $stmt->bind_param("i", $report['target_id']);
                $stmt->execute();
                $listing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$listing) back($tab, 'err', 'That listing no longer exists. Dismiss the report instead.');
                sf_flag_listing($conn, $listing, $note);
                $outcome = 'listing_flagged';
                $done    = $name . ' was flagged and hidden from the marketplace.';
            } elseif ($report['target_type'] === 'comment') {
                // Removes the comment (a question takes its replies with it) and tells the person who wrote it
                $stmt = $conn->prepare("SELECT c.*, l.title FROM listing_comments c JOIN listings l ON l.id = c.listing_id WHERE c.id = ?");
                $stmt->bind_param("i", $report['target_id']);
                $stmt->execute();
                $comment = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$comment) back($tab, 'err', 'That comment was already removed. Dismiss the report instead.');
                $stmt = $conn->prepare("DELETE FROM listing_comments WHERE id = ? OR parent_id = ?");
                $stmt->bind_param("ii", $comment['id'], $comment['id']);
                $stmt->execute();
                $stmt->close();
                sf_notify($conn, (int) $comment['user_id'], 'info', 'Your comment was removed',
                    'An admin removed your comment on "' . $comment['title'] . '" for breaking the community rules'
                    . ($note !== '' ? ': ' . $note : '.'), 'policies.php');
                $outcome = 'comment_removed';
                $done    = 'The comment was removed and its author was told.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role <> 'admin'");
                $stmt->bind_param("i", $report['target_id']);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                $stmt = $conn->prepare("SELECT status FROM users WHERE id = ?");
                $stmt->bind_param("i", $report['target_id']);
                $stmt->execute();
                $target = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$target) back($tab, 'err', 'That user no longer exists. Dismiss the report instead.');
                $outcome = 'user_suspended';
                $done    = $name . ($changed ? ' was suspended.' : ' was already suspended.');
            }
            // Every open report about the same listing or user is settled by this action
            $stmt = $conn->prepare("SELECT * FROM reports WHERE target_type = ? AND target_id = ? AND status IN ('open', 'escalated')");
            $stmt->bind_param("si", $report['target_type'], $report['target_id']);
            $stmt->execute();
            $related = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($related as $r) close_report($conn, $r, 'resolved', $outcome, $note);
            sf_admin_log($conn, 'report', ['user_suspended' => 'Resolved report: suspended user', 'comment_removed' => 'Resolved report: removed comment'][$outcome] ?? 'Resolved report: flagged listing',
                $report['target_name'], 'resolved', $outcome === 'user_suspended');
            back($tab, 'ok', $done . ' ' . count($related) . ' report' . (count($related) === 1 ? '' : 's') . ' resolved.');
        }
    }
}

// ── Reports, with the current state of what was reported ────────
$reports = $conn->query("SELECT r.*, UNIX_TIMESTAMP(r.created_at) AS created_ts, UNIX_TIMESTAMP(r.resolved_at) AS resolved_ts,
           rep.username AS reporter,
           l.status AS listing_status, l.price AS listing_price, l.unit AS listing_unit, l.description AS listing_desc,
           COALESCE(sp.store_name, ls.username) AS listing_seller,
           t.status AS user_status, t.email AS user_email,
           cm.body AS comment_body, cl.id AS comment_listing_id, cl.type AS comment_listing_type, cl.title AS comment_listing,
           (SELECT COUNT(*) FROM reports o WHERE o.target_type = r.target_type AND o.target_id = r.target_id) AS times_reported
    FROM reports r
    JOIN users rep ON rep.id = r.reporter_id
    LEFT JOIN listings l ON r.target_type = 'listing' AND l.id = r.target_id
    LEFT JOIN users ls ON ls.id = l.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = l.seller_id
    LEFT JOIN users t ON r.target_type = 'user' AND t.id = r.target_id
    LEFT JOIN listing_comments cm ON r.target_type = 'comment' AND cm.id = r.target_id
    LEFT JOIN listings cl ON cl.id = cm.listing_id
    ORDER BY FIELD(r.status, 'escalated', 'open', 'resolved', 'dismissed'), r.created_at DESC")->fetch_all(MYSQLI_ASSOC);

$weekStart     = strtotime('monday this week');
$lastWeekStart = strtotime('monday last week');
$closedIn = fn($from, $to = PHP_INT_MAX) => count(array_filter($reports,
    fn($r) => in_array($r['status'], ['resolved', 'dismissed'], true) && $r['resolved_ts'] >= $from && $r['resolved_ts'] < $to));
$stats = [
    'open'      => count(array_filter($reports, fn($r) => $r['status'] === 'open')),
    'escalated' => count(array_filter($reports, fn($r) => $r['status'] === 'escalated')),
    'week'      => $closedIn($weekStart),
    'last_week' => $closedIn($lastWeekStart, $weekStart),
];
$weekDiff = $stats['week'] - $stats['last_week'];

$tabCounts = ['all' => count($reports)];
foreach (['listing', 'user', 'comment'] as $t) $tabCounts[$t] = count(array_filter($reports, fn($r) => $r['target_type'] === $t));
$shown = $tab === 'all' ? $reports : array_filter($reports, fn($r) => $r['target_type'] === $tab);

$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$rowIcons = [
    'listing'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'user'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'escalated' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'comment'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'closed'    => '<rect x="3" y="3" width="18" height="18" rx="3"/><polyline points="8 12 11 15 16 9"/>',
];
$listingStates = ['active' => 'Live in the marketplace', 'draft' => 'Draft (hidden)', 'flagged' => 'Flagged (hidden)', 'pending' => 'Waiting for review (hidden)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Content Moderation — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=25" />
  <link rel="stylesheet" href="css/admin-shell.css?v=6" />
</head>
<body>

  <?php sf_admin_shell_start('moderation', 'Search reports, users, listings…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Content Moderation</h1>
          <p>Review reported listings, users and comments.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="reportFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="export-button" id="exportReport">Export Report</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <div class="filter-panel" id="reportFilters" hidden>
          <label for="statusFilter">Status</label>
          <select id="statusFilter">
            <option value="all">All statuses</option>
            <?php foreach ($statuses as $key => [$label]): ?><option value="<?php echo $key; ?>"><?php echo $label; ?></option><?php endforeach; ?>
          </select>
        </div>

        <section class="stats-grid three">
          <button type="button" class="stat-card stat-filter" data-status="open">
            <div class="stat-card-top"><span class="stat-label">Open reports</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['open']); ?></div>
            <p class="stat-trend<?php echo $stats['open'] ? ' attention' : ''; ?>"><?php echo $stats['open'] ? '⊙ Needs attention' : 'Nothing waiting'; ?></p>
          </button>
          <button type="button" class="stat-card stat-filter" data-status="escalated">
            <div class="stat-card-top"><span class="stat-label">Escalated</span><div class="stat-icon-wrapper flagged-icon"><?php echo $icon($rowIcons['escalated']); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['escalated']); ?></div>
            <p class="stat-trend<?php echo $stats['escalated'] ? ' danger' : ''; ?>"><?php echo $stats['escalated'] ? '⊙ Requires action' : 'None escalated'; ?></p>
          </button>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Resolved this week</span><div class="stat-icon-wrapper done-icon"><?php echo $icon($rowIcons['closed']); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['week']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo ($weekDiff >= 0 ? '+' : '') . $weekDiff; ?> vs last week</p>
          </div>
        </section>

        <nav class="users-tabs" aria-label="Filter reports">
          <?php foreach ($tabs as $key => $label): ?>
          <a href="admin_moderation.php?tab=<?php echo $key; ?>" class="users-tab<?php echo $tab === $key ? ' active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo $label; ?> <span><?php echo $tabCounts[$key]; ?></span></a>
          <?php endforeach; ?>
        </nav>

        <section class="users-card">
          <div class="table-scroll">
            <table class="users-table">
              <thead><tr><th>Reported item</th><th>Reason</th><th>Reported by</th><th>Date</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="reportsBody">
                <?php foreach ($shown as $r):
                  [$statusLabel, $chip] = $statuses[$r['status']] ?? [ucfirst($r['status']), 'draft'];
                  $closed   = in_array($r['status'], ['resolved', 'dismissed'], true);
                  $iconKey  = $closed ? 'closed' : ($r['status'] === 'escalated' ? 'escalated' : $r['target_type']);
                  $label    = $r['target_name'] . ($r['target_type'] === 'user' ? ' (user)' : '');
                  if ($r['target_type'] === 'listing') {
                      $now = $r['listing_status'] === null ? 'Listing deleted'
                           : ($listingStates[$r['listing_status']] ?? ucfirst($r['listing_status'])) . ' · by ' . $r['listing_seller'];
                  } elseif ($r['target_type'] === 'comment') {
                      $now = $r['comment_body'] === null ? 'Comment removed' : 'On "' . $r['comment_listing'] . '"';
                  } else {
                      $now = $r['user_status'] === null ? 'Account deleted' : ($r['user_status'] === 'suspended' ? 'Suspended' : 'Active') . ' · ' . $r['user_email'];
                  }
                  $info = [
                      'no' => 'RPT-' . str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT),
                      'desc' => (string) ($r['target_type'] === 'comment' ? ($r['comment_body'] ?? '') : ($r['listing_desc'] ?? '')),
                      'where' => $r['comment_listing'] ?? '',
                      'link' => $r['comment_listing_id'] ? ['products' => 'product', 'rentals' => 'rental', 'services' => 'service'][$r['comment_listing_type']] . '_detail.php?id=listing-' . $r['comment_listing_id'] . '#comment-' . $r['target_id'] : '',
                      'price' => $r['listing_price'] !== null ? '₱' . number_format((float) $r['listing_price'], 2) . ($r['listing_unit'] && $r['listing_unit'] !== 'item' ? ' / ' . $r['listing_unit'] : '') : '',
                      'id' => (int) $r['id'], 'type' => $r['target_type'], 'name' => $r['target_name'], 'reason' => $r['reason'],
                      'details' => $r['details'], 'reporter' => $r['reporter'], 'date' => date('M j, Y g:i A', $r['created_ts']),
                      'status' => $r['status'], 'statusLabel' => $statusLabel, 'now' => $now, 'times' => (int) $r['times_reported'],
                      'note' => $r['admin_note'], 'outcome' => $outcomes[$r['outcome']] ?? '',
                      'resolved' => $r['resolved_ts'] ? date('M j, Y', $r['resolved_ts']) : '',
                      'gone' => ['listing' => $r['listing_status'] === null, 'comment' => $r['comment_body'] === null][$r['target_type']] ?? $r['user_status'] === null,
                  ];
                ?>
                <tr data-search="<?php echo $e(mb_strtolower($r['target_name'] . ' ' . $r['reason'] . ' ' . $r['reporter'])); ?>" data-status="<?php echo $e($r['status']); ?>">
                  <td>
                    <div class="listing-cell">
                      <span class="report-icon <?php echo $iconKey; ?>"><?php echo $icon($rowIcons[$iconKey]); ?></span>
                      <strong><?php echo $e($label); ?></strong>
                    </div>
                  </td>
                  <td><?php echo $e($r['reason']); ?></td>
                  <td class="muted-cell"><?php echo $e($r['reporter']); ?></td>
                  <td class="muted-cell"><?php echo date('M j, Y', $r['created_ts']); ?></td>
                  <td><span class="status-chip caps <?php echo $chip; ?>"><?php echo $statusLabel; ?></span></td>
                  <td class="col-action"><button type="button" class="manage-btn<?php echo $closed ? ' quiet' : ''; ?>" onclick='openReview(<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Review</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyReports"<?php echo $shown ? ' hidden' : ''; ?>><?php echo $shown ? 'No reports match your search.' : 'No reports yet. Students can report listings and comments from a listing page and users from Messages.'; ?></p>
        </section>
      </div>

  <!-- ==================== REVIEW REPORT MODAL ==================== -->
  <dialog class="adm-modal wide review-modal" id="reviewModal" aria-labelledby="rHeading">
    <div class="review-head">
      <h2 id="rHeading">Review Report</h2>
      <span class="review-no" id="rNo"></span>
      <button type="button" class="adm-modal-close" onclick="closeReview()" aria-label="Close">&times;</button>
    </div>
    <dl class="review-facts">
      <div><dt>Reported item</dt><dd id="rTitle"></dd></div>
      <div><dt>Reason</dt><dd id="rReason"></dd></div>
      <div><dt>Reported by</dt><dd id="rReporter"></dd></div>
      <div><dt>Date</dt><dd id="rDate"></dd></div>
      <div><dt>Status</dt><dd><span class="review-status" id="rStatus"></span></dd></div>
    </dl>

    <div class="review-preview" id="rPreview">
      <p class="review-preview-label"><span id="rPreviewLabel">Listing description preview</span> <span id="rPrice"></span></p>
      <p id="rDesc"></p>
      <p class="review-preview-where" id="rWhere" hidden>On <a id="rWhereLink" target="_blank" rel="noopener"></a></p>
    </div>
    <div class="review-preview" id="rDetailsBox">
      <p class="review-preview-label">What the reporter said</p>
      <p id="rDetails"></p>
    </div>
    <div class="review-preview log" id="rNoteBox">
      <p class="review-preview-label">Action log</p>
      <p id="rNoteShown"></p>
    </div>

    <form method="post" id="rForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="id" id="rId" />
      <div class="adm-field">
        <label for="rNote" id="rNoteLabel">Admin notes / action log</label>
        <textarea id="rNote" name="note" maxlength="255" rows="3" placeholder="Write internal notes about your action here…"></textarea>
        <span class="adm-field-hint" id="rNoteHint"></span>
      </div>
      <div class="review-actions">
        <button type="submit" name="action" value="resolve" class="export-button" id="rResolve">Mark as resolved</button>
        <button type="submit" name="action" value="escalate" class="filter-button" id="rEscalate">Escalate</button>
        <button type="submit" name="action" value="take_action" class="filter-button" id="rAct"></button>
        <button type="submit" name="action" value="dismiss" class="filter-button" id="rDismiss">Dismiss</button>
      </div>
    </form>
  </dialog>

  <?php sf_admin_shell_end(); ?>

  <script>
    const modal = document.getElementById('reviewModal');
    function closeReview() { modal.close(); }
    modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });

    // Fill the modal with this report and the actions that fit it
    function openReview(r) {
      const set = (id, text) => { document.getElementById(id).textContent = text; };
      const show = (id, on) => { document.getElementById(id).hidden = !on; };
      const closed = r.status === 'resolved' || r.status === 'dismissed';
      const listing = r.type === 'listing';
      const comment = r.type === 'comment';
      set('rNo', '#' + r.no);
      set('rTitle', r.name + (r.type === 'user' ? ' (user)' : ''));
      set('rReason', r.reason);
      set('rReporter', r.reporter);
      set('rDate', r.date);
      const status = document.getElementById('rStatus');
      status.textContent = r.statusLabel;
      status.className = 'review-status ' + r.status;

      // What was reported, what the reporter said, and any earlier note
      set('rPreviewLabel', comment ? 'The reported comment' : 'Listing description preview');
      set('rDesc', r.desc ? '“' + r.desc + '”' : 'This listing has no description.');
      set('rPrice', r.price ? '· ' + r.price : '');
      show('rPreview', (listing || comment) && !r.gone);
      set('rWhereLink', r.where);
      document.getElementById('rWhereLink').href = r.link;
      show('rWhere', comment && !r.gone);
      set('rDetails', r.details);
      show('rDetailsBox', !!r.details);
      set('rNoteShown', r.note);
      show('rNoteBox', !!r.note);
      document.getElementById('rId').value = r.id;

      const note = document.getElementById('rNote');
      note.value = '';
      note.classList.remove('has-error');
      set('rNoteHint', listing ? 'Required to flag the item: the seller sees it so they know what to fix. The reporter is told the outcome, not your note.'
                     : comment ? 'Optional: if you remove the comment, its author sees this as the reason. The reporter is told the outcome, not your note.'
                               : 'Kept for the admin team. The reporter is told the outcome, not your note.');
      document.getElementById('rAct').textContent = listing ? 'Flag Item' : comment ? 'Remove comment' : 'Suspend user';
      show('rAct', !r.gone);
      show('rEscalate', r.status === 'open');
      show('rForm', !closed);
      modal.showModal();
    }

    document.getElementById('rForm').addEventListener('submit', e => {
      const action = e.submitter ? e.submitter.value : '';
      const note = document.getElementById('rNote');
      const listing = document.getElementById('rAct').textContent === 'Flag Item';
      if (action === 'take_action' && listing && !note.value.trim()) {
        e.preventDefault();
        note.classList.add('has-error');
        note.focus();
      } else if (action === 'take_action' && document.getElementById('rAct').textContent === 'Remove comment') {
        if (!confirm('Remove this comment? If it\'s a question, its replies are removed too.')) e.preventDefault();
      } else if (action === 'take_action' && !listing && !confirm('Suspend this user? They are logged out and their listings are hidden.')) {
        e.preventDefault();
      }
    });

    // Search (top bar), status filter (Filters), and the Open / Escalated cards
    const rows = [...document.querySelectorAll('#reportsBody tr')];
    const statusFilter = document.getElementById('statusFilter');
    let query = '';
    function applyFilters() {
      rows.forEach(r => {
        r.hidden = !r.dataset.search.includes(query) || (statusFilter.value !== 'all' && r.dataset.status !== statusFilter.value);
      });
      if (rows.length) document.getElementById('emptyReports').hidden = rows.some(r => !r.hidden);
      document.querySelectorAll('.stat-filter').forEach(c => c.classList.toggle('selected', c.dataset.status === statusFilter.value));
    }
    window.onAdminSearch = q => { query = q; applyFilters(); };
    statusFilter.addEventListener('change', applyFilters);
    document.querySelectorAll('.stat-filter').forEach(card => card.addEventListener('click', () => {
      statusFilter.value = statusFilter.value === card.dataset.status ? 'all' : card.dataset.status;
      applyFilters();
    }));
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('reportFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // Export the visible reports as a CSV file
    document.getElementById('exportReport').addEventListener('click', () => {
      const lines = [['Reported item', 'Reason', 'Reported by', 'Date', 'Status']];
      rows.filter(r => !r.hidden).forEach(r => lines.push([...r.cells].slice(0, 5).map(c => c.textContent.trim())));
      const csv = lines.map(row => row.map(v => '"' + String(v).replaceAll('"', '""') + '"').join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
      const link = document.createElement('a');
      link.href = url; link.download = 'content-moderation.csv'; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  </script>
</body>
</html>
