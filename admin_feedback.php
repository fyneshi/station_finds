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
require_once __DIR__ . '/includes/seller.php';
require_once __DIR__ . '/includes/feedback.php';
require_once __DIR__ . '/includes/admin_layout.php';
sf_admin_require($conn, 'feedback');

$tabs = ['all' => 'All', 'buyer' => 'Buyer feedback', 'seller' => 'Seller feedback', 'platform' => 'Platform', 'flagged' => 'Flagged'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';

// Feedback status => [label, chip style]
$statuses = [
    'pending'   => ['Pending', 'pending'],
    'escalated' => ['Escalated', 'flagged'],
    'resolved'  => ['Resolved', 'active'],
    'flagged'   => ['Flagged', 'flagged'],
    'dismissed' => ['Dismissed', 'draft'],
];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function back($tab, $id, $type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin_feedback.php?tab=' . $tab . '&id=' . (int) $id);
    exit;
}

// ── Resolve / escalate / flag / dismiss / reopen ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);
    $next   = ['resolve' => 'resolved', 'escalate' => 'escalated', 'flag' => 'flagged', 'dismiss' => 'dismissed', 'reopen' => 'pending'][$action] ?? null;
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } elseif ($next) {
        $stmt = $conn->prepare("SELECT * FROM feedback WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) back($tab, 0, 'err', 'That feedback could not be found.');
        if ($item['status'] === $next) back($tab, $id, 'err', 'It\'s already ' . strtolower($statuses[$next][0]) . '.');

        $closing = in_array($next, ['resolved', 'flagged', 'dismissed'], true);
        $stmt = $conn->prepare("UPDATE feedback SET status = ?, resolved_at = " . ($closing ? "NOW()" : "NULL") . " WHERE id = ?");
        $stmt->bind_param("si", $next, $id);
        $stmt->execute();
        $stmt->close();
        sf_admin_log($conn, 'feedback', ['resolved' => 'Resolved feedback', 'escalated' => 'Escalated feedback', 'flagged' => 'Flagged feedback',
            'dismissed' => 'Dismissed feedback', 'pending' => 'Reopened feedback'][$next],
            sf_feedback_no($id) . (($item['subject'] ?? '') !== '' ? ' · ' . $item['subject'] : ''), $next === 'pending' ? 'reopened' : $next);

        // Let people who wrote to the platform know it was handled
        if ($next === 'resolved' && $item['type'] === 'platform' && $item['user_id']) {
            sf_notify($conn, (int) $item['user_id'], 'info', 'We looked into your feedback',
                'Thanks for telling us about "' . $item['subject'] . '". Our team has reviewed it.', 'contact.php');
        }
        $done = [
            'resolved'  => 'marked as resolved',
            'escalated' => 'escalated',
            'flagged'   => 'flagged' . ($item['type'] === 'buyer' ? ' and its rating is hidden from the shop' : ''),
            'dismissed' => 'dismissed' . ($item['type'] === 'buyer' ? ' and its rating is hidden from the shop' : ''),
            'pending'   => 'reopened',
        ][$next];
        back($tab, $id, 'ok', sf_feedback_no($id) . ' was ' . $done . '.');
    }
}

