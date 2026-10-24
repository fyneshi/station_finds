<?php
/**
 * Shared sidebar + top bar for the admin pages (admin_dashboard.php, admin_users.php, admin_listings.php, admin_sellers.php, admin_moderation.php, admin_transactions.php, admin_reports.php,
 * admin_payouts.php, admin_categories.php, admin_promos.php, admin_feedback.php, admin_logs.php, admin_notifications.php). Styles: css/admin-shell.css.
 * Needs db_setup.php included first ($conn).
 *
 *   sf_admin_shell_start('users', 'Search users…');   // after <body>
 *   ...page content...
 *   sf_admin_shell_end();                              // before </body>
 *
 * Pages with a search box handle typing by defining  window.onAdminSearch = q => { ... }.
 */

// Revenue as a SQL expression: order totals minus items the buyer cancelled or an admin refunded;
// an order where every item was cancelled or refunded counts as nothing (shipping and tax included).
// $where filters the orders, written with o.created_at etc., e.g. "o.created_at >= '2026-10-01'".
function sf_net_sales_sql($where = '1 = 1') {
    return "(SELECT COALESCE(SUM(o.total - COALESCE(c.closed, 0)), 0)
             FROM orders o
             LEFT JOIN (SELECT order_id, SUM(line_total) AS closed, COUNT(*) AS n FROM order_items
                        WHERE fulfillment IN ('cancelled', 'refunded') GROUP BY order_id) c ON c.order_id = o.id
             LEFT JOIN (SELECT order_id, COUNT(*) AS n FROM order_items GROUP BY order_id) a ON a.order_id = o.id
             WHERE (c.n IS NULL OR c.n < a.n) AND ($where))";
}

