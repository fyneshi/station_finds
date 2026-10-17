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
require_once 'seller.php';   // listing photos (Delete Account)
require_once 'admin_layout.php';
sf_admin_require($conn, 'users');

$adminId = (int) $_SESSION['user_id'];
$tabs    = ['all' => 'All', 'buyers' => 'Buyers', 'sellers' => 'Sellers', 'suspended' => 'Suspended'];
$tab     = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$addErrors = [];      // Add User modal re-opens with these
$addValues = ['full_name' => '', 'email' => '', 'role' => 'user', 'status' => 'active'];

function back($tab, $type, $message) {
    $_SESSION['flash'] = [$type, $message];
    header('Location: admin_users.php?tab=' . $tab);
    exit;
}

$editErrors = [];     // Manage User modal re-opens in edit mode with these
$editValues = null;

// A temporary password to pass on privately (no look-alike characters)
function temp_password() {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < 10; $i++) $password .= $chars[random_int(0, strlen($chars) - 1)];
    return $password;
}

// The account being edited or deleted, or back to the list with a message
function target_user($conn, $tab, $id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) back($tab, 'err', 'That user could not be found.');
    return $user;
}

// ── Suspend / reactivate / add / edit / delete ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];

    } elseif ($action === 'edit_user') {
        $target = target_user($conn, $tab, (int) ($_POST['id'] ?? 0));
        $isSelf = (int) $target['id'] === $adminId;
        $super  = sf_admin_access($conn)['full'];
        // Admin accounts are a super admin's to change (anyone can fix their own)
        if ($target['role'] === 'admin' && !$isSelf && !$super) back($tab, 'err', 'Only a super admin can edit other admin accounts.');
        $editValues = [
            'id'        => (int) $target['id'],
            'full_name' => trim(preg_replace('/\s+/', ' ', (string) ($_POST['full_name'] ?? ''))),
            'email'     => trim((string) ($_POST['email'] ?? '')),
            // Role: only a super admin, and never on their own account. Status: not for admins.
            'role'      => $super && !$isSelf && in_array($_POST['role'] ?? '', ['user', 'admin'], true) ? $_POST['role'] : $target['role'],
            'status'    => $target['role'] !== 'admin' && ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : ($target['role'] === 'admin' ? $target['status'] : 'active'),
        ];
        if ($editValues['role'] === 'admin') $editValues['status'] = 'active';
        if (mb_strlen($editValues['full_name']) > 100) $editErrors['full_name'] = 'Up to 100 characters.';
        if (!filter_var($editValues['email'], FILTER_VALIDATE_EMAIL) || strlen($editValues['email']) > 255) $editErrors['email'] = 'Enter a valid email address.';
        if (!$editErrors) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
            $stmt->bind_param("si", $editValues['email'], $editValues['id']);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $editErrors['email'] = 'That email already has an account.';
            $stmt->close();
        }
        if (!$editErrors) {
            // Someone made an admin starts with no pages (Settings › Roles & permissions)
            $access = $editValues['role'] === 'admin' ? ($target['role'] === 'admin' ? $target['admin_access'] : '') : null;
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, status = ?, admin_access = ? WHERE id = ?");
            $stmt->bind_param("sssssi", $editValues['full_name'], $editValues['email'], $editValues['role'], $editValues['status'], $access, $editValues['id']);
            $stmt->execute();
            $stmt->close();

            $who = $target['username'];
            if ($isSelf) {
                $_SESSION['email'] = $editValues['email'];
            } elseif (strcasecmp($target['email'], $editValues['email']) !== 0) {
                sf_notify($conn, $editValues['id'], 'security', 'Your login email was changed',
                    'An admin changed your login email from ' . $target['email'] . ' to ' . $editValues['email'] . '. Contact support@stationfinds.edu.ph if you didn\'t ask for this.');
            }
            if ($editValues['status'] !== $target['status']) {
                if ($editValues['status'] === 'active') sf_notify($conn, $editValues['id'], 'security', 'Account reactivated', 'Your Station Finds account is active again. Welcome back!');
                sf_admin_log($conn, 'user', $editValues['status'] === 'suspended' ? 'Suspended user account' : 'Reactivated user account', $who, $editValues['status'], $editValues['status'] === 'suspended');
            }
            if ($editValues['role'] !== $target['role']) {
                sf_admin_log($conn, 'user', $editValues['role'] === 'admin' ? 'Made user an admin' : 'Removed admin role', $who, 'edited', true);
            }
            sf_admin_log($conn, 'user', 'Edited user details', $who, 'edited');
            back($tab, 'ok', $who . '\'s details were updated.'
                . ($editValues['role'] === 'admin' && $target['role'] !== 'admin' ? ' Choose what they can access in Settings › Roles & permissions.' : '')
                . ($editValues['status'] === 'suspended' && $target['status'] !== 'suspended' ? ' They are logged out and their listings are hidden.' : ''));
        }

    } elseif ($action === 'reset_password') {
        // For students who used "Forgot password" (it asks the admins, see auth_handler.php)
        $target = target_user($conn, $tab, (int) ($_POST['id'] ?? 0));
        if ((int) $target['id'] === $adminId) back($tab, 'err', 'Change your own password in Settings › Login credentials.');
        if ($target['role'] === 'admin' && !sf_admin_access($conn)['full']) back($tab, 'err', 'Only a super admin can reset another admin\'s password.');
        $password = temp_password();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $target['id']);
        $stmt->execute();
        $stmt->close();
        // Their reset request(s) in Feedback are now handled
        $stmt = $conn->prepare("UPDATE feedback SET status = 'resolved', resolved_at = NOW()
                                WHERE user_id = ? AND subject = 'Password reset request' AND status IN ('pending', 'escalated')");
        $stmt->bind_param("i", $target['id']);
        $stmt->execute();
        $stmt->close();
        sf_notify($conn, (int) $target['id'], 'security', 'Your password was reset',
            'A Station Finds admin reset your password. Log in with the temporary password you were given, then set your own under Account › Login Credentials.', 'account.php?tab=credentials');
        sf_admin_log($conn, 'user', 'Reset user password', $target['username'], 'edited', true);
        back($tab, 'ok', $target['username'] . '\'s password was reset. Their temporary password is ' . $password
            . ' (shown only once: give it to them privately, e.g. in person, and ask them to change it under Account › Login Credentials).');

    } elseif ($action === 'delete_user') {
        $target = target_user($conn, $tab, (int) ($_POST['id'] ?? 0));
        if ($target['role'] === 'admin') back($tab, 'err', 'Admin accounts can\'t be deleted here.');
        // Orders keep their history, so accounts that bought or sold stay (suspend them instead)
        $id   = (int) $target['id'];
        $busy = $conn->query("SELECT
            (SELECT COUNT(*) FROM orders WHERE user_id = $id) AS bought,
            (SELECT COUNT(*) FROM order_items oi JOIN listings l ON oi.item_key = CONCAT('listing-', l.id) WHERE l.seller_id = $id) AS sold")->fetch_assoc();
        if ($busy['bought'] > 0 || $busy['sold'] > 0) {
            back($tab, 'err', $target['username'] . ' has orders on record, so the account can\'t be deleted. Suspend it instead.');
        }
        // Their listings go with them (photos, and copies in other people's carts and saved items)
        $listings = $conn->query("SELECT * FROM listings WHERE seller_id = $id")->fetch_all(MYSQLI_ASSOC);
        foreach ($listings as $l) {
            foreach (sf_listing_photos($l) as $photo) sf_delete_listing_photo($photo);
            $key = sf_listing_key($l['id']);
            foreach (['cart_items', 'favorites'] as $table) {
                $stmt = $conn->prepare("DELETE FROM `$table` WHERE item_key = ?");
                $stmt->bind_param("s", $key);
                $stmt->execute();
                $stmt->close();
            }
        }
        $conn->query("DELETE FROM cart_items WHERE user_id = $id");
        $conn->query("DELETE FROM favorites WHERE user_id = $id");
        $store = sf_seller_profile($conn, $id);
        if ($store) sf_delete_id_document($store['id_document']);
        sf_delete_avatar($target['avatar'] ?? '');
        $conn->query("DELETE FROM users WHERE id = $id");   // listings, store, messages, notifications… cascade
        sf_admin_log($conn, 'user', 'Deleted user account', $target['username'] . ' · ' . $target['email'], 'deleted', true);
        back($tab, 'ok', $target['username'] . '\'s account was permanently deleted.');

    } elseif ($action === 'suspend' || $action === 'reactivate') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, username, role, status FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) back($tab, 'err', 'That user could not be found.');
        if ($target['role'] === 'admin') back($tab, 'err', 'Admin accounts can\'t be suspended here.');

        $status = $action === 'suspend' ? 'suspended' : 'active';
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        $stmt->close();
        sf_admin_log($conn, 'user', $status === 'suspended' ? 'Suspended user account' : 'Reactivated user account',
            $target['username'], $status, $status === 'suspended');
        if ($status === 'active') {
            sf_notify($conn, $id, 'security', 'Account reactivated', 'Your Station Finds account is active again. Welcome back!');
        }
        back($tab, 'ok', $target['username'] . ($status === 'suspended'
            ? ' was suspended. They are logged out, can\'t log in, and their listings are hidden.'
            : ' was reactivated.'));

    } elseif ($action === 'add_user') {
        $addValues = [
            'full_name' => trim(preg_replace('/\s+/', ' ', (string) ($_POST['full_name'] ?? ''))),
            'email'     => trim((string) ($_POST['email'] ?? '')),
            'role'      => ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user',
            'status'    => ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : 'active',
        ];
        if (!preg_match('/^[\p{L}\p{N} .\'-]{2,100}$/u', $addValues['full_name'])) $addErrors['full_name'] = 'Enter their full name (2–100 letters).';
        if (!filter_var($addValues['email'], FILTER_VALIDATE_EMAIL) || strlen($addValues['email']) > 255) $addErrors['email'] = 'Enter a valid email address.';
        // Only a super admin can make admins (otherwise a limited admin could give themselves full access)
        if ($addValues['role'] === 'admin' && !sf_admin_access($conn)['full']) $addErrors['role'] = 'Only a super admin can create admin accounts.';
        if (!$addErrors) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $addValues['email']);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $addErrors['email'] = 'That email already has an account.';
            $stmt->close();
        }
        if (!$addErrors) {
            // Username: their name (or the start of their email), numbered if it's taken
            $base = preg_replace('/[^\p{L}\p{N} ._-]/u', '', $addValues['full_name']);
            if (mb_strlen($base) < 3) $base = preg_replace('/[^\p{L}\p{N}._-]/u', '', strstr($addValues['email'], '@', true));
            $base = mb_substr(str_pad($base, 3, '0'), 0, 45);
            $username = $base;
            $check = $conn->prepare("SELECT 1 FROM users WHERE username = ?");
            for ($n = 2; ; $n++) {
                $check->bind_param("s", $username);
                $check->execute();
                if ($check->get_result()->num_rows === 0) break;
                $username = $base . ' ' . $n;
            }
            $check->close();

            // Temporary password, shown once to the admin to pass on (no look-alike characters)
            $password = temp_password();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // New admins start with no areas; a super admin picks them in Settings › Roles & permissions
            $access = $addValues['role'] === 'admin' ? '' : null;
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, status, admin_access) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $username, $addValues['full_name'], $addValues['email'], $hash, $addValues['role'], $addValues['status'], $access);
            $stmt->execute();
            $stmt->close();
            sf_admin_log($conn, 'user', $addValues['role'] === 'admin' ? 'Created admin account' : 'Created user account',
                $username, $addValues['status'] === 'suspended' ? 'suspended' : 'added', $addValues['role'] === 'admin');
            back($tab, 'ok', ($addValues['role'] === 'admin' ? 'Admin' : 'User') . ' account "' . $username . '" was created'
                . ($addValues['status'] === 'suspended' ? ' as suspended' : '') . '. They log in with ' . $addValues['email']
                . ' and the temporary password ' . $password . ' (shown only once: share it privately and ask them to change it in their account settings).'
                . ($addValues['role'] === 'admin' ? ' Choose what they can access in Settings › Roles & permissions.' : ''));
        }
    }
}