// ── All feedback, with who it's about ───────────────────────────
$all = $conn->query("SELECT f.*, UNIX_TIMESTAMP(f.created_at) AS created_ts,
           u.username, l.title AS listing_title, oi.title AS item_title, o.order_no,
           a.username AS about_username, a.full_name AS about_full_name, a.email AS about_email
    FROM feedback f
    LEFT JOIN users u ON u.id = f.user_id
    LEFT JOIN listings l ON f.listing_key = CONCAT('listing-', l.id)
    LEFT JOIN order_items oi ON oi.id = f.order_item_id
    LEFT JOIN orders o ON o.id = oi.order_id
    LEFT JOIN users a ON a.id = f.about_user_id
    ORDER BY FIELD(f.status, 'escalated', 'pending', 'flagged', 'resolved', 'dismissed'), f.created_at DESC, f.id DESC")->fetch_all(MYSQLI_ASSOC);

$monthStart = strtotime(date('Y-m-01'));
$weekStart  = strtotime('monday this week');
$rated      = array_filter($all, fn($f) => $f['rating'] !== null && !in_array($f['status'], SF_FEEDBACK_HIDDEN, true));
$positive   = array_filter($rated, fn($f) => $f['rating'] >= 4);
$stats = [
    'total'          => count($all),
    'month'          => count(array_filter($all, fn($f) => $f['created_ts'] >= $monthStart)),
    'positive'       => count($positive),
    'positive_week'  => count(array_filter($positive, fn($f) => $f['created_ts'] >= $weekStart)),
    'avg'            => $rated ? round(array_sum(array_column($rated, 'rating')) / count($rated), 1) : 0,
    'rated'          => count($rated),
    'flagged'        => count(array_filter($all, fn($f) => $f['status'] === 'flagged')),
    'open'           => count(array_filter($all, fn($f) => in_array($f['status'], ['pending', 'escalated'], true))),
];

$tabCounts = ['all' => count($all), 'flagged' => $stats['flagged']];
foreach (['buyer', 'seller', 'platform'] as $t) $tabCounts[$t] = count(array_filter($all, fn($f) => $f['type'] === $t));
$shown = array_values(array_filter($all, fn($f) => $tab === 'all' || ($tab === 'flagged' ? $f['status'] === 'flagged' : $f['type'] === $tab)));

// ?id= (from a link or after an action) highlights that feedback, and opens it unless a message is showing
$wanted   = (int) ($_GET['id'] ?? 0);
$selected = null;
foreach ($all as $f) if ((int) $f['id'] === $wanted) $selected = $f;

// Average rating each user has received, for the profile card in the review modal
$received = [];
foreach ($rated as $f) if ($f['about_user_id']) $received[$f['about_user_id']][] = (int) $f['rating'];

$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$initials = fn($name) => strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($name)), 0, 2))) ?: '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Feedback — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=23" />
  <link rel="stylesheet" href="css/admin-shell.css?v=4" />
