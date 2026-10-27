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

$adminId = (int) $_SESSION['user_id'];
$e       = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$icon    = fn($paths, $size = 16) => '<svg aria-hidden="true" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
$myAccess = sf_admin_access_of($me);

$passwordMatches = fn($p) => (string) $p !== '' && password_verify((string) $p, $me['password']);

function done($type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin_settings.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];
$old    = [];
$open   = '';   // which credentials form to show open (after an error)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];

    } elseif ($action === 'change_email') {
        $old['email'] = trim((string) ($_POST['new_email'] ?? ''));
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 255) {
            $errors['new_email'] = 'Please enter a valid email address.';
        } elseif (strcasecmp($old['email'], $me['email']) === 0) {
            $errors['new_email'] = 'That is already your email.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
            $stmt->bind_param("si", $old['email'], $adminId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $errors['new_email'] = 'That email is already used by another account.';
            $stmt->close();
        }
        if (!$passwordMatches($_POST['email_password'] ?? '')) $errors['email_password'] = 'Your password is incorrect.';
        if (!$errors) {
            $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->bind_param("si", $old['email'], $adminId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['email'] = $old['email'];
            sf_admin_log($conn, 'user', 'Changed own login email', $me['username'], 'edited');
            done('ok', 'Your email was changed. Use ' . $old['email'] . ' to log in from now on.');
        }
        $open = 'email';

    } elseif ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (!$passwordMatches($current)) $errors['current_password'] = 'Your current password is incorrect.';
        if (strlen($new) < 8)      $errors['new_password'] = 'Use at least 8 characters.';
        elseif ($new === $current) $errors['new_password'] = 'Choose a password different from your current one.';
        if ($confirm !== $new)     $errors['confirm_password'] = 'Passwords do not match.';
        if (!$errors) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hash, $adminId);
            $stmt->execute();
            $stmt->close();
            sf_admin_log($conn, 'user', 'Changed own password', $me['username'], 'edited');
            done('ok', 'Your password was changed.');
        }
        $open = 'password';

    } elseif ($action === 'save_prefs') {
        $prefs = [];
        foreach (SF_NOTICE_PREFS as $key => $_) $prefs[$key] = isset($_POST['pref'][$key]) ? 1 : 0;
        $json = json_encode($prefs);
        $stmt = $conn->prepare("UPDATE users SET admin_notice_prefs = ? WHERE id = ?");
        $stmt->bind_param("si", $json, $adminId);
        $stmt->execute();
        $stmt->close();
        done('ok', 'Your notification preferences were saved.');

    } elseif ($action === 'save_role') {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim(preg_replace('/\s+/', ' ', (string) ($_POST['title'] ?? '')));
        if (!$myAccess['full']) done('err', 'Only a super admin can change what other admins can access.');
        if ($id === $adminId)  done('err', 'You can\'t change your own access. Ask another super admin.');
        $stmt = $conn->prepare("SELECT id, username, admin_access, admin_title FROM users WHERE id = ? AND role = 'admin'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$target) done('err', 'That admin account could not be found.');
        if (mb_strlen($title) > 50 || !preg_match('/^[\p{L}\p{N} &\/.,()-]*$/u', $title)) done('err', 'Use up to 50 letters and numbers for the role name.');

        $full   = isset($_POST['full']);
        $areas  = array_values(array_intersect(array_keys(SF_ADMIN_AREAS), (array) ($_POST['areas'] ?? [])));
        $access = $full ? null : implode(',', $areas);
        $stmt = $conn->prepare("UPDATE users SET admin_title = ?, admin_access = ? WHERE id = ?");
        $stmt->bind_param("ssi", $title, $access, $id);
        $stmt->execute();
        $stmt->close();
        $summary = $full ? 'Full access' : ($areas ? implode(', ', array_map(fn($a) => SF_ADMIN_AREAS[$a], $areas)) : 'No access');
        sf_admin_log($conn, 'user', 'Changed admin access', $target['username'] . ' · ' . $summary, 'edited', true);
        done('ok', $target['username'] . '\'s access was updated: ' . $summary . '.');
    }
}

