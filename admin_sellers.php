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
require_once 'seller.php';
require_once 'admin_layout.php';
sf_admin_require($conn, 'seller-apps');

$tabs = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'pending';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function back($tab, $type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin_sellers.php?tab=' . $tab);
    exit;
}

// ── Approve / reject ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } elseif ($action === 'approve' || $action === 'reject') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT user_id, store_name, status FROM seller_profiles WHERE user_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$app) back($tab, 'err', 'That application could not be found.');
        if ($app['status'] !== 'pending') back($tab, 'err', 'That application has already been reviewed.');

        if ($action === 'approve') {
            // Optional note, kept as the approval record (admins only)
            $approveNote = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['note'] ?? ''))), 0, 255);
            $stmt = $conn->prepare("UPDATE seller_profiles SET status = 'approved', review_note = ?, reviewed_at = NOW() WHERE user_id = ?");
            $stmt->bind_param("si", $approveNote, $id);
            $stmt->execute();
            $stmt->close();
            sf_admin_log($conn, 'seller', 'Approved seller application', $app['store_name'], 'approved');
            sf_notify($conn, (int) $id, 'info', 'You\'re approved to sell!',
                $app['store_name'] . ' is ready. Create your first listing to start selling to other students.', 'listing_form.php');
            back($tab, 'ok', $app['store_name'] . ' was approved. They can start listing now.');
        }

        $note = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['note'] ?? ''))), 0, 255);
        if ($note === '') back($tab, 'err', 'Add a reason so the applicant knows what to fix.');
        $stmt = $conn->prepare("UPDATE seller_profiles SET status = 'rejected', review_note = ?, reviewed_at = NOW() WHERE user_id = ?");
        $stmt->bind_param("si", $note, $id);
        $stmt->execute();
        $stmt->close();
        sf_admin_log($conn, 'seller', 'Rejected seller application', $app['store_name'], 'rejected');
        sf_notify($conn, (int) $id, 'info', 'Seller application not approved',
            'Reason: ' . $note . ' You can update your details and resubmit from your Account page.', 'account.php');
        back($tab, 'ok', $app['store_name'] . ' was rejected. The applicant has been told why.');
    }
}