</head>
<body>

  <?php sf_admin_shell_start('feedback', 'Search feedback, users, listings…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Feedback</h1>
          <p>Review and manage feedback submitted by users on the platform.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="feedbackFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="filter-button outline-strong" id="exportReport">Export Report</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <div class="filter-panel" id="feedbackFilters" hidden>
          <label for="statusFilter">Status</label>
          <select id="statusFilter">
            <option value="all">All statuses</option>
            <?php foreach ($statuses as $key => [$label]): ?><option value="<?php echo $key; ?>"><?php echo $label; ?></option><?php endforeach; ?>
          </select>
          <label for="ratingFilter">Rating</label>
          <select id="ratingFilter">
            <option value="all">Any rating</option>
            <?php for ($s = 5; $s >= 1; $s--): ?><option value="<?php echo $s; ?>"><?php echo str_repeat('★', $s); ?></option><?php endfor; ?>
            <option value="none">No rating</option>
          </select>
        </div>

        <section class="stats-grid">
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Total feedback</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> +<?php echo $stats['month']; ?> this month</p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Positive reviews</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['positive']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> +<?php echo $stats['positive_week']; ?> this week · 4★ and up</p>
          </div>
          <div class="stat-card revenue-card">
            <div class="stat-card-top"><span class="stat-label">Average rating</span><div class="stat-icon-wrapper"><?php echo $icon('<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'); ?></div></div>
            <div class="stat-value"><?php echo $stats['rated'] ? number_format($stats['avg'], 1) . ' ★' : '—'; ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> from <?php echo $stats['rated']; ?> rating<?php echo $stats['rated'] === 1 ? '' : 's'; ?></p>
          </div>
          <a href="admin_feedback.php?tab=flagged" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Flagged</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['flagged']); ?></div>
            <p class="stat-trend<?php echo $stats['open'] ? ' attention' : ''; ?>"><?php echo $stats['open'] ? '⊙ ' . $stats['open'] . ' waiting for review' : 'Nothing waiting'; ?></p>
          </a>
        </section>

        <nav class="users-tabs" aria-label="Filter feedback">
          <?php foreach ($tabs as $key => $label): ?>
          <a href="admin_feedback.php?tab=<?php echo $key; ?>" class="users-tab<?php echo $tab === $key ? ' active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo $label; ?> <span><?php echo $tabCounts[$key]; ?></span></a>
          <?php endforeach; ?>
        </nav>

        <section class="activity-card feedback-table-card">
          <div class="section-heading"><h2><span class="heading-icon"><?php echo $icon('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'); ?></span>Feedback Table</h2><span class="time-badge" id="entryCount"><?php echo count($shown); ?> entr<?php echo count($shown) === 1 ? 'y' : 'ies'; ?></span></div>
          <div class="table-scroll">
            <table class="users-table plain-head">
              <thead><tr><th>Feedback ID</th><th>Type</th><th>Subject</th><th>Submitted by</th><th>Rating</th><th>Date</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="feedbackBody">
                <?php foreach ($shown as $f):
                  [$statusLabel, $chip] = $statuses[$f['status']] ?? [ucfirst($f['status']), 'draft'];
                  $isSelected = $selected && (int) $selected['id'] === (int) $f['id'];
                  $open = in_array($f['status'], ['pending', 'escalated'], true);
                  // Profile card: who the feedback is about (the seller for a buyer review, the buyer
                  // for seller feedback); for messages to the platform, the person who wrote in
                  $aboutId  = $f['type'] !== 'platform' && $f['about_user_id'] ? (int) $f['about_user_id'] : null;
                  $ratings  = $aboutId ? ($received[$aboutId] ?? []) : [];
                  $profName = $aboutId ? ($f['about_full_name'] ?: $f['about_username']) : $f['name'];
                  $info = [
                      'id' => (int) $f['id'], 'no' => sf_feedback_no($f['id']), 'type' => SF_FEEDBACK_TYPES[$f['type']] ?? $f['type'],
                      'subject' => $f['subject'], 'name' => $f['name'], 'email' => $f['email'], 'message' => $f['message'],
                      'date' => date('M j, Y', $f['created_ts']), 'status' => $f['status'], 'statusLabel' => $statusLabel,
                      'rating' => $f['rating'] === null ? 0 : (int) $f['rating'], 'public' => $f['type'] === 'buyer',
                      'profileTitle' => $aboutId ? ($f['type'] === 'buyer' ? 'Seller profile' : 'Buyer profile') : 'Submitted by',
                      'profileName' => $profName, 'profileEmail' => $aboutId ? (string) $f['about_email'] : $f['email'], 'initials' => $initials($profName),
                      'profileRating' => $ratings ? number_format(array_sum($ratings) / count($ratings), 1) . ' / 5.0 (' . count($ratings) . ')' : ($aboutId ? 'No ratings yet' : ''),
                      'related' => $f['listing_title'] ?? $f['item_title'] ?? '', 'order' => $f['order_no'] ?? '',
                  ];
                ?>
                <tr class="<?php echo $isSelected ? 'is-selected' : ''; ?>" data-search="<?php echo $e(mb_strtolower($f['subject'] . ' ' . $f['name'] . ' ' . $f['email'] . ' ' . $f['message'] . ' ' . ($f['listing_title'] ?? ''))); ?>" data-status="<?php echo $e($f['status']); ?>" data-rating="<?php echo $f['rating'] === null ? 'none' : (int) $f['rating']; ?>">
                  <td><strong><?php echo sf_feedback_no($f['id']); ?></strong></td>
                  <td class="muted-cell"><?php echo SF_FEEDBACK_TYPES[$f['type']] ?? $e($f['type']); ?></td>
                  <td class="subject-cell"><?php echo $e($f['subject']); ?></td>
                  <td><div class="user-cell"><span><strong><?php echo $e($f['name']); ?></strong><span><?php echo $e($f['email']); ?></span></span></div></td>
                  <td><?php echo sf_stars_html($f['rating']); ?></td>
                  <td class="muted-cell"><?php echo date('M j, Y', $f['created_ts']); ?></td>
                  <td><span class="status-chip caps <?php echo $chip; ?>"><?php echo $statusLabel; ?></span></td>
                  <td class="col-action"><button type="button" class="manage-btn" data-info="<?php echo $e(json_encode($info)); ?>" onclick="openFeedback(this)"><?php echo $open ? 'Review' : 'View'; ?></button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyFeedback"<?php echo $shown ? ' hidden' : ''; ?>><?php echo $shown ? 'No feedback matches your search.' : 'No feedback here yet. It comes from the Contact us form and from ratings on finished orders.'; ?></p>
        </section>

      </div>

  <?php sf_admin_shell_end(); ?>

  <!-- ==================== REVIEW FEEDBACK MODAL ==================== -->
  <dialog class="adm-modal wide review-modal feedback-modal" id="feedbackModal" aria-labelledby="fHeading">
    <div class="review-head">
      <h2 id="fHeading">Review Feedback</h2>
      <span class="review-no" id="fNo"></span>
      <button type="button" class="adm-modal-close" onclick="document.getElementById('feedbackModal').close()" aria-label="Close">&times;</button>
    </div>
    <div class="feedback-modal-body">
      <dl class="feedback-facts">
        <div><dt>Feedback type</dt><dd id="fType"></dd></div>
        <div><dt>Subject</dt><dd id="fSubject"></dd></div>
        <div><dt>Submitted by</dt><dd id="fBy"></dd></div>
        <div><dt>Date submitted</dt><dd id="fDate"></dd></div>
        <div><dt>Status</dt><dd><span class="review-status" id="fStatus"></span></dd></div>
        <div><dt>Rating</dt><dd class="feedback-stars" id="fStars"></dd></div>
      </dl>
      <aside class="profile-card">
        <p class="review-preview-label" id="fProfileTitle"></p>
        <div class="user-cell">
          <span class="user-avatar small" id="fInitials"></span>
          <span><strong id="fProfileName"></strong><a id="fProfileEmail"></a></span>
        </div>
        <dl class="profile-facts">
          <div id="fProfileRatingRow"><dt>Rating:</dt><dd id="fProfileRating"></dd></div>
          <div id="fRelatedRow"><dt>Related listing:</dt><dd id="fRelated"></dd></div>
          <div id="fOrderRow"><dt>Order:</dt><dd id="fOrder"></dd></div>
        </dl>
      </aside>
    </div>
    <blockquote class="review-preview feedback-message" id="fMessage"></blockquote>
    <p class="adm-field-hint" id="fPublicNote">This is a public review: Flag or Dismiss hides its stars from the listing and the seller's rating.</p>
    <form method="post" class="review-actions">
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="id" id="fId" />
      <button type="submit" name="action" value="resolve" class="export-button" data-hide="resolved">Mark as resolved</button>
      <button type="submit" name="action" value="escalate" class="filter-button" data-hide="escalated">Escalate</button>
      <button type="submit" name="action" value="flag" class="filter-button" data-hide="flagged">Flag</button>
      <button type="submit" name="action" value="dismiss" class="filter-button" data-hide="dismissed">Dismiss</button>
      <button type="submit" name="action" value="reopen" class="filter-button" data-show="closed">Reopen</button>
    </form>
  </dialog>

  <script>
    // Review Feedback modal, filled from the row's data
    const fModal = document.getElementById('feedbackModal');
    fModal.addEventListener('click', e => { if (e.target === fModal) fModal.close(); });
    function openFeedback(button) {
      const f = JSON.parse(button.dataset.info);
      const set = (id, text) => { document.getElementById(id).textContent = text; };
      const show = (id, on) => { document.getElementById(id).hidden = !on; };
      set('fNo', f.no);
      set('fType', f.type);
      set('fSubject', f.subject);
      set('fBy', f.name + (f.email ? ' (' + f.email + ')' : ''));
      set('fDate', f.date);
      const status = document.getElementById('fStatus');
      status.textContent = f.statusLabel;
      status.className = 'review-status ' + f.status;
      const stars = document.getElementById('fStars');
      stars.textContent = f.rating ? '★'.repeat(f.rating) + '☆'.repeat(5 - f.rating) : 'No rating';
      stars.classList.toggle('none', !f.rating);
      stars.setAttribute('aria-label', f.rating ? f.rating + ' out of 5 stars' : 'No rating');
      set('fMessage', f.message ? '“' + f.message + '”' : 'No comment, rating only.');

      set('fProfileTitle', f.profileTitle);
      set('fInitials', f.initials);
      set('fProfileName', f.profileName);
      const mail = document.getElementById('fProfileEmail');
      mail.textContent = f.profileEmail;
      mail.href = 'mailto:' + f.profileEmail;
      set('fProfileRating', f.profileRating);
      show('fProfileRatingRow', !!f.profileRating);
      set('fRelated', f.related);
      show('fRelatedRow', !!f.related);
      set('fOrder', f.order ? '#' + f.order : '');
      show('fOrderRow', !!f.order);
      show('fPublicNote', f.public);

      // Offer every change except to the status it already has; Reopen once it's closed
      const closed = !['pending', 'escalated'].includes(f.status);
      document.getElementById('fId').value = f.id;
      fModal.querySelectorAll('[data-hide]').forEach(b => { b.hidden = b.dataset.hide === f.status; });
      fModal.querySelector('[data-show="closed"]').hidden = !closed;
      fModal.showModal();
    }
    <?php if ($selected && !$flash): ?>
    { const b = document.querySelector('#feedbackBody tr.is-selected .manage-btn'); if (b) openFeedback(b); }
    <?php endif; ?>

    // Search (top bar) plus status and rating filters
    const rows = [...document.querySelectorAll('#feedbackBody tr')];
    const statusFilter = document.getElementById('statusFilter');
    const ratingFilter = document.getElementById('ratingFilter');
    let query = '';
    function applyFilters() {
      rows.forEach(r => {
        r.hidden = !r.dataset.search.includes(query)
          || (statusFilter.value !== 'all' && r.dataset.status !== statusFilter.value)
          || (ratingFilter.value !== 'all' && r.dataset.rating !== ratingFilter.value);
      });
      const visible = rows.filter(r => !r.hidden).length;
      document.getElementById('entryCount').textContent = visible + (visible === 1 ? ' entry' : ' entries');
      if (rows.length) document.getElementById('emptyFeedback').hidden = visible > 0;
    }
    window.onAdminSearch = q => { query = q; applyFilters(); };
    statusFilter.addEventListener('change', applyFilters);
    ratingFilter.addEventListener('change', applyFilters);
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('feedbackFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // Export the visible feedback as a CSV file
    document.getElementById('exportReport').addEventListener('click', () => {
      const lines = [['Feedback ID', 'Type', 'Subject', 'Submitted by', 'Email', 'Rating', 'Date', 'Status']];
      rows.filter(r => !r.hidden).forEach(r => {
        const c = r.cells;
        const stars = c[4].querySelector('.on');
        lines.push([c[0].textContent, c[1].textContent, c[2].textContent, c[3].querySelector('strong').textContent,
                    c[3].querySelector('strong + span').textContent, stars ? stars.textContent.length : '',
                    c[5].textContent, c[6].textContent].map(v => String(v).trim()));
      });
      const csv = lines.map(row => row.map(v => '"' + String(v).replaceAll('"', '""') + '"').join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
      const link = document.createElement('a');
      link.href = url; link.download = 'feedback.csv'; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  </script>
</body>
</html>
