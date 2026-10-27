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
sf_admin_require($conn, 'categories');

$types   = ['products' => 'Products', 'rentals' => 'Rentals', 'services' => 'Services'];
$filters = ['all' => 'All'] + $types;
$filter  = isset($filters[$_GET['type'] ?? '']) ? $_GET['type'] : 'all';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$modalError = null;     // shown inside the modal, which re-opens with what was typed
$modalValues = null;

function done($filter, $type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin_categories.php?type=' . $filter);
    exit;
}

// "Dorm Gear" -> "dorm-gear"
function category_slug($name) {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
}

// Listings using each category: ["products|books" => 3, ...]
function category_usage($conn) {
    $usage = [];
    $res = $conn->query("SELECT type, category, COUNT(*) AS n FROM listings GROUP BY type, category");
    while ($res && $row = $res->fetch_assoc()) $usage[$row['type'] . '|' . $row['category']] = (int) $row['n'];
    return $usage;
}

// ── Add / edit / turn on-off / delete ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];

    } elseif ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $name   = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
        $type   = (string) ($_POST['type'] ?? '');
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $slug   = category_slug($name);
        $modalValues = ['id' => $id, 'name' => $name, 'type' => $type, 'status' => $status];

        $existing = null;
        if ($id) {
            $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$existing) done($filter, 'err', 'That category could not be found.');
            // Listings store the type and slug, so those stay as they are once created
            $type = $existing['type'];
            $slug = $existing['slug'];
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 40 || $slug === '') {
            $modalError = 'Enter a category name of 2–40 characters, including letters or numbers.';
        } elseif (!isset($types[$type])) {
            $modalError = 'Choose a type.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE type = ? AND (slug = ? OR LOWER(name) = LOWER(?)) AND id <> ?");
            $stmt->bind_param("sssi", $type, $slug, $name, $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $modalError = 'There is already a ' . $types[$type] . ' category called "' . $name . '".';
            $stmt->close();
        }

        if (!$modalError) {
            if ($existing) {
                $stmt = $conn->prepare("UPDATE categories SET name = ?, status = ? WHERE id = ?");
                $stmt->bind_param("ssi", $name, $status, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO categories (type, slug, name, status) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $type, $slug, $name, $status);
            }
            $stmt->execute();
            $stmt->close();
            sf_admin_log($conn, 'category', $existing ? 'Edited category' : 'Added category', $name . ' · ' . $types[$type], $existing ? 'edited' : 'added');
            done($filter, 'ok', '"' . $name . '" was ' . ($existing ? 'updated' : 'added to ' . $types[$type]) . '.');
        }

    } elseif ($action === 'toggle' || $action === 'delete') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $cat = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$cat) done($filter, 'err', 'That category could not be found.');

        if ($action === 'toggle') {
            $status = $cat['status'] === 'active' ? 'inactive' : 'active';
            $stmt = $conn->prepare("UPDATE categories SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            $stmt->close();
            sf_admin_log($conn, 'category', $status === 'active' ? 'Turned on category' : 'Turned off category', $cat['name'] . ' · ' . ($types[$cat['type']] ?? $cat['type']), $status);
            done($filter, 'ok', '"' . $cat['name'] . '" is now ' . $status . '.');
        }

        $used = category_usage($conn)[$cat['type'] . '|' . $cat['slug']] ?? 0;
        if ($used > 0) {
            done($filter, 'err', '"' . $cat['name'] . '" is used by ' . $used . ' listing' . ($used === 1 ? '' : 's') . ', so it can\'t be deleted. Set it to inactive instead.');
        }
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        sf_admin_log($conn, 'category', 'Deleted category', $cat['name'] . ' · ' . ($types[$cat['type']] ?? $cat['type']), 'deleted', true);
        done($filter, 'ok', '"' . $cat['name'] . '" was deleted.');
    }
}