// ── Everyone, with what they do on the marketplace ──────────────
$users = $conn->query("SELECT u.id, u.username, u.full_name, u.email, u.role, u.status, UNIX_TIMESTAMP(u.created_at) AS joined,
           UNIX_TIMESTAMP(u.last_login_at) AS last_login, u.last_login_ip,
           s.store_name,
           (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS orders,
           (SELECT COUNT(*) FROM listings l WHERE l.seller_id = u.id) AS listings,
           (SELECT COUNT(*) FROM order_items oi JOIN listings l ON oi.item_key = CONCAT('listing-', l.id) WHERE l.seller_id = u.id) AS sold,
           (SELECT ROUND(AVG(f.rating), 1) FROM feedback f WHERE f.about_user_id = u.id AND f.rating IS NOT NULL AND f.status NOT IN ('flagged', 'dismissed')) AS rating,
           (SELECT COUNT(*) FROM feedback f WHERE f.about_user_id = u.id AND f.rating IS NOT NULL AND f.status NOT IN ('flagged', 'dismissed')) AS ratings,
           (SELECT COUNT(*) FROM reports r WHERE r.target_type = 'user' AND r.target_id = u.id) AS reported
    FROM users u LEFT JOIN seller_profiles s ON s.user_id = u.id
    ORDER BY u.created_at DESC, u.id DESC")->fetch_all(MYSQLI_ASSOC);

function role_label(array $u) {
    if ($u['role'] === 'admin') return 'Admin';
    if ($u['store_name'] !== null) return $u['orders'] > 0 ? 'Buyer & Seller' : 'Seller';
    return 'Buyer';
}

$members   = array_filter($users, fn($u) => $u['role'] !== 'admin');
$monthStart = strtotime(date('Y-m-01'));
$lastStart  = strtotime(date('Y-m-01', strtotime('first day of last month')));
$stats = [
    'total'     => count($members),
    'new'       => count(array_filter($members, fn($u) => $u['joined'] >= $monthStart)),
    'new_last'  => count(array_filter($members, fn($u) => $u['joined'] >= $lastStart && $u['joined'] < $monthStart)),
    'active'    => count(array_filter($members, fn($u) => $u['status'] === 'active')),
    'suspended' => count(array_filter($members, fn($u) => $u['status'] === 'suspended')),
];
$activePct = $stats['total'] ? round($stats['active'] / $stats['total'] * 100, 1) : 0;
$newTrend  = $stats['new_last'] > 0
    ? (($stats['new'] >= $stats['new_last'] ? '+' : '') . round(($stats['new'] - $stats['new_last']) / $stats['new_last'] * 100) . '% vs last month')
    : ($stats['new'] ? 'All new this month' : 'None yet this month');

$tabCounts = [
    'all'       => count($users),
    'buyers'    => count(array_filter($members, fn($u) => $u['store_name'] === null)),
    'sellers'   => count(array_filter($members, fn($u) => $u['store_name'] !== null)),
    'suspended' => $stats['suspended'],
];
$shown = array_filter($users, function ($u) use ($tab) {
    switch ($tab) {
        case 'buyers':    return $u['role'] !== 'admin' && $u['store_name'] === null;
        case 'sellers':   return $u['role'] !== 'admin' && $u['store_name'] !== null;
        case 'suspended': return $u['status'] === 'suspended';
        default:          return true;
    }
});

$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = sf_csrf_token();
$initials = fn($u) => $u['full_name'] !== '' ? sf_initials($u['full_name']) : strtoupper(mb_substr($u['username'], 0, 2));
$icon = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>User Management — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="CSS/admin.css?v=23" />
  <link rel="stylesheet" href="CSS/admin-shell.css?v=2" />
</head>
<body>

  <?php sf_admin_shell_start('users', 'Search users by name or email…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>User Management</h1>
          <p>View, edit, and manage all marketplace users</p>
        </div>
        <div class="header-actions">
          <button type="button" class="filter-button" id="filterToggle" aria-expanded="false" aria-controls="userFilters"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v8l-4-2v-6Z"/></svg>Filters</button>
          <button type="button" class="export-button" onclick="openModal('addUserModal')">+ Add User</button>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <div class="filter-panel" id="userFilters" hidden>
          <label for="sortUsers">Sort by</label>
          <select id="sortUsers">
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
            <option value="name">Name (A–Z)</option>
          </select>
        </div>

        <section class="stats-grid">
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Total users</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> +<?php echo $stats['new']; ?> this month</p>
          </div>
          <div class="stat-card">
            <div class="stat-card-top"><span class="stat-label">New this month</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['new']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo $e($newTrend); ?></p>
          </div>
          <div class="stat-card revenue-card">
            <div class="stat-card-top"><span class="stat-label">Active</span><div class="stat-icon-wrapper"><?php echo $icon('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
            <p class="stat-trend"><span class="trend-chip">↗</span> <?php echo $activePct; ?>% of total users</p>
          </div>
          <a href="admin_users.php?tab=suspended" class="stat-card">
            <div class="stat-card-top"><span class="stat-label">Suspended</span><div class="stat-icon-wrapper pending-icon"><?php echo $icon('<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'); ?></div></div>
            <div class="stat-value"><?php echo number_format($stats['suspended']); ?></div>
            <p class="stat-trend<?php echo $stats['suspended'] ? ' attention' : ''; ?>"><?php echo $stats['suspended'] ? '⊙ Needs attention' : 'None suspended'; ?></p>
          </a>
        </section>

        <nav class="users-tabs" aria-label="Filter users">
          <?php foreach ($tabs as $key => $label): ?>
          <a href="admin_users.php?tab=<?php echo $key; ?>" class="users-tab<?php echo $tab === $key ? ' active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo $label; ?> <span><?php echo $tabCounts[$key]; ?></span></a>
          <?php endforeach; ?>
        </nav>

        <section class="users-card">
          <div class="table-scroll">
            <table class="users-table">
              <thead><tr><th>Name</th><th>Role</th><th>Joined</th><th>Status</th><th class="col-action">Action</th></tr></thead>
              <tbody id="usersBody">
                <?php foreach ($shown as $u):
                  $name = $u['full_name'] !== '' ? $u['full_name'] : $u['username'];
                  // System log: last login, then what they do on the marketplace
                  $login = $u['last_login']
                      ? 'Last login ' . (date('Y-m-d', $u['last_login']) === date('Y-m-d') ? 'today' : (date('Y-m-d', $u['last_login']) === date('Y-m-d', strtotime('yesterday')) ? 'yesterday' : date('M j, Y', $u['last_login'])))
                        . ' at ' . date('g:i A', $u['last_login']) . ($u['last_login_ip'] !== '' ? ' from IP ' . $u['last_login_ip'] : '') . '.'
                      : 'No login recorded yet.';
                  $activity = $u['role'] === 'admin' ? 'Admin account.' : implode(' · ', array_filter([
                      $u['orders'] . ' order' . ($u['orders'] == 1 ? '' : 's') . ' placed',
                      $u['store_name'] !== null ? $u['listings'] . ' listing' . ($u['listings'] == 1 ? '' : 's') . ', ' . $u['sold'] . ' sold' : null,
                      $u['ratings'] ? 'rated ' . $u['rating'] . '/5 by ' . $u['ratings'] : null,
                      $u['reported'] ? 'reported ' . $u['reported'] . ' time' . ($u['reported'] == 1 ? '' : 's') : null,
                  ])) . '.';
                  $isAdminRow = $u['role'] === 'admin';
                  $info = [
                      'id' => (int) $u['id'], 'name' => $name, 'fullName' => $u['full_name'], 'username' => $u['username'], 'email' => $u['email'],
                      'role' => role_label($u), 'status' => $u['status'], 'joined' => date('M j, Y', $u['joined']),
                      'log' => [$login, ucfirst($activity)], 'initials' => $initials($u),
                      'isAdmin' => $isAdminRow, 'isYou' => (int) $u['id'] === $adminId,
                      'canEdit' => !$isAdminRow || (int) $u['id'] === $adminId || sf_admin_access($conn)['full'],
                      'deleteBlock' => $isAdminRow ? 'Admin accounts can\'t be deleted here.'
                          : ($u['orders'] > 0 || $u['sold'] > 0 ? 'Has orders on record, so it can\'t be deleted. Suspend it instead.' : ''),
                  ];
                ?>
                <tr data-name="<?php echo $e(mb_strtolower($name . ' ' . $u['username'] . ' ' . $u['email'])); ?>" data-joined="<?php echo (int) $u['joined']; ?>" data-sort-name="<?php echo $e(mb_strtolower($name)); ?>">
                  <td>
                    <div class="user-cell">
                      <span class="user-avatar<?php echo $u['status'] === 'suspended' ? ' muted' : ''; ?>"><?php echo $e($initials($u)); ?></span>
                      <span><strong><?php echo $e($name); ?></strong><span><?php echo $e($u['email']); ?></span></span>
                    </div>
                  </td>
                  <td><?php echo $e(role_label($u)); ?></td>
                  <td><?php echo date('M j, Y', $u['joined']); ?></td>
                  <td><span class="status-chip <?php echo $u['status'] === 'suspended' ? 'suspended' : 'active'; ?>"><?php echo $u['status'] === 'suspended' ? 'Suspended' : 'Active'; ?></span></td>
                  <td class="col-action"><button type="button" class="manage-btn" onclick='openManage(<?php echo json_encode($info, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Manage</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="empty-state" id="emptyUsers"<?php echo $shown ? ' hidden' : ''; ?>><?php echo $shown ? 'No users match your search.' : 'No ' . strtolower($tabs[$tab]) . ' users.'; ?></p>
        </section>
      </div>

  <!-- ==================== MANAGE USER MODAL ==================== -->
  <dialog class="adm-modal card-modal" id="manageModal" aria-labelledby="manageHeading">
    <div class="card-modal-head">
      <h2 id="manageHeading">Manage User</h2>
      <button type="button" class="adm-modal-close" onclick="closeModal('manageModal')" aria-label="Close">&times;</button>
    </div>

    <div id="mView">
      <div class="user-cell card-modal-who">
        <span class="user-avatar soft" id="mAvatar"></span>
        <span><strong id="manageTitle"></strong><span id="mEmail"></span></span>
      </div>
      <dl class="card-facts">
        <div><dt>Role</dt><dd id="mRole"></dd></div>
        <div><dt>Joined</dt><dd id="mJoined"></dd></div>
        <div><dt>Status</dt><dd><span class="review-status" id="mStatus"></span></dd></div>
      </dl>
      <div class="review-preview system-log">
        <p class="review-preview-label">System log</p>
        <p id="mLog"></p>
      </div>
      <form method="post" id="mForm">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
        <input type="hidden" name="id" id="mId" />
        <div class="card-modal-actions">
          <button type="submit" name="action" id="mAction" class="filter-button"></button>
          <button type="button" class="export-button" id="mEditBtn" onclick="showEdit(true)">Edit User</button>
        </div>
        <button type="submit" name="action" value="reset_password" class="reset-link" id="mReset">Reset password</button>
        <button type="submit" name="action" value="delete_user" class="danger-link" id="mDelete">Delete Account Permanently</button>
        <p class="card-modal-note" id="mNote"></p>
      </form>
    </div>

    <form method="post" id="mEditForm" hidden novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" value="edit_user" />
      <input type="hidden" name="id" id="eId" value="<?php echo $editValues ? (int) $editValues['id'] : ''; ?>" />
      <div class="editing-box">
        <span class="user-avatar small-yellow" id="eAvatar"></span>
        <span><span>Currently Editing</span><strong id="eCurrent"></strong></span>
      </div>
      <?php foreach (['full_name' => 'Full Name', 'email' => 'Email Address'] as $key => $label): ?>
      <div class="adm-field">
        <label for="e_<?php echo $key; ?>"><?php echo $label; ?></label>
        <input type="<?php echo $key === 'email' ? 'email' : 'text'; ?>" id="e_<?php echo $key; ?>" name="<?php echo $key; ?>" maxlength="<?php echo $key === 'email' ? 255 : 100; ?>" autocomplete="off"
               value="<?php echo $e($editValues[$key] ?? ''); ?>"<?php echo isset($editErrors[$key]) ? ' class="has-error"' : ''; ?> />
        <?php if (isset($editErrors[$key])): ?><span class="adm-field-error"><?php echo $e($editErrors[$key]); ?></span><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div class="adm-field">
        <label for="e_role">Role</label>
        <select id="e_role" name="role">
          <option value="user" id="e_role_user">Buyer</option>
          <option value="admin">Admin</option>
        </select>
        <span class="adm-field-hint" id="e_role_hint"></span>
      </div>
      <div class="adm-field">
        <label for="e_status">Status</label>
        <select id="e_status" name="status" class="status-select">
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
        </select>
      </div>
      <div class="card-modal-actions">
        <button type="button" class="filter-button" onclick="showEdit(false)">Cancel</button>
        <button type="submit" class="export-button">Save Changes</button>
      </div>
    </form>
  </dialog>

  <!-- ==================== ADD USER MODAL ==================== -->
  <dialog class="adm-modal" id="addUserModal" aria-labelledby="addUserTitle">
    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
      <input type="hidden" name="action" value="add_user" />
      <div class="adm-modal-head">
        <h2 id="addUserTitle">+ Add user</h2>
        <button type="button" class="adm-modal-close" onclick="closeModal('addUserModal')" aria-label="Close">&times;</button>
      </div>
      <?php
        $field = function ($name, $label, $type, $extra = '') use ($addErrors, $addValues, $e) {
            echo '<div class="adm-field"><label for="add_' . $name . '">' . $label . '</label>'
               . '<input type="' . $type . '" id="add_' . $name . '" name="' . $name . '" value="' . $e($addValues[$name] ?? '') . '"' . $extra
               . (isset($addErrors[$name]) ? ' class="has-error"' : '') . ' />'
               . (isset($addErrors[$name]) ? '<span class="adm-field-error">' . $e($addErrors[$name]) . '</span>' : '') . '</div>';
        };
        $field('full_name', 'Full name', 'text', ' maxlength="100" autocomplete="off" placeholder="e.g. Juan dela Cruz" required');
        $field('email', 'Email address', 'email', ' maxlength="255" autocomplete="off" placeholder="e.g. juan@student.edu" required');
      ?>
      <div class="adm-field">
        <label for="add_role">Role</label>
        <select id="add_role" name="role">
          <option value="user"<?php echo $addValues['role'] === 'user' ? ' selected' : ''; ?>>Buyer</option>
          <?php if (sf_admin_access($conn)['full']): ?>
          <option value="admin"<?php echo $addValues['role'] === 'admin' ? ' selected' : ''; ?>>Admin</option>
          <?php endif; ?>
        </select>
        <?php if (isset($addErrors['role'])): ?><span class="adm-field-error"><?php echo $e($addErrors['role']); ?></span><?php endif; ?>
      </div>
      <div class="adm-field">
        <label for="add_status">Status</label>
        <select id="add_status" name="status">
          <option value="active"<?php echo $addValues['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
          <option value="suspended"<?php echo $addValues['status'] === 'suspended' ? ' selected' : ''; ?>>Suspended</option>
        </select>
      </div>
      <p class="adm-field-hint">A username and temporary password are made for them; you'll see the password once after you add the user. Buyers can apply to sell from their account.</p>
      <div class="adm-modal-actions add-user-actions">
        <button type="button" class="filter-button" onclick="closeModal('addUserModal')">Cancel</button>
        <button type="submit" class="export-button">Add User</button>
      </div>
    </form>
  </dialog>

  <?php sf_admin_shell_end(); ?>

  <script>
    function openModal(id) { document.getElementById(id).showModal(); }
    function closeModal(id) { document.getElementById(id).close(); }
    document.querySelectorAll('.adm-modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.close(); }));

    // Manage: fill the modal with this user's details and the right action
    function openManage(u) {
      const set = (id, text) => { document.getElementById(id).textContent = text; };
      set('mAvatar', u.initials);
      set('manageTitle', u.name);
      set('mEmail', u.email);
      set('mRole', u.role);
      set('mJoined', u.joined);
      const status = document.getElementById('mStatus');
      status.textContent = u.status === 'suspended' ? 'Suspended' : 'Active';
      status.className = 'review-status ' + (u.status === 'suspended' ? 'flagged' : 'resolved');
      set('mLog', u.log.join(' '));
      document.getElementById('mId').value = u.id;

      // Suspend / Reactivate (not for admins), Edit, Delete (only accounts without orders)
      const action = document.getElementById('mAction');
      action.hidden = u.isAdmin;
      action.value = u.status === 'suspended' ? 'reactivate' : 'suspend';
      action.textContent = u.status === 'suspended' ? 'Reactivate User' : 'Suspend User';
      document.getElementById('mEditBtn').hidden = !u.canEdit;
      document.getElementById('mDelete').hidden = !!u.deleteBlock;
      document.getElementById('mReset').hidden = u.isYou || (u.isAdmin && !SUPER);
      set('mNote', u.deleteBlock ? (u.isYou ? 'This is your account.' : u.deleteBlock) : '');

      // Edit form starts from their current details
      fillUserEdit({ id: u.id, name: u.name, initials: u.initials, fullName: u.fullName, email: u.email,
                     role: u.isAdmin ? 'admin' : 'user', roleLabel: u.isAdmin ? 'Buyer' : u.role, status: u.status, isYou: u.isYou });
      document.querySelectorAll('#mEditForm .adm-field-error').forEach(el => el.remove());
      document.querySelectorAll('#mEditForm .has-error').forEach(el => el.classList.remove('has-error'));
      showEdit(false);
      openModal('manageModal');
    }

    // Role: only a super admin can change it, and not on their own account. Status: not for admins.
    const SUPER = <?php echo json_encode(sf_admin_access($conn)['full']); ?>;
    function fillUserEdit(v) {
      document.getElementById('eId').value = v.id;
      document.getElementById('eAvatar').textContent = v.initials;
      document.getElementById('eCurrent').textContent = v.name;
      if (v.fullName !== undefined) document.getElementById('e_full_name').value = v.fullName;
      if (v.email !== undefined) document.getElementById('e_email').value = v.email;
      const role = document.getElementById('e_role');
      document.getElementById('e_role_user').textContent = v.roleLabel;
      role.value = v.role;
      role.disabled = !SUPER || v.isYou;
      document.getElementById('e_role_hint').textContent = v.isYou ? 'You can\'t change your own role.'
        : !SUPER ? 'Only a super admin can change roles.'
        : 'Buyers become sellers by applying with Become a Seller. New admins start with no pages until you pick them in Settings.';
      const status = document.getElementById('e_status');
      status.value = v.status === 'suspended' ? 'suspended' : 'active';
      status.disabled = v.role === 'admin';
      tintStatus();
    }
    function tintStatus() {
      const status = document.getElementById('e_status');
      status.className = 'status-select ' + (status.value === 'suspended' ? 'flagged' : 'resolved');
    }
    document.getElementById('e_status').addEventListener('change', tintStatus);
    document.getElementById('e_role').addEventListener('change', e => {
      // Admin accounts can't be suspended
      const status = document.getElementById('e_status');
      status.disabled = e.target.value === 'admin';
      if (status.disabled) { status.value = 'active'; tintStatus(); }
    });
    function showEdit(on) {
      document.getElementById('mView').hidden = on;
      document.getElementById('mEditForm').hidden = !on;
      document.getElementById('manageHeading').textContent = on ? 'Edit User' : 'Manage User';
      if (on) document.getElementById('e_full_name').focus();
    }
    document.getElementById('mForm').addEventListener('submit', e => {
      const action = e.submitter ? e.submitter.value : '';
      if (action === 'suspend' && !confirm('Suspend this account? They are logged out and their listings are hidden.')) e.preventDefault();
      if (action === 'reset_password' && !confirm('Reset this password? Their current password stops working, and you\'ll get a temporary one to give them.')) e.preventDefault();
      if (action === 'delete_user' && !confirm('Delete this account permanently? Their store, listings, messages and notifications are removed. This cannot be undone.')) e.preventDefault();
    });
    <?php if ($editErrors): ?>
    <?php $editTarget = $editValues ? (array_values(array_filter($users, fn($u) => (int) $u['id'] === $editValues['id']))[0] ?? null) : null; ?>
    <?php if ($editTarget): ?>
    fillUserEdit(<?php echo json_encode(['id' => $editValues['id'], 'name' => $editTarget['full_name'] !== '' ? $editTarget['full_name'] : $editTarget['username'], 'initials' => $initials($editTarget), 'role' => $editValues['role'], 'roleLabel' => $editTarget['role'] === 'admin' ? 'Buyer' : role_label($editTarget), 'status' => $editValues['status'], 'isYou' => $editValues['id'] === $adminId], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    <?php endif; ?>
    { showEdit(true); openModal('manageModal'); }   // show what needs fixing
    <?php endif; ?>

    // Search (top bar) and sort (Filters)
    const rows = [...document.querySelectorAll('#usersBody tr')];
    window.onAdminSearch = q => {
      rows.forEach(r => { r.hidden = !r.dataset.name.includes(q); });
      if (rows.length) document.getElementById('emptyUsers').hidden = rows.some(r => !r.hidden);
    };
    document.getElementById('filterToggle').addEventListener('click', e => {
      const panel = document.getElementById('userFilters');
      panel.hidden = !panel.hidden;
      e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });
    document.getElementById('sortUsers').addEventListener('change', e => {
      const by = e.target.value;
      rows.sort((a, b) => by === 'name' ? a.dataset.sortName.localeCompare(b.dataset.sortName)
        : by === 'oldest' ? a.dataset.joined - b.dataset.joined : b.dataset.joined - a.dataset.joined);
      const body = document.getElementById('usersBody');
      rows.forEach(r => body.appendChild(r));
    });

    <?php if ($addErrors): ?>
    openModal('addUserModal');   // show what needs fixing
    <?php endif; ?>
  </script>
</body>
</html>