// ── Applications ────────────────────────────────────────────────
$apps = $conn->query("SELECT s.*, u.username, u.full_name, u.email,
           UNIX_TIMESTAMP(COALESCE(s.submitted_at, s.created_at)) AS submitted_ts,
           UNIX_TIMESTAMP(s.reviewed_at) AS reviewed_ts
    FROM seller_profiles s JOIN users u ON u.id = s.user_id
    ORDER BY FIELD(s.status, 'pending', 'rejected', 'approved'), COALESCE(s.submitted_at, s.created_at) DESC")->fetch_all(MYSQLI_ASSOC);

$monthStart = strtotime(date('Y-m-01'));
$lastStart  = strtotime(date('Y-m-01', strtotime('first day of last month')));
$reviewedIn = fn($status, $from, $to = PHP_INT_MAX) => count(array_filter($apps,
    fn($a) => $a['status'] === $status && $a['reviewed_ts'] && $a['reviewed_ts'] >= $from && $a['reviewed_ts'] < $to));
$stats = [
    'pending'        => count(array_filter($apps, fn($a) => $a['status'] === 'pending')),
    'approved_month' => $reviewedIn('approved', $monthStart),
    'approved_last'  => $reviewedIn('approved', $lastStart, $monthStart),
    'rejected_month' => $reviewedIn('rejected', $monthStart),
];
$approvedTrend = $stats['approved_last'] > 0
    ? (($stats['approved_month'] >= $stats['approved_last'] ? '+' : '') . round(($stats['approved_month'] - $stats['approved_last']) / $stats['approved_last'] * 100) . '% vs last month')
    : ($stats['approved_month'] ? 'New this month' : 'None approved yet this month');

$tabCounts = ['all' => count($apps)];
foreach (['pending', 'approved', 'rejected'] as $s) $tabCounts[$s] = count(array_filter($apps, fn($a) => $a['status'] === $s));
$shown = $tab === 'all' ? $apps : array_filter($apps, fn($a) => $a['status'] === $tab);

$categories = [];                       // every category name, including turned-off ones
foreach (sf_categories() as $cats) $categories += $cats;
$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$statusChip = ['pending' => ['Pending', 'pending'], 'approved' => ['Approved', 'active'], 'rejected' => ['Rejected', 'flagged']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Seller Applications — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="CSS/admin.css?v=22" />
  <link rel="stylesheet" href="CSS/admin-shell.css?v=2" />
</head>
<body>

  <?php sf_admin_shell_start('seller-apps', 'Search applicants, stores…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Seller Applications</h1>
          <p>Review and approve seller verification requests.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="appFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="export-button" id="exportReport">Export Report</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <div class="filter-panel" id="appFilters" hidden>
          <label for="typeFilter">Seller type</label>
          <select id="typeFilter">
            <option value="all">All types</option>
            <option value="individual">Individual</option>
            <option value="business">Business</option>
          </select>
        </div>

        <section class="stats-grid three">
          <a href="admin_sellers.php?tab=pending" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Pending review</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
            <p class="stat-trend<?php echo $stats['pending'] ? ' attention' : ''; ?>"><?php echo $stats['pending'] ? '⊙ Needs attention' : 'All caught up'; ?></p>
          </a>
          <a href="admin_sellers.php?tab=approved" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Approved this month</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['approved_month']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo $e($approvedTrend); ?></p>
          </a>
          <a href="admin_sellers.php?tab=rejected" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Rejected this month</span><div class="stat-icon-wrapper"><?php echo $icon('<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['rejected_month']); ?></div>
            <p class="stat-trend<?php echo $stats['rejected_month'] ? ' danger' : ''; ?>"><?php echo $stats['rejected_month'] ? '⊗ ' . $stats['rejected_month'] . ' declined request' . ($stats['rejected_month'] === 1 ? '' : 's') : 'None declined'; ?></p>
          </a>
        </section>

        <nav class="users-tabs" aria-label="Filter applications">
          <?php foreach ($tabs as $key => $label): ?>
          <a href="admin_sellers.php?tab=<?php echo $key; ?>" class="users-tab<?php echo $tab === $key ? ' active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo $label; ?> <span><?php echo $tabCounts[$key]; ?></span></a>
          <?php endforeach; ?>
        </nav>

        <section class="users-card">
          <div class="table-scroll">
            <table class="users-table" id="appsTable">
              <thead><tr><th>Applicant</th><th>Store name</th><th>Type</th><th>Submitted</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="appsBody">
                <?php foreach ($shown as $a):
                  $name = $a['full_name'] !== '' ? $a['full_name'] : $a['username'];
                  [$chipText, $chipClass] = $statusChip[$a['status']] ?? [ucfirst($a['status']), 'draft'];
                  [, $payTitle, $payMasked] = sf_payout_destination($a);
                  $info = [
                      'id' => (int) $a['user_id'], 'name' => $name, 'email' => $a['email'], 'store' => $a['store_name'],
                      'type' => $a['seller_type'] === 'business' ? 'Registered business' : 'Individual seller',
                      'category' => $categories[$a['category']] ?? $a['category'], 'contact' => $a['contact_number'],
                      'school' => $a['school'], 'studentId' => $a['student_id'], 'about' => $a['about'],
                      'payout' => $payTitle . ' ' . $payMasked . ' (' . $a['payout_name'] . ')',
                      'submitted' => date('M j, Y g:i A', $a['submitted_ts']), 'status' => $a['status'], 'statusText' => $chipText,
                      'note' => $a['review_note'], 'reviewed' => $a['reviewed_ts'] ? date('M j, Y', $a['reviewed_ts']) : '',
                      'file' => sf_id_document_path($a['id_document']) ? ($a['id_document_name'] ?: 'ID document') : '', 'initials' => $a['full_name'] !== '' ? sf_initials($a['full_name']) : strtoupper(mb_substr($a['username'], 0, 2)),
                  ];
                ?>
                <tr data-search="<?php echo $e(mb_strtolower($name . ' ' . $a['email'] . ' ' . $a['store_name'] . ' ' . $a['school'])); ?>" data-type="<?php echo $e($a['seller_type']); ?>">
                  <td>
                    <div class="user-cell">
                      <span class="user-avatar <?php echo $a['status']; ?>"><?php echo $e($a['full_name'] !== '' ? sf_initials($a['full_name']) : strtoupper(mb_substr($a['username'], 0, 2))); ?></span>
                      <span><strong><?php echo $e($name); ?></strong><span><?php echo $e($a['email']); ?></span></span>
                    </div>
                  </td>
                  <td><?php echo $e($a['store_name']); ?></td>
                  <td class="muted-cell"><?php echo $a['seller_type'] === 'business' ? 'Business' : 'Individual'; ?></td>
                  <td class="muted-cell"><?php echo date('M j, Y', $a['submitted_ts']); ?></td>
                  <td><span class="status-chip caps <?php echo $chipClass; ?>"><?php echo $chipText; ?></span></td>
                  <td class="col-action">
                    <button type="button" class="manage-btn<?php echo $a['status'] === 'pending' ? ' primary' : ''; ?>" onclick='openReview(<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'><?php echo $a['status'] === 'pending' ? 'Review' : 'View'; ?></button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyApps"<?php echo $shown ? ' hidden' : ''; ?>><?php echo $shown ? 'No applications match your search.' : 'No ' . ($tab === 'all' ? '' : strtolower($tabs[$tab]) . ' ') . 'applications.'; ?></p>
        </section>
      </div>

  <!-- ==================== REVIEW APPLICATION MODAL ==================== -->
  <dialog class="adm-modal card-modal" id="reviewModal" aria-labelledby="rHeading">
    <div class="card-modal-head">
      <div>
        <h2 id="rHeading">Review Application</h2>
        <p class="card-modal-sub">Review applicant details and submitted files.</p>
      </div>
      <button type="button" class="adm-modal-close" onclick="closeReview()" aria-label="Close">&times;</button>
    </div>
    <div class="user-cell card-modal-who">
      <span class="user-avatar" id="rAvatar"></span>
      <span><strong id="rName"></strong><span id="rEmail"></span></span>
    </div>
    <dl class="card-facts">
      <div><dt>Store Name</dt><dd id="rStore"></dd></div>
      <div><dt>Type</dt><dd id="rType"></dd></div>
      <div><dt>Submitted</dt><dd id="rSubmitted"></dd></div>
      <div><dt>Status</dt><dd><span class="outline-chip" id="rStatus"></span></dd></div>
    </dl>

    <p class="review-preview-label">Submitted files</p>
    <a class="file-row" id="rFile" target="_blank" rel="noopener">
      <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <span id="rFileName"></span>
      <em id="rFileTag"></em>
    </a>
    <p class="file-row empty" id="rNoFile">No file submitted. This application was sent before ID uploads were added.</p>

    <form method="post" id="rForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="id" id="rId" />
      <div class="adm-field">
        <label for="rReason" class="review-preview-label">Reason / admin notes</label>
        <textarea id="rReason" name="note" maxlength="255" rows="2" placeholder="Optional notes for rejection or approval record…"></textarea>
        <span class="adm-field-hint">A reason is needed to reject: it's sent to the applicant. Approval notes stay with the admin team.</span>
      </div>
      <div class="card-modal-split">
        <button type="submit" name="action" value="reject" class="danger-link" id="rReject">Reject</button>
        <button type="submit" name="action" value="approve" class="export-button" id="rApprove">Approve</button>
      </div>
    </form>
    <div class="review-preview log" id="rDecision">
      <p class="review-preview-label" id="rDecisionLabel"></p>
      <p id="rDecisionText"></p>
    </div>
  </dialog>

  <?php sf_admin_shell_end(); ?>

  <script>
    const modal = document.getElementById('reviewModal');
    function closeReview() { modal.close(); }
    modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });

    function openReview(a) {
      const set = (id, text) => { document.getElementById(id).textContent = text; };
      set('rAvatar', a.initials);
      set('rName', a.name);
      set('rEmail', a.email);
      set('rStore', a.store);
      set('rType', a.type);
      set('rSubmitted', a.submitted);
      const status = document.getElementById('rStatus');
      status.textContent = a.statusText;
      status.className = 'outline-chip ' + a.status;

      // The ID they uploaded, opened by the admin-only file page
      const file = document.getElementById('rFile');
      file.hidden = !a.file;
      document.getElementById('rNoFile').hidden = !!a.file;
      if (a.file) {
        file.href = 'admin_seller_file.php?id=' + a.id;
        set('rFileName', a.file);
        const tag = document.getElementById('rFileTag');
        tag.textContent = a.status === 'approved' ? 'Verified' : a.status === 'rejected' ? 'Not accepted' : 'Open to check';
        tag.className = a.status;
      }

      // Pending: decide. Reviewed: show the decision and its note
      document.getElementById('rId').value = a.id;
      const reason = document.getElementById('rReason');
      reason.value = '';
      reason.classList.remove('has-error');
      const pending = a.status === 'pending';
      document.getElementById('rForm').hidden = !pending;
      document.getElementById('rDecision').hidden = pending;
      set('rDecisionLabel', (a.status === 'approved' ? 'Approved' : 'Rejected') + (a.reviewed ? ' on ' + a.reviewed : ''));
      set('rDecisionText', a.note ? (a.status === 'approved' ? 'Note: ' : 'Reason: ') + a.note : 'No note was added.');
      modal.showModal();
    }

    document.getElementById('rForm').addEventListener('submit', e => {
      if (e.submitter && e.submitter.value === 'reject' && !document.getElementById('rReason').value.trim()) {
        e.preventDefault();
        const reason = document.getElementById('rReason');
        reason.classList.add('has-error');
        reason.focus();
      }
    });

    // Search (top bar) and seller-type filter
    const rows = [...document.querySelectorAll('#appsBody tr')];
    const typeFilter = document.getElementById('typeFilter');
    let query = '';
    function applyFilters() {
      rows.forEach(r => {
        r.hidden = !r.dataset.search.includes(query) || (typeFilter.value !== 'all' && r.dataset.type !== typeFilter.value);
      });
      if (rows.length) document.getElementById('emptyApps').hidden = rows.some(r => !r.hidden);
    }
    window.onAdminSearch = q => { query = q; applyFilters(); };
    typeFilter.addEventListener('change', applyFilters);
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('appFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // Export the visible applications as a CSV file
    document.getElementById('exportReport').addEventListener('click', () => {
      const lines = [['Applicant', 'Email', 'Store name', 'Type', 'Submitted', 'Status']];
      rows.filter(r => !r.hidden).forEach(r => {
        const c = r.cells;
        lines.push([c[0].querySelector('strong').textContent, c[0].querySelector('strong + span').textContent,
                    c[1].textContent, c[2].textContent, c[3].textContent, c[4].textContent].map(v => v.trim()));
      });
      const csv = lines.map(row => row.map(v => '"' + String(v).replaceAll('"', '""') + '"').join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
      const link = document.createElement('a');
      link.href = url; link.download = 'seller-applications.csv'; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  </script>
</body>
</html>
