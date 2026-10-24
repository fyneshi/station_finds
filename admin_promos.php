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
sf_admin_require($conn, 'promos');

$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$icon = fn($paths, $size = 18) => '<svg aria-hidden="true" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
function done($type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin_promos.php');
    exit;
}

// Add / Edit modal re-opens with these after a mistake
$formError  = null;
$formValues = null;

// ── Add / edit / turn on-off / delete ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];

    } elseif ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $formValues = [
            'id'            => $id,
            'code'          => strtoupper(trim((string) ($_POST['code'] ?? ''))),
            'type'          => isset(SF_PROMO_TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'percent',
            'value'         => trim((string) ($_POST['value'] ?? '')),
            'min_order'     => trim((string) ($_POST['min_order'] ?? '')),
            'max_uses'      => trim((string) ($_POST['max_uses'] ?? '')),
            'once_per_user' => isset($_POST['once_per_user']) ? 1 : 0,
            'expires_on'    => trim((string) ($_POST['expires_on'] ?? '')),
            'status'        => ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active',
        ];
        $v = $formValues;
        $value = $v['type'] === 'free_shipping' ? 0 : $v['value'];
        if (!preg_match('/^[A-Z0-9]{3,20}$/', $v['code'])) {
            $formError = 'Codes are 3–20 letters and numbers, no spaces (e.g. OLFU20).';
        } elseif ($v['type'] === 'percent' && (!is_numeric($value) || $value <= 0 || $value > 100)) {
            $formError = 'A percent discount is between 1 and 100.';
        } elseif ($v['type'] === 'fixed' && (!is_numeric($value) || $value <= 0 || $value > 100000)) {
            $formError = 'Enter how many pesos off (more than ₱0).';
        } elseif ($v['min_order'] !== '' && (!is_numeric($v['min_order']) || $v['min_order'] < 0)) {
            $formError = 'The minimum order should be a peso amount, or empty for none.';
        } elseif ($v['max_uses'] !== '' && !ctype_digit($v['max_uses'])) {
            $formError = 'The usage limit is a whole number, or empty for no limit.';
        } elseif ($v['expires_on'] !== '' && !DateTime::createFromFormat('Y-m-d', $v['expires_on'])) {
            $formError = 'Choose a valid expiry date, or leave it empty.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM promo_codes WHERE code = ? AND id <> ?");
            $stmt->bind_param("si", $v['code'], $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $formError = 'There is already a code called ' . $v['code'] . '.';
            $stmt->close();
        }
        if (!$formError && $id) {
            // A code already used on orders keeps its name (orders refer to it)
            $old = $conn->query("SELECT code FROM promo_codes WHERE id = " . $id)->fetch_assoc();
            if (!$old) done('err', 'That code could not be found.');
            if ($old['code'] !== $v['code'] && sf_promo_uses($conn, $old['code']) > 0) $formError = $old['code'] . ' has been used on orders, so its name can\'t change. Create a new code instead.';
        }

        if (!$formError) {
            $min     = $v['min_order'] === '' ? 0 : (float) $v['min_order'];
            $maxUses = $v['max_uses'] === '' ? null : (int) $v['max_uses'];
            $expires = $v['expires_on'] === '' ? null : $v['expires_on'];
            $value   = (float) $value;
            if ($id) {
                $stmt = $conn->prepare("UPDATE promo_codes SET code = ?, type = ?, value = ?, min_order = ?, max_uses = ?, once_per_user = ?, expires_on = ?, status = ? WHERE id = ?");
                $stmt->bind_param("ssddiissi", $v['code'], $v['type'], $value, $min, $maxUses, $v['once_per_user'], $expires, $v['status'], $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO promo_codes (code, type, value, min_order, max_uses, once_per_user, expires_on, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssddiiss", $v['code'], $v['type'], $value, $min, $maxUses, $v['once_per_user'], $expires, $v['status']);
            }
            $stmt->execute();
            $stmt->close();
            $label = sf_promo_label(['type' => $v['type'], 'value' => $value]);
            sf_admin_log($conn, 'promo', $id ? 'Edited promo code' : 'Created promo code', $v['code'] . ' · ' . $label, $id ? 'edited' : 'added');
            done('ok', $v['code'] . ' was ' . ($id ? 'updated' : 'created') . ' (' . $label . ').');
        }

    } elseif ($action === 'toggle' || $action === 'delete') {
        $id    = (int) ($_POST['id'] ?? 0);
        $promo = $conn->query("SELECT * FROM promo_codes WHERE id = " . $id)->fetch_assoc();
        if (!$promo) done('err', 'That code could not be found.');
        if ($action === 'toggle') {
            $status = $promo['status'] === 'active' ? 'inactive' : 'active';
            $conn->query("UPDATE promo_codes SET status = '" . $status . "' WHERE id = " . $id);
            sf_admin_log($conn, 'promo', $status === 'active' ? 'Turned on promo code' : 'Turned off promo code', $promo['code'], $status);
            done('ok', $promo['code'] . ' is now ' . ($status === 'active' ? 'on' : 'off') . '.');
        }
        if (sf_promo_uses($conn, $promo['code']) > 0) done('err', $promo['code'] . ' has been used on orders, so it can\'t be deleted. Turn it off instead.');
        $conn->query("DELETE FROM promo_codes WHERE id = " . $id);
        sf_admin_log($conn, 'promo', 'Deleted promo code', $promo['code'], 'deleted');
        done('ok', $promo['code'] . ' was deleted.');
    }
}

// ── Codes, with how often each was used and the discount it gave ─
$promos = $conn->query("SELECT p.*, (SELECT COUNT(*) FROM orders o WHERE o.promo_code = p.code) AS uses,
                               (SELECT COALESCE(SUM(o.discount), 0) FROM orders o WHERE o.promo_code = p.code) AS given,
                               (SELECT COALESCE(SUM(o.shipping = 0), 0) FROM orders o WHERE o.promo_code = p.code) AS free_ship
                        FROM promo_codes p ORDER BY p.status = 'active' DESC, p.created_at DESC, p.id DESC")->fetch_all(MYSQLI_ASSOC);
$today = date('Y-m-d');
$state = function ($p) use ($today) {
    if ($p['status'] !== 'active') return ['Off', 'draft'];
    if ($p['expires_on'] !== null && $p['expires_on'] < $today) return ['Expired', 'suspended'];
    if ($p['max_uses'] !== null && $p['uses'] >= $p['max_uses']) return ['Used up', 'suspended'];
    return ['Active', 'active'];
};
$stats = [
    'active' => count(array_filter($promos, fn($p) => $state($p)[0] === 'Active')),
    'uses'   => array_sum(array_column($promos, 'uses')),
    'given'  => array_sum(array_column($promos, 'given')),
    'free'   => array_sum(array_column($promos, 'free_ship')) * SF_SHIPPING_FEE,
];
$csrf = sf_csrf_token();
$tag  = sf_admin_menu()['promos'][2];
$m    = $formValues ?? ['id' => 0, 'code' => '', 'type' => 'percent', 'value' => '', 'min_order' => '', 'max_uses' => '', 'once_per_user' => 1, 'expires_on' => '', 'status' => 'active'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Promo Codes — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=23" />
  <link rel="stylesheet" href="css/admin-shell.css?v=3" />
</head>
<body>

  <?php sf_admin_shell_start('promos', 'Search promo codes…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Promo Codes</h1>
          <p>Discount codes students can enter in their cart.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="export-button" onclick="openPromo()">+ New Code</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <section class="stats-grid">
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Active codes</span><div class="stat-icon-wrapper"><?php echo $icon($tag); ?></div></div>
            <div class="stat-value"><?php echo $stats['active']; ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> of <?php echo count($promos); ?> code<?php echo count($promos) === 1 ? '' : 's'; ?></p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Times used</span><div class="stat-icon-wrapper"><?php echo $icon('<polyline points="20 6 9 17 4 12"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['uses']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> on orders</p>
          </div>
          <div class="stat-card revenue-card">
            <div class="stat-card-top"><span class="stat-label">Discounts given</span><div class="stat-icon-wrapper"><?php echo $icon('<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'); ?></div></div>
            <div class="stat-value"><?php echo sf_peso($stats['given']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> off item prices</p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Free shipping given</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>'); ?></div></div>
            <div class="stat-value"><?php echo sf_peso($stats['free']); ?></div>
            <p class="stat-trend attention">⊙ <?php echo sf_peso(SF_SHIPPING_FEE); ?> per order</p>
          </div>
        </section>

        <section class="activity-card">
          <div class="section-heading"><h2><span class="heading-icon"><?php echo $icon($tag); ?></span>All Codes</h2><span class="time-badge"><?php echo count($promos); ?> total</span></div>
          <div class="table-scroll">
            <table class="users-table plain-head">
              <thead><tr><th>Code</th><th>Discount</th><th>Rules</th><th>Used</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="promoBody">
                <?php foreach ($promos as $p): [$label, $chip] = $state($p);
                  $rules = array_filter([
                      $p['min_order'] > 0 ? 'Min. ' . sf_peso($p['min_order']) : null,
                      $p['once_per_user'] ? 'Once per student' : null,
                      $p['expires_on'] !== null ? 'Until ' . date('M j, Y', strtotime($p['expires_on'])) : null,
                  ]);
                  $info = ['id' => (int) $p['id'], 'code' => $p['code'], 'type' => $p['type'], 'value' => rtrim(rtrim($p['value'], '0'), '.'),
                           'min_order' => $p['min_order'] > 0 ? rtrim(rtrim($p['min_order'], '0'), '.') : '', 'max_uses' => $p['max_uses'] ?? '',
                           'once_per_user' => (int) $p['once_per_user'], 'expires_on' => $p['expires_on'] ?? '', 'status' => $p['status'], 'uses' => (int) $p['uses']];
                ?>
                <tr data-search="<?php echo $e(mb_strtolower($p['code'] . ' ' . sf_promo_label($p))); ?>">
                  <td><span class="promo-code"><?php echo $e($p['code']); ?></span></td>
                  <td><strong><?php echo $e(sf_promo_label($p)); ?></strong></td>
                  <td class="muted-cell"><?php echo $rules ? $e(implode(' · ', $rules)) : 'No limits'; ?></td>
                  <td class="muted-cell"><?php echo (int) $p['uses'] . ($p['max_uses'] !== null ? ' / ' . (int) $p['max_uses'] : ''); ?></td>
                  <td><span class="status-chip caps <?php echo $chip; ?>"><?php echo $label; ?></span></td>
                  <td class="col-action"><button type="button" class="manage-btn" onclick='openPromo(<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Edit</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyPromos"<?php echo $promos ? ' hidden' : ''; ?>><?php echo $promos ? 'No codes match your search.' : 'No promo codes yet. Use + New Code to make one.'; ?></p>
        </section>
      </div>

  <?php sf_admin_shell_end(); ?>

  <!-- ==================== ADD / EDIT CODE ==================== -->
  <dialog class="adm-modal card-modal" id="promoModal" aria-labelledby="promoTitle">
    <form method="post" id="promoForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" value="save" />
      <input type="hidden" name="id" id="pId" value="<?php echo (int) $m['id']; ?>" />
      <div class="card-modal-head">
        <h2 id="promoTitle"><?php echo $m['id'] ? 'Edit Code' : 'New Promo Code'; ?></h2>
        <button type="button" class="adm-modal-close" onclick="closePromo()" aria-label="Close">&times;</button>
      </div>
      <?php if ($formError): ?><div class="adm-flash err" role="alert"><?php echo $e($formError); ?></div><?php endif; ?>

      <div class="adm-field">
        <label for="pCode">Code</label>
        <input type="text" id="pCode" name="code" value="<?php echo $e($m['code']); ?>" maxlength="20" autocomplete="off" placeholder="e.g. OLFU20" style="text-transform: uppercase;" required />
      </div>
      <div class="promo-grid">
        <div class="adm-field">
          <label for="pType">Discount</label>
          <select id="pType" name="type">
            <?php foreach (SF_PROMO_TYPES as $key => $label): ?>
            <option value="<?php echo $key; ?>"<?php echo $m['type'] === $key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="adm-field" id="pValueField">
          <label for="pValue" id="pValueLabel">Percent off</label>
          <input type="number" id="pValue" name="value" value="<?php echo $e($m['value']); ?>" min="0" step="0.01" inputmode="decimal" />
        </div>
      </div>
      <div class="promo-grid">
        <div class="adm-field">
          <label for="pMin">Minimum order <span>(₱, optional)</span></label>
          <input type="number" id="pMin" name="min_order" value="<?php echo $e($m['min_order']); ?>" min="0" step="1" inputmode="decimal" placeholder="None" />
        </div>
        <div class="adm-field">
          <label for="pMax">Usage limit <span>(optional)</span></label>
          <input type="number" id="pMax" name="max_uses" value="<?php echo $e($m['max_uses']); ?>" min="0" step="1" inputmode="numeric" placeholder="No limit" />
        </div>
      </div>
      <div class="promo-grid">
        <div class="adm-field">
          <label for="pExpires">Expires on <span>(optional)</span></label>
          <input type="date" id="pExpires" name="expires_on" value="<?php echo $e($m['expires_on']); ?>" />
        </div>
        <div class="adm-field">
          <label for="pStatus">Status</label>
          <select id="pStatus" name="status">
            <option value="active"<?php echo $m['status'] === 'active' ? ' selected' : ''; ?>>On</option>
            <option value="inactive"<?php echo $m['status'] === 'inactive' ? ' selected' : ''; ?>>Off</option>
          </select>
        </div>
      </div>
      <label class="promo-check"><input type="checkbox" id="pOnce" name="once_per_user" value="1"<?php echo $m['once_per_user'] ? ' checked' : ''; ?> /> Each student can use it only once</label>
      <p class="adm-field-hint" id="pUsedNote"></p>

      <div class="card-modal-split">
        <button type="button" class="plain-link" onclick="closePromo()">Cancel</button>
        <button type="submit" class="export-button wide-save" id="pSave"><?php echo $m['id'] ? 'Save Changes' : 'Create Code'; ?></button>
      </div>
      <button type="submit" name="action" value="toggle" class="reset-link" id="pToggle" formnovalidate hidden></button>
      <button type="submit" name="action" value="delete" class="danger-link" id="pDelete" formnovalidate hidden>Delete code</button>
    </form>
  </dialog>

  <script>
    const modal = document.getElementById('promoModal');
    const type  = document.getElementById('pType');
    // The amount box changes with the kind of discount (none for free shipping)
    function syncType() {
      const t = type.value;
      document.getElementById('pValueField').hidden = t === 'free_shipping';
      document.getElementById('pValueLabel').textContent = t === 'fixed' ? 'Pesos off (₱)' : 'Percent off (%)';
      document.getElementById('pValue').max = t === 'percent' ? 100 : '';
    }
    type.addEventListener('change', syncType);

    function openPromo(p) {
      const editing = !!p;
      const set = (id, v) => { document.getElementById(id).value = v; };
      document.getElementById('promoTitle').textContent = editing ? 'Edit ' + p.code : 'New Promo Code';
      document.getElementById('pSave').textContent = editing ? 'Save Changes' : 'Create Code';
      set('pId', editing ? p.id : 0);
      set('pCode', editing ? p.code : '');
      set('pType', editing ? p.type : 'percent');
      set('pValue', editing && p.type !== 'free_shipping' ? p.value : '');
      set('pMin', editing ? p.min_order : '');
      set('pMax', editing ? p.max_uses : '');
      set('pExpires', editing ? p.expires_on : '');
      set('pStatus', editing ? p.status : 'active');
      document.getElementById('pOnce').checked = editing ? !!p.once_per_user : true;
      const toggle = document.getElementById('pToggle');
      toggle.hidden = !editing;
      toggle.textContent = editing && p.status === 'active' ? 'Turn off this code' : 'Turn on this code';
      document.getElementById('pDelete').hidden = !editing || p.uses > 0;
      document.getElementById('pUsedNote').textContent = editing && p.uses > 0
        ? 'Used on ' + p.uses + ' order' + (p.uses === 1 ? '' : 's') + ': it can be turned off but not deleted, and its name stays.' : '';
      document.getElementById('pCode').readOnly = editing && p.uses > 0;
      const err = modal.querySelector('.adm-flash.err');
      if (err) err.remove();
      syncType();
      modal.showModal();
      document.getElementById(editing ? 'pValue' : 'pCode').focus();
    }
    function closePromo() { modal.close(); }
    modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });
    document.getElementById('promoForm').addEventListener('submit', e => {
      if (e.submitter && e.submitter.value === 'delete' && !confirm('Delete this code? Students will no longer be able to use it.')) e.preventDefault();
    });
    syncType();
    <?php if ($formError): ?>modal.showModal();   // show what needs fixing<?php endif; ?>

    // Search (top bar)
    const rows = [...document.querySelectorAll('#promoBody tr')];
    window.onAdminSearch = q => {
      rows.forEach(r => { r.hidden = !r.dataset.search.includes(q); });
      if (rows.length) document.getElementById('emptyPromos').hidden = rows.some(r => !r.hidden);
    };
  </script>
</body>
</html>