// Things waiting for an admin (shown as counts in the sidebar and on the bell)
function sf_admin_counts($conn) {
    $row = $conn->query("SELECT
        (SELECT COUNT(*) FROM payout_requests WHERE status IN ('pending', 'flagged')) AS payouts,
        (SELECT COUNT(*) FROM feedback WHERE status IN ('pending', 'escalated')) AS feedback,
        (SELECT COUNT(*) FROM listings WHERE status = 'pending') AS listings,
        (SELECT COUNT(*) FROM seller_profiles WHERE status = 'pending') AS seller_apps,
        (SELECT COUNT(*) FROM reports WHERE status IN ('open', 'escalated')) AS reports,
        (SELECT COUNT(DISTINCT order_id) FROM order_items WHERE dispute_status = 'open') AS disputes")->fetch_assoc();
    return ['payouts' => (int) $row['payouts'], 'feedback' => (int) $row['feedback'],
            'listings' => (int) $row['listings'], 'seller-apps' => (int) $row['seller_apps'],
            'moderation' => (int) $row['reports'], 'transactions' => (int) $row['disputes']];
}

// ── Roles & permissions (set in admin_settings.php) ─────────────
// Areas an admin can be given, by sidebar key. Dashboard, Notifications and Settings are open to every admin.
const SF_ADMIN_AREAS = [
    'users'        => 'Users',
    'listings'     => 'Listings',
    'seller-apps'  => 'Seller apps',
    'moderation'   => 'Moderation',
    'feedback'     => 'Feedback',
    'transactions' => 'Transactions',
    'payouts'      => 'Payouts',
    'categories'   => 'Categories',
    'promos'       => 'Promo codes',
    'reports'      => 'Reports',
    'activity'     => 'Audit logs',
];
const SF_ADMIN_OPEN = ['overview', 'notifications', 'settings'];

// An admin row's access: ['full' => bool, 'areas' => [keys], 'title' => 'Super Admin' / 'Support Admin' / …]
function sf_admin_access_of(array $row) {
    $full  = $row['admin_access'] === null;
    $areas = $full ? array_keys(SF_ADMIN_AREAS)
                   : array_values(array_intersect(array_keys(SF_ADMIN_AREAS), explode(',', $row['admin_access'])));
    $title = $row['admin_title'] !== '' ? $row['admin_title'] : ($full ? 'Super Admin' : 'Admin');
    return ['full' => $full, 'areas' => $areas, 'title' => $title];
}

// The signed-in admin's access (read once per request)
function sf_admin_access($conn) {
    static $access = null;
    if ($access === null) {
        $stmt = $conn->prepare("SELECT admin_access, admin_title FROM users WHERE id = ? AND role = 'admin'");
        $id   = (int) ($_SESSION['user_id'] ?? 0);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $access = $row ? sf_admin_access_of($row) : ['full' => false, 'areas' => [], 'title' => 'Admin'];
    }
    return $access;
}

function sf_admin_can($conn, $area) {
    return in_array($area, SF_ADMIN_OPEN, true) || in_array($area, sf_admin_access($conn)['areas'], true);
}

// Top of each admin page: admins without that area go back to the dashboard with a message
function sf_admin_require($conn, $area) {
    if (sf_admin_can($conn, $area)) return;
    $_SESSION['access_denied'] = 'You don\'t have access to ' . (sf_admin_menu()[$area][0] ?? 'that page') . '. Ask a super admin to add it in Settings › Roles & permissions.';
    header('Location: ' . sf_root_url('admin_dashboard.php'));
    exit;
}

// Notification preferences (admin_settings.php), with defaults for anything not saved yet
const SF_NOTICE_PREFS = [
    'seller'      => ['New seller applications', 1],
    'reports'     => ['Flagged listings and reports', 1],
    'disputes'    => ['Disputed transactions', 1],
    'cancelled'   => ['Cancelled orders', 1],
    'payouts'     => ['Payout requests', 1],
    'payouts_big' => ['Only payout requests over ₱10,000', 0],
    'users'       => ['New users and Contact Us messages', 1],
];
const SF_BIG_PAYOUT = 10000;
function sf_admin_notice_prefs($json) {
    $saved = json_decode((string) $json, true) ?: [];
    $prefs = [];
    foreach (SF_NOTICE_PREFS as $key => [, $default]) $prefs[$key] = (int) ($saved[$key] ?? $default) ? 1 : 0;
    return $prefs;
}

// Writes one entry to the audit log (admin_logs.php) for the signed-in admin.
//   sf_admin_log($conn, 'user', 'Suspended user account', 'Priya Patel', 'SUSPENDED', true);
// kind picks the icon (SF_LOG_KINDS); badge is the short status shown next to the target.
const SF_LOG_KINDS = [
    'seller'   => 'Seller applications',
    'user'     => 'Users',
    'listing'  => 'Listings',
    'report'   => 'Moderation',
    'feedback' => 'Feedback',
    'dispute'  => 'Transactions',
    'payout'   => 'Payouts',
    'category' => 'Categories',
    'promo'    => 'Promo codes',
    'export'   => 'Reports',
];
function sf_admin_log($conn, $kind, $action, $target = '', $badge = '', $critical = false) {
    $adminId  = (int) ($_SESSION['user_id'] ?? 0) ?: null;
    $name     = mb_substr((string) ($_SESSION['username'] ?? 'Admin'), 0, 100);
    $target   = mb_substr((string) $target, 0, 255);
    $badge    = mb_substr(strtoupper((string) $badge), 0, 20);
    $critical = $critical ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, admin_name, kind, action, target, badge, critical) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssi", $adminId, $name, $kind, $action, $target, $badge, $critical);
    $stmt->execute();
    $stmt->close();
}