$prefs  = sf_admin_notice_prefs($me['admin_notice_prefs']);
$admins = $conn->query("SELECT id, username, full_name, email, status, admin_title, admin_access FROM users WHERE role = 'admin'
                        ORDER BY admin_access IS NOT NULL, id")->fetch_all(MYSQLI_ASSOC);
$csrf   = sf_csrf_token();

$fieldBad = fn($f) => isset($errors[$f]) ? ' has-error' : '';
$fieldErr = fn($f) => isset($errors[$f]) ? '<span class="adm-field-error">' . $e($errors[$f]) . '</span>' : '';
$menu     = sf_admin_menu();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Settings — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=25" />
  <link rel="stylesheet" href="css/admin-shell.css?v=6" />
</head>
<body>

  <?php sf_admin_shell_start('settings', 'Search users, listings, orders…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Settings</h1>
          <p>Manage your admin account and platform preferences.</p>
        </div>
        <div class="header-actions">
          <button type="submit" form="prefsForm" class="export-button">Save Changes</button>
        </div>
      </header>

      <div class="dashboard-content settings-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <!-- ── Login credentials ── -->
        <section class="settings-card">
          <h2><span class="settings-icon"><?php echo $icon('<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>'); ?></span>Login credentials</h2>

          <div class="settings-field">
            <label for="currentEmail">Email address</label>
            <div class="settings-row">
              <input type="email" id="currentEmail" value="<?php echo $e($me['email']); ?>" readonly />
              <button type="button" class="filter-button" aria-controls="emailForm" aria-expanded="<?php echo $open === 'email' ? 'true' : 'false'; ?>" onclick="toggleForm('emailForm', this)">Change</button>
            </div>
            <form method="post" id="emailForm" class="settings-subform"<?php echo $open === 'email' ? '' : ' hidden'; ?> novalidate>
              <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
              <input type="hidden" name="action" value="change_email" />
              <div class="settings-grid">
                <div class="adm-field">
                  <label for="new_email">New email</label>
                  <input type="email" id="new_email" name="new_email" class="<?php echo $fieldBad('new_email'); ?>" value="<?php echo $e($old['email'] ?? ''); ?>" maxlength="255" autocomplete="email" required />
                  <?php echo $fieldErr('new_email'); ?>
                </div>
                <div class="adm-field">
                  <label for="email_password">Current password</label>
                  <input type="password" id="email_password" name="email_password" class="<?php echo $fieldBad('email_password'); ?>" autocomplete="current-password" required />
                  <?php echo $fieldErr('email_password'); ?>
                </div>
              </div>
              <div class="adm-modal-actions">
                <button type="button" class="filter-button" onclick="toggleForm('emailForm')">Cancel</button>
                <button type="submit" class="dark-button">Save email</button>
              </div>
            </form>
          </div>

          <div class="settings-field">
            <label for="currentPassword">Password</label>
            <div class="settings-row">
              <input type="password" id="currentPassword" value="passwordhidden" readonly aria-label="Password (hidden)" />
              <button type="button" class="filter-button" aria-controls="passwordForm" aria-expanded="<?php echo $open === 'password' ? 'true' : 'false'; ?>" onclick="toggleForm('passwordForm', this)">Change</button>
            </div>
            <form method="post" id="passwordForm" class="settings-subform"<?php echo $open === 'password' ? '' : ' hidden'; ?> novalidate>
              <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
              <input type="hidden" name="action" value="change_password" />
              <div class="settings-grid three">
                <?php foreach (['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Confirm new password'] as $field => $label): ?>
                <div class="adm-field">
                  <label for="<?php echo $field; ?>"><?php echo $label; ?></label>
                  <input type="password" id="<?php echo $field; ?>" name="<?php echo $field; ?>" class="<?php echo $fieldBad($field); ?>"
                         autocomplete="<?php echo $field === 'current_password' ? 'current-password' : 'new-password'; ?>"<?php echo $field === 'current_password' ? '' : ' minlength="8"'; ?> required />
                  <?php echo $fieldErr($field); ?>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="adm-modal-actions">
                <button type="button" class="filter-button" onclick="toggleForm('passwordForm')">Cancel</button>
                <button type="submit" class="dark-button">Update password</button>
              </div>
            </form>
          </div>

          <p class="settings-note">Signed in as <strong><?php echo $e($me['username']); ?></strong> · <?php echo $e($myAccess['title']); ?></p>
        </section>

        <!-- ── Notification preferences ── -->
        <section class="settings-card">
          <h2><span class="settings-icon"><?php echo $icon(SF_BELL_ICON); ?></span>Notification preferences</h2>
          <p class="settings-sub">Choose what shows up in <a href="admin_notifications.php">Notifications</a> and on the bell. Press Save Changes when you're done.</p>
          <form method="post" id="prefsForm">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
            <input type="hidden" name="action" value="save_prefs" />
            <?php foreach (SF_NOTICE_PREFS as $key => [$label]): ?>
            <label class="settings-toggle<?php echo $key === 'payouts_big' ? ' sub' : ''; ?>" for="pref_<?php echo $key; ?>">
              <span><?php echo $e($label); ?></span>
              <input type="checkbox" class="switch" id="pref_<?php echo $key; ?>" name="pref[<?php echo $key; ?>]" value="1"<?php echo $prefs[$key] ? ' checked' : ''; ?> />
            </label>
            <?php endforeach; ?>
          </form>
        </section>

        <!-- ── Roles & permissions ── -->
        <section class="settings-card">
          <h2><span class="settings-icon"><?php echo $icon($menu['moderation'][2]); ?></span>Roles &amp; permissions</h2>
          <p class="settings-sub">Manage what other admin accounts can access.<?php echo $myAccess['full'] ? '' : ' Only a super admin can change these.'; ?></p>
          <ul class="role-list" id="roleList">
            <?php foreach ($admins as $a):
              $acc  = sf_admin_access_of($a);
              $self = (int) $a['id'] === $adminId;
              $info = ['id' => (int) $a['id'], 'name' => $a['username'], 'title' => $a['admin_title'], 'full' => $acc['full'], 'areas' => $acc['areas']];
            ?>
            <li class="role-row" data-full="<?php echo $acc['full'] ? 1 : 0; ?>">
              <span class="role-who">
                <strong><?php echo $e($a['username']); ?><?php echo $self ? ' (you)' : ''; ?><?php if ($a['status'] === 'suspended'): ?> <span class="status-chip caps suspended">Suspended</span><?php endif; ?></strong>
                <span><?php echo $e($acc['title']); ?> · <?php echo $e($a['email']); ?></span>
              </span>
              <span class="role-access">
                <?php if ($acc['full']): ?>
                <span class="role-chip full">Full access</span>
                <?php elseif ($acc['areas']): ?>
                <span class="role-chip"><?php echo $e(implode(', ', array_map(fn($k) => SF_ADMIN_AREAS[$k], $acc['areas']))); ?></span>
                <?php else: ?>
                <span class="role-chip none">No access yet</span>
                <?php endif; ?>
                <?php if ($myAccess['full'] && !$self): ?>
                <button type="button" class="manage-btn" data-role='<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>' onclick="openRole(this)">Edit</button>
                <?php endif; ?>
              </span>
            </li>
            <?php endforeach; ?>
          </ul>
          <div class="role-footer">
            <button type="button" class="filter-button" id="roleFilter" aria-pressed="false"><?php echo $icon('<path d="M3 4h18l-7 8v8l-4-2v-6Z"/>'); ?><span>Show limited admins only</span></button>
            <?php if ($myAccess['full']): ?><a href="admin_users.php" class="filter-button">+ Add admin in User Management</a><?php endif; ?>
          </div>
        </section>
      </div>

  <?php sf_admin_shell_end(); ?>

  <?php if ($myAccess['full']): ?>
  <!-- ==================== EDIT ROLE MODAL ==================== -->
  <dialog class="adm-modal" id="roleModal" aria-labelledby="roleTitle">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" value="save_role" />
      <input type="hidden" name="id" id="rId" />
      <div class="adm-modal-head">
        <h2 id="roleTitle">Edit access</h2>
        <button type="button" class="adm-modal-close" onclick="document.getElementById('roleModal').close()" aria-label="Close">&times;</button>
      </div>
      <div class="adm-field">
        <label for="rTitle">Role name <span>(optional, e.g. Support Admin)</span></label>
        <input type="text" id="rTitle" name="title" maxlength="50" autocomplete="off" />
      </div>
      <label class="settings-toggle"><span><strong>Full access</strong> (super admin: every page, and can manage other admins)</span><input type="checkbox" class="switch" id="rFull" name="full" value="1" /></label>
      <p class="modal-section-title">Pages they can open</p>
      <div class="area-grid" id="rAreas">
        <?php foreach (SF_ADMIN_AREAS as $key => $label): ?>
        <label><input type="checkbox" name="areas[]" value="<?php echo $key; ?>" /> <?php echo $e($menu[$key][0]); ?></label>
        <?php endforeach; ?>
      </div>
      <p class="adm-field-hint">Dashboard, Notifications and Settings are open to every admin.</p>
      <div class="adm-modal-actions">
        <button type="button" class="filter-button" onclick="document.getElementById('roleModal').close()">Cancel</button>
        <button type="submit" class="export-button">Save access</button>
      </div>
    </form>
  </dialog>
  <?php endif; ?>

  <script>
    function toggleForm(id, button) {
      const form = document.getElementById(id);
      form.hidden = !form.hidden;
      document.querySelector('[aria-controls="' + id + '"]').setAttribute('aria-expanded', String(!form.hidden));
      if (!form.hidden) form.querySelector('input:not([type=hidden])').focus();
    }

    // "Only over ₱10,000" only means something while payout notifications are on
    const payouts = document.getElementById('pref_payouts'), big = document.getElementById('pref_payouts_big');
    const syncBig = () => { big.disabled = !payouts.checked; big.closest('label').classList.toggle('off', !payouts.checked); };
    payouts.addEventListener('change', syncBig); syncBig();

    // Warn before leaving with unsaved switches
    let dirty = false;
    document.getElementById('prefsForm').addEventListener('change', () => { dirty = true; });
    document.getElementById('prefsForm').addEventListener('submit', () => { dirty = false; big.disabled = false; });
    window.addEventListener('beforeunload', ev => { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });

    // Roles list filter
    document.getElementById('roleFilter').addEventListener('click', e => {
      const on = e.currentTarget.getAttribute('aria-pressed') !== 'true';
      e.currentTarget.setAttribute('aria-pressed', String(on));
      e.currentTarget.querySelector('span').textContent = on ? 'Show all admins' : 'Show limited admins only';
      document.querySelectorAll('.role-row').forEach(r => { r.hidden = on && r.dataset.full === '1'; });
    });

    // Edit access
    const rFull = document.getElementById('rFull');
    function syncAreas() { document.querySelectorAll('#rAreas input').forEach(i => { i.disabled = rFull.checked; if (rFull.checked) i.checked = true; }); }
    if (rFull) rFull.addEventListener('change', syncAreas);
    function openRole(button) {
      const r = JSON.parse(button.dataset.role);
      document.getElementById('roleTitle').textContent = 'Edit access: ' + r.name;
      document.getElementById('rId').value = r.id;
      document.getElementById('rTitle').value = r.title;
      rFull.checked = r.full;
      document.querySelectorAll('#rAreas input').forEach(i => { i.checked = r.areas.includes(i.value); });
      syncAreas();
      document.getElementById('roleModal').showModal();
    }
  </script>
</body>
</html>