// ── Categories, with how many listings use each ─────────────────
$categories = $conn->query("SELECT *, UNIX_TIMESTAMP(created_at) AS ts FROM categories
                            ORDER BY FIELD(type, 'products', 'rentals', 'services'), name")->fetch_all(MYSQLI_ASSOC);
$usage  = category_usage($conn);
$active = [];   // active (published) listings per type
$res = $conn->query("SELECT type, COUNT(*) AS n FROM listings WHERE status = 'active' GROUP BY type");
while ($res && $row = $res->fetch_assoc()) $active[$row['type']] = (int) $row['n'];

$monthStart = strtotime(date('Y-m-01'));
$perType    = array_count_values(array_column($categories, 'type')) + ['products' => 0, 'rentals' => 0, 'services' => 0];
$stats = [
    'total'    => count($categories),
    'month'    => count(array_filter($categories, fn($c) => $c['ts'] >= $monthStart)),
    'active'   => count(array_filter($categories, fn($c) => $c['status'] === 'active')),
    'inactive' => count(array_filter($categories, fn($c) => $c['status'] !== 'active')),
    'listings' => array_sum($usage),
];
$recent = $categories;
usort($recent, fn($a, $b) => $b['ts'] <=> $a['ts'] ?: $b['id'] <=> $a['id']);
$recent = array_slice($recent, 0, 3);

$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$m    = $modalValues ?? ['id' => 0, 'name' => '', 'type' => 'products', 'status' => 'active'];
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$typeIcons = [
    'products' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    'rentals'  => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
    'services' => '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
];
$folder = '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Category Management — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=25" />
  <link rel="stylesheet" href="css/admin-shell.css?v=5" />
</head>
<body>

  <?php sf_admin_shell_start('categories', 'Search categories…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Category Management</h1>
          <p>Organize the categories buyers browse and filter by.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="catFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="export-button" onclick="openCategoryModal()">Add Category</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <div class="filter-panel" id="catFilters" hidden>
          <label for="typeFilter">Type</label>
          <select id="typeFilter">
            <option value="all">All types</option>
            <?php foreach ($types as $key => $label): ?><option value="<?php echo $key; ?>"><?php echo $label; ?></option><?php endforeach; ?>
          </select>
          <label for="statusFilter">Status</label>
          <select id="statusFilter">
            <option value="all">Any status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>

        <section class="stats-grid">
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Total categories</span><div class="stat-icon-wrapper"><?php echo $icon($folder); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> +<?php echo $stats['month']; ?> this month</p>
          </div>
          <?php foreach ($types as $key => $label): ?>
          <button type="button" class="stat-card stat-filter<?php echo $key === 'rentals' ? ' revenue-card' : ''; ?>" data-type="<?php echo $key; ?>">
            <div class="stat-card-top"><span class="stat-label"><?php echo $label; ?></span><div class="stat-icon-wrapper<?php echo $key === 'services' ? ' pending-icon' : ''; ?>"><?php echo $icon($typeIcons[$key]); ?></div></div>
            <div class="stat-value"><?php echo number_format($perType[$key]); ?></div>
            <p class="stat-trend<?php echo $key === 'services' ? ' attention' : ''; ?>"><span class="trend-chip">↗</span> <?php echo number_format($active[$key] ?? 0); ?> active listing<?php echo ($active[$key] ?? 0) === 1 ? '' : 's'; ?></p>
          </button>
          <?php endforeach; ?>
        </section>

        <section class="activity-card">
          <div class="section-heading"><h2><span class="heading-icon"><?php echo $icon($folder); ?></span>All Categories</h2><span class="time-badge" id="catCount"><?php echo $stats['total']; ?> total</span></div>
          <div class="table-scroll">
            <table class="users-table plain-head">
              <thead><tr><th>Category name</th><th>Type</th><th>Listings</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="catBody">
                <?php foreach ($categories as $c):
                  $used = $usage[$c['type'] . '|' . $c['slug']] ?? 0;
                  $on   = $c['status'] === 'active';
                  $info = ['id' => (int) $c['id'], 'name' => $c['name'], 'type' => $c['type'], 'status' => $c['status'], 'used' => $used];
                ?>
                <tr data-search="<?php echo $e(mb_strtolower($c['name'] . ' ' . $types[$c['type']])); ?>" data-type="<?php echo $e($c['type']); ?>" data-status="<?php echo $on ? 'active' : 'inactive'; ?>">
                  <td><div class="listing-cell"><span class="report-icon<?php echo $on ? '' : ' muted-icon'; ?>"><?php echo $icon($typeIcons[$c['type']] ?? $folder); ?></span><strong><?php echo $e($c['name']); ?></strong></div></td>
                  <td><span class="category-chip caps"><?php echo $types[$c['type']] ?? $e($c['type']); ?></span></td>
                  <td><?php echo number_format($used); ?></td>
                  <td><span class="status-chip <?php echo $on ? 'active' : 'flagged'; ?>"><?php echo $on ? 'Active' : 'Inactive'; ?></span></td>
                  <td class="col-action"><button type="button" class="manage-btn" onclick='openCategoryModal(<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Edit</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyCats"<?php echo $categories ? ' hidden' : ''; ?>><?php echo $categories ? 'No categories match your search.' : 'No categories yet. Add the first one.'; ?></p>
        </section>

        <div class="category-bottom">
          <section class="health-card" aria-labelledby="catHealth">
            <h2 id="catHealth" class="health-title"><span class="heading-icon"><?php echo $icon('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'); ?></span>Category Health</h2>
            <dl>
              <div><dt>Active categories</dt><dd><?php echo number_format($stats['active']); ?></dd></div>
              <div><dt>Inactive categories</dt><dd><?php echo number_format($stats['inactive']); ?></dd></div>
              <div><dt>Total listings</dt><dd><?php echo number_format($stats['listings']); ?></dd></div>
            </dl>
          </section>

          <section class="card-box recent-cats" aria-labelledby="recentTitle">
            <h2 id="recentTitle">Recently added</h2>
            <?php foreach ($recent as $c): $used = $usage[$c['type'] . '|' . $c['slug']] ?? 0; ?>
            <div class="recent-cat">
              <span class="report-icon"><?php echo $icon($typeIcons[$c['type']] ?? $folder); ?></span>
              <span class="recent-cat-text"><strong><?php echo $e($c['name']); ?></strong><span><?php echo $types[$c['type']] ?? ''; ?> · Added <?php echo sf_time_ago($c['ts']); ?></span></span>
              <?php if (time() - $c['ts'] < 7 * 86400): ?><span class="status-chip active caps">New</span>
              <?php else: ?><span class="category-chip"><?php echo $used; ?> listing<?php echo $used === 1 ? '' : 's'; ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (!$recent): ?><p class="empty-state">No categories yet.</p><?php endif; ?>
          </section>
        </div>
      </div>

  <!-- ==================== ADD / EDIT CATEGORY MODAL ==================== -->
  <dialog class="adm-modal card-modal" id="categoryModal" aria-labelledby="categoryModalTitle">
    <form method="post" id="catForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="id" id="catId" value="<?php echo (int) $m['id']; ?>" />
      <div class="card-modal-head">
        <h2 id="categoryModalTitle"><?php echo $m['id'] ? 'Edit Category' : 'Add Category'; ?></h2>
        <button type="button" class="adm-modal-close" onclick="closeCategoryModal()" aria-label="Close">&times;</button>
      </div>

      <?php if ($modalError): ?><div class="adm-flash err" role="alert"><?php echo $e($modalError); ?></div><?php endif; ?>

      <div class="adm-field">
        <label for="catName">Category Name</label>
        <input type="text" id="catName" name="name"<?php echo $modalError ? ' class="has-error"' : ''; ?> value="<?php echo $e($m['name']); ?>" maxlength="40" placeholder="Enter category name" autocomplete="off" required />
      </div>
      <div class="adm-field">
        <label for="catType">Type</label>
        <select id="catType" name="type"<?php echo $m['id'] ? ' disabled' : ''; ?>>
          <?php foreach ($types as $key => $label): ?>
          <option value="<?php echo $key; ?>"<?php echo $m['type'] === $key ? ' selected' : ''; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="adm-field">
        <label for="catStatus">Status</label>
        <select id="catStatus" name="status">
          <option value="active"<?php echo $m['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
          <option value="inactive"<?php echo $m['status'] === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
        </select>
      </div>
      <div class="adm-field" id="catCountField"<?php echo $m['id'] ? '' : ' hidden'; ?>>
        <label for="catUsed">Listings count</label>
        <input type="text" id="catUsed" readonly tabindex="-1" />
      </div>

      <div class="card-modal-split">
        <button type="button" class="plain-link" onclick="closeCategoryModal()">Cancel</button>
        <button type="submit" name="action" value="save" class="export-button wide-save" id="catSubmit"><?php echo $m['id'] ? 'Save Changes' : 'Add Category'; ?></button>
      </div>
      <button type="submit" name="action" value="delete" class="danger-link" id="catDelete" formnovalidate hidden>Delete category</button>
      <p class="card-modal-note" id="catDeleteNote"></p>
    </form>
  </dialog>

  <?php sf_admin_shell_end(); ?>

  <script>
    const modal = document.getElementById('categoryModal');

    // Opens the modal empty (Add) or filled in with a category (Edit)
    function openCategoryModal(cat) {
      const editing = !!cat;
      document.getElementById('categoryModalTitle').textContent = editing ? 'Edit Category' : 'Add Category';
      document.getElementById('catSubmit').textContent = editing ? 'Save Changes' : 'Add Category';
      document.getElementById('catId').value = editing ? cat.id : 0;
      const name = document.getElementById('catName');
      name.value = editing ? cat.name : '';
      name.classList.remove('has-error');
      const type = document.getElementById('catType');
      type.value = editing ? cat.type : (document.getElementById('typeFilter').value !== 'all' ? document.getElementById('typeFilter').value : 'products');
      type.disabled = editing;
      type.title = editing ? 'The type can\'t change after a category is created.' : '';
      document.getElementById('catCountField').hidden = !editing;
      document.getElementById('catUsed').value = editing ? cat.used.toLocaleString('en-US') : '';
      document.getElementById('catStatus').value = editing ? cat.status : 'active';
      const del = document.getElementById('catDelete');
      del.hidden = !editing;
      // Categories in use stay (set them to inactive instead)
      del.hidden = !editing || cat.used > 0;
      document.getElementById('catDeleteNote').textContent = editing && cat.used > 0
        ? 'Used by ' + cat.used + ' listing' + (cat.used === 1 ? '' : 's') + ', so it can\'t be deleted. Set it to Inactive instead.' : '';
      const error = modal.querySelector('.adm-flash.err');
      if (error) error.remove();
      modal.showModal();
      name.focus();
    }
    function closeCategoryModal() { modal.close(); }
    modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });
    document.getElementById('catForm').addEventListener('submit', e => {
      if (e.submitter && e.submitter.value === 'delete' && !confirm('Delete this category?')) e.preventDefault();
    });

    // Search (top bar), type and status filters, and the type cards
    const rows = [...document.querySelectorAll('#catBody tr')];
    const typeFilter = document.getElementById('typeFilter');
    const statusFilter = document.getElementById('statusFilter');
    let query = '';
    function applyFilters() {
      rows.forEach(r => {
        r.hidden = !r.dataset.search.includes(query)
          || (typeFilter.value !== 'all' && r.dataset.type !== typeFilter.value)
          || (statusFilter.value !== 'all' && r.dataset.status !== statusFilter.value);
      });
      const visible = rows.filter(r => !r.hidden).length;
      document.getElementById('catCount').textContent = visible === rows.length ? rows.length + ' total' : visible + ' of ' + rows.length;
      if (rows.length) document.getElementById('emptyCats').hidden = visible > 0;
      document.querySelectorAll('.stat-filter').forEach(c => c.classList.toggle('selected', c.dataset.type === typeFilter.value));
    }
    window.onAdminSearch = q => { query = q; applyFilters(); };
    typeFilter.addEventListener('change', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
    document.querySelectorAll('.stat-filter').forEach(card => card.addEventListener('click', () => {
      typeFilter.value = typeFilter.value === card.dataset.type ? 'all' : card.dataset.type;
      applyFilters();
    }));
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('catFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    <?php if ($modalError): ?>
    modal.showModal();   // re-open with the error and what was typed
    <?php endif; ?>
  </script>
</body>
</html>