// Admin notifications (admin_notifications.php): marketplace activity from the last 30 days,
// newest first. Built from the tables themselves, so nothing has to remember to send them.
// Each: key, group (filter chip), icon (sidebar key), title, text, badge, tone, ts, action (button label or null), go, unread
function sf_admin_events($conn, $adminId) {
    $since  = "NOW() - INTERVAL 30 DAY";
    $events = [];
    $add = function ($key, $group, $icon, $title, $text, $badge, $tone, $ts, $action, $go) use (&$events) {
        $events[] = compact('key', 'group', 'icon', 'title', 'text', 'badge', 'tone', 'ts', 'action', 'go');
    };

    // Seller applications (a resubmission is a new notification)
    $res = $conn->query("SELECT s.user_id, s.store_name, s.status, UNIX_TIMESTAMP(COALESCE(s.submitted_at, s.created_at)) AS ts,
                                COALESCE(NULLIF(u.full_name, ''), u.username) AS name
                         FROM seller_profiles s JOIN users u ON u.id = s.user_id
                         WHERE COALESCE(s.submitted_at, s.created_at) >= $since");
    while ($r = $res->fetch_assoc()) {
        $pending = $r['status'] === 'pending';
        $add('seller-' . $r['user_id'] . '-' . $r['ts'], 'seller', 'seller-apps', 'New seller application',
             $r['name'] . ' applied to sell as ' . $r['store_name'], $pending ? 'Pending' : $r['status'],
             $pending ? 'pending' : ($r['status'] === 'approved' ? 'done' : 'flagged'), (int) $r['ts'],
             $pending ? 'Review' : null, 'admin_sellers.php?tab=' . ($pending ? 'pending' : $r['status']));
    }

    // Reports filed, and reports closed
    $res = $conn->query("SELECT id, target_type, target_name, reason, status, UNIX_TIMESTAMP(created_at) AS ts, UNIX_TIMESTAMP(resolved_at) AS closed_ts
                         FROM reports WHERE created_at >= $since OR resolved_at >= $since");
    while ($r = $res->fetch_assoc()) {
        $what = $r['target_type'] === 'listing' ? '"' . $r['target_name'] . '"' : $r['target_name'];
        $open = in_array($r['status'], ['open', 'escalated'], true);
        if ($r['ts'] >= strtotime('-30 days')) {
            $add('report-' . $r['id'], 'moderation', 'moderation', ($r['target_type'] === 'listing' ? 'Listing' : 'User') . ' reported',
                 $what . ' was reported for ' . mb_strtolower($r['reason']), $open ? ($r['status'] === 'escalated' ? 'Escalated' : 'Flagged') : 'Closed',
                 $open ? 'flagged' : 'muted', (int) $r['ts'], $open ? 'Moderate' : null, 'admin_moderation.php?tab=' . $r['target_type']);
        }
        if ($r['closed_ts']) {
            $add('report-closed-' . $r['id'], 'moderation', 'reports', 'Report ' . ($r['status'] === 'dismissed' ? 'dismissed' : 'resolved'),
                 $r['reason'] . ' report about ' . $what . ' was closed', $r['status'] === 'dismissed' ? 'Dismissed' : 'Done',
                 $r['status'] === 'dismissed' ? 'muted' : 'done', (int) $r['closed_ts'], null, 'admin_moderation.php?tab=' . $r['target_type']);
        }
    }

    // Payout requests
    $res = $conn->query("SELECT p.id, p.amount, p.method, p.status, UNIX_TIMESTAMP(p.created_at) AS ts, COALESCE(s.store_name, u.username) AS seller
                         FROM payout_requests p JOIN users u ON u.id = p.seller_id LEFT JOIN seller_profiles s ON s.user_id = p.seller_id
                         WHERE p.created_at >= $since");
    $prefs = sf_admin_notice_prefs($conn->query("SELECT admin_notice_prefs FROM users WHERE id = " . (int) $adminId)->fetch_assoc()['admin_notice_prefs'] ?? null);
    while ($r = $res->fetch_assoc()) {
        if ($prefs['payouts_big'] && (float) $r['amount'] <= SF_BIG_PAYOUT) continue;
        $waiting = in_array($r['status'], ['pending', 'flagged'], true);
        $add('payout-' . $r['id'], 'payouts', 'payouts', 'Payout requested',
             $r['seller'] . ' requested ₱' . number_format((float) $r['amount']) . ' via ' . ($r['method'] === 'gcash' ? 'GCash' : 'bank transfer'),
             $waiting ? 'Action' : $r['status'], $waiting ? 'action' : ($r['status'] === 'paid' ? 'done' : 'muted'), (int) $r['ts'],
             $waiting ? ($r['status'] === 'flagged' ? 'Review' : 'Approve') : null, 'admin_payouts.php?tab=' . $r['status']);
    }

    // Disputes
    $res = $conn->query("SELECT oi.id, oi.title, oi.dispute_status, oi.dispute_reason, UNIX_TIMESTAMP(oi.disputed_at) AS ts, o.order_no
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.disputed_at >= $since");
    while ($r = $res->fetch_assoc()) {
        $open = $r['dispute_status'] === 'open';
        $add('dispute-' . $r['id'] . '-' . $r['ts'], 'disputes', 'transactions', 'Transaction disputed',
             'Order ' . $r['order_no'] . ' was marked as disputed: ' . $r['title'] . ($r['dispute_reason'] !== '' ? ' (' . mb_strtolower($r['dispute_reason']) . ')' : ''),
             $open ? 'Dispute' : 'Settled', $open ? 'flagged' : 'done', (int) $r['ts'], $open ? 'Resolve' : null, 'admin_transactions.php?tab=disputed');
    }

    // Items buyers cancelled before the seller started (for reports; nothing to do)
    $res = $conn->query("SELECT oi.id, oi.title, oi.cancel_reason, oi.line_total, UNIX_TIMESTAMP(oi.cancelled_at) AS ts, o.order_no,
                                COALESCE(NULLIF(u.full_name, ''), u.username) AS buyer
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN users u ON u.id = o.user_id
                         WHERE oi.fulfillment = 'cancelled' AND oi.cancelled_at >= $since");
    while ($r = $res->fetch_assoc()) {
        $add('cancel-' . $r['id'], 'cancelled', 'transactions', 'Order cancelled',
             $r['buyer'] . ' cancelled ' . $r['title'] . ' (' . sf_peso($r['line_total']) . ', order ' . $r['order_no'] . ')' . ($r['cancel_reason'] !== '' ? ': ' . $r['cancel_reason'] : ''),
             'Cancelled', 'muted', (int) $r['ts'], null, 'admin_transactions.php?tab=all');
        $events[count($events) - 1]['pref'] = 'cancelled';   // its own switch in Settings
    }

    // Listings a seller fixed after they were flagged
    $res = $conn->query("SELECT id, title, UNIX_TIMESTAMP(updated_at) AS ts FROM listings WHERE status = 'pending' AND updated_at >= $since");
    while ($r = $res->fetch_assoc()) {
        $add('listing-' . $r['id'] . '-' . $r['ts'], 'moderation', 'listings', 'Listing awaiting review',
             '"' . $r['title'] . '" was updated by its seller after being flagged', 'Pending', 'pending', (int) $r['ts'], 'Review', 'admin_listings.php');
    }

    // Messages from the Contact Us page
    $res = $conn->query("SELECT id, name, subject, status, UNIX_TIMESTAMP(created_at) AS ts FROM feedback WHERE type = 'platform' AND created_at >= $since");
    while ($r = $res->fetch_assoc()) {
        $open = in_array($r['status'], ['pending', 'escalated'], true);
        $add('feedback-' . $r['id'], 'other', 'feedback', 'New feedback', ($r['name'] ?: 'Someone') . ' wrote: ' . $r['subject'],
             $open ? 'New' : $r['status'], $open ? 'new' : 'muted', (int) $r['ts'], $open ? 'Review' : null, 'admin_feedback.php?tab=platform');
    }

    // New accounts
    $res = $conn->query("SELECT id, email, UNIX_TIMESTAMP(created_at) AS ts FROM users WHERE role <> 'admin' AND created_at >= $since");
    while ($r = $res->fetch_assoc()) {
        $add('user-' . $r['id'], 'users', 'users', 'New user registered', $r['email'] . ' joined the marketplace', 'New', 'new', (int) $r['ts'], null, 'admin_users.php');
    }

    // Only areas this admin can open, and only kinds they asked to hear about (Settings)
    $areaOf = ['admin_sellers' => 'seller-apps', 'admin_moderation' => 'moderation', 'admin_payouts' => 'payouts', 'admin_transactions' => 'transactions',
               'admin_listings' => 'listings', 'admin_feedback' => 'feedback', 'admin_users' => 'users'];
    $prefOf = ['seller-apps' => 'seller', 'moderation' => 'reports', 'listings' => 'reports', 'transactions' => 'disputes',
               'payouts' => 'payouts', 'feedback' => 'users', 'users' => 'users'];
    $events = array_filter($events, function ($ev) use ($conn, $areaOf, $prefOf, $prefs) {
        $area = $areaOf[strtok($ev['go'], '.')];
        return sf_admin_can($conn, $area) && $prefs[$ev['pref'] ?? $prefOf[$area]];
    });
    usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $events = array_slice($events, 0, 100);

    // Unread: newer than Mark All as Read and not opened since
    $stmt = $conn->prepare("SELECT UNIX_TIMESTAMP(notices_seen_at) AS seen FROM users WHERE id = ?");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $seen = (int) ($stmt->get_result()->fetch_assoc()['seen'] ?? 0);
    $stmt->close();
    $stmt = $conn->prepare("SELECT event_key FROM admin_notice_reads WHERE admin_id = ?");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $read = array_flip(array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'event_key'));
    $stmt->close();
    foreach ($events as &$ev) $ev['unread'] = $ev['ts'] > $seen && !isset($read[$ev['key']]);
    unset($ev);
    return $events;
}

// Sidebar items: key => [label, link (null = not built yet), icon path]
function sf_admin_menu() {
    return [
        'overview'      => ['Dashboard Overview', 'admin_dashboard.php', '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'],
        'users'         => ['User Management', 'admin_users.php', '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>'],
        'listings'      => ['Listing Management', 'admin_listings.php', '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'],
        'seller-apps'   => ['Seller Applications', 'admin_sellers.php', '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>'],
        'moderation'    => ['Content Moderation', 'admin_moderation.php', '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],
        'feedback'      => ['Feedback', 'admin_feedback.php', '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
        'transactions'  => ['Transaction Management', 'admin_transactions.php', '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>'],
        'payouts'       => ['Payout Oversight', 'admin_payouts.php', '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
        'categories'    => ['Category Management', 'admin_categories.php', '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'],
        'promos'        => ['Promo Codes', 'admin_promos.php', '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>'],
        'reports'       => ['Reports', 'admin_reports.php', '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>'],
        'activity'      => ['Activity / Audit Logs', 'admin_logs.php', '<path d="M2 12h5l3-9 4 18 3-9h5"/>'],
        'notifications' => ['Notifications', 'admin_notifications.php', '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>'],
    ];
}

function sf_admin_shell_start($active, $searchPlaceholder = null) {
    global $conn;
    $counts   = sf_admin_counts($conn);
    $unread   = count(array_filter(sf_admin_events($conn, (int) ($_SESSION['user_id'] ?? 0)), fn($ev) => $ev['unread']));
    $badges   = $counts + ['notifications' => $unread];   // sidebar key => number waiting
    $name     = $_SESSION['username'] ?? 'Admin';
    $initials = strtoupper(mb_substr($name, 0, 2));
    $e        = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $icon     = fn($paths) => '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
    ?>
  <div class="adm-shell">
    <aside class="adm-sidebar" id="admSidebar">
      <a href="admin_dashboard.php" class="adm-brand"><span class="adm-brand-logo"><img src="assets/images/logo.png" alt="Station Finds" /></span></a>

      <div class="adm-profile">
        <span class="adm-avatar"><?php echo $e($initials); ?></span>
        <span class="adm-profile-text"><strong><?php echo $e($name); ?></strong><span><?php echo $e(sf_admin_access($conn)['title']); ?></span></span>
      </div>

      <p class="adm-menu-label">Main menu</p>
      <nav class="adm-nav" aria-label="Admin">
        <?php foreach (sf_admin_menu() as $key => [$label, $href, $paths]): ?>
          <?php if ($href && !sf_admin_can($conn, $key)) continue; ?>
          <?php if ($href): ?>
          <a href="<?php echo $href; ?>" class="adm-nav-item<?php echo $key === $active ? ' active' : ''; ?>"<?php echo $key === $active ? ' aria-current="page"' : ''; ?>>
            <?php echo $icon($paths); ?><span><?php echo $label; ?></span>
            <?php if (!empty($badges[$key])): ?><em class="adm-count" aria-label="<?php echo $badges[$key]; ?> waiting"><?php echo $badges[$key]; ?></em><?php endif; ?>
          </a>
          <?php else: ?>
          <span class="adm-nav-item disabled" title="Not built yet"><?php echo $icon($paths); ?><span><?php echo $label; ?></span><em class="adm-soon">Soon</em></span>
          <?php endif; ?>
        <?php endforeach; ?>
        <div class="adm-divider"></div>
        <a href="admin_settings.php" class="adm-nav-item<?php echo $active === 'settings' ? ' active" aria-current="page' : ''; ?>"><?php echo $icon('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.82 1.17V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-2.82-1.17l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 3.26 15H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.17-2.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 2.82 1.17l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 20.74 9H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'); ?><span>Settings</span></a>
        <a href="logout.php" class="adm-nav-item"><?php echo $icon('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>'); ?><span>Log out</span></a>
      </nav>
    </aside>

    <div class="adm-main">
      <header class="adm-topbar">
        <button type="button" class="adm-menu-toggle" id="admMenuToggle" aria-label="Open menu" aria-controls="admSidebar" aria-expanded="false"><?php echo $icon('<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>'); ?></button>
        <?php if ($searchPlaceholder): ?>
        <label class="adm-search"><?php echo $icon('<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>'); ?><input type="search" id="adminSearch" placeholder="<?php echo $e($searchPlaceholder); ?>" aria-label="<?php echo $e($searchPlaceholder); ?>" autocomplete="off" /></label>
        <?php else: ?><span class="adm-search-spacer"></span><?php endif; ?>
        <div class="adm-topbar-actions">
          <a href="admin_notifications.php" class="adm-bell" aria-label="Notifications<?php echo $unread ? ', ' . $unread . ' unread' : ''; ?>">
            <?php echo $icon('<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>'); ?><?php if ($unread): ?><span class="adm-bell-dot"></span><?php endif; ?>
          </a>
          <span class="adm-avatar" title="<?php echo $e($name); ?>"><?php echo $e($initials); ?></span>
        </div>
      </header>
      <?php if (!empty($_SESSION['access_denied'])): ?>
      <div class="adm-flash err adm-access-flash" role="alert"><?php echo $e($_SESSION['access_denied']); unset($_SESSION['access_denied']); ?></div>
      <?php endif; ?>
    <?php
}

function sf_admin_shell_end() {
    ?>
    </div>
  </div>
  <script>
    // Phones: the menu button opens the sidebar
    (function () {
      const toggle = document.getElementById('admMenuToggle');
      const sidebar = document.getElementById('admSidebar');
      toggle.addEventListener('click', () => {
        const open = sidebar.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      const search = document.getElementById('adminSearch');
      if (search) search.addEventListener('input', () => {
        if (typeof window.onAdminSearch === 'function') window.onAdminSearch(search.value.trim().toLowerCase());
      });
    })();
  </script>
    <?php
}
