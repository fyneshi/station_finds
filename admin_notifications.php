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
$icon    = fn($paths, $size = 18) => '<svg aria-hidden="true" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Open one (marks it read, then goes to the page that handles it) / mark all read ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sf_check_csrf()) {
        $flash = ['err', 'Your session expired. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'read_all') {
        $stmt = $conn->prepare("UPDATE users SET notices_seen_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $stmt->close();
        // Everything they opened one by one is now older than notices_seen_at
        $stmt = $conn->prepare("DELETE FROM admin_notice_reads WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = ['ok', 'All notifications are marked as read.'];
        header('Location: admin_notifications.php');
        exit;
    } elseif (($_POST['action'] ?? '') === 'open') {
        $key = (string) ($_POST['key'] ?? '');
        $go  = (string) ($_POST['go'] ?? '');
        if (preg_match('/^[a-z]+(-[a-z]+)?(-\d+){1,2}$/', $key) && strlen($key) <= 80) {
            $stmt = $conn->prepare("INSERT IGNORE INTO admin_notice_reads (admin_id, event_key) VALUES (?, ?)");
            $stmt->bind_param("is", $adminId, $key);
            $stmt->execute();
            $stmt->close();
        }
        // Only ever send them on to another admin page
        header('Location: ' . (preg_match('/^admin_[a-z]+\.php(\?tab=[a-z-]+)?$/', $go) ? $go : 'admin_notifications.php'));
        exit;
    }
}

$events = sf_admin_events($conn, $adminId);
$menu   = sf_admin_menu();
$today  = strtotime('today');
$groups = [
    'today'   => ['Today', array_values(array_filter($events, fn($ev) => $ev['ts'] >= $today))],
    'earlier' => ['Earlier', array_values(array_filter($events, fn($ev) => $ev['ts'] < $today))],
];
$unread  = count(array_filter($events, fn($ev) => $ev['unread']));
$filters = ['all' => 'All', 'unread' => 'Unread', 'seller' => 'Seller Apps', 'moderation' => 'Moderation', 'disputes' => 'Disputes', 'cancelled' => 'Cancelled', 'payouts' => 'Payouts', 'users' => 'New users'];
$csrf    = sf_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Notifications — Station Finds Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css?v=23" />
  <link rel="stylesheet" href="css/admin-shell.css?v=2" />
</head>
<body>

  <?php sf_admin_shell_start('notifications', 'Search notifications, users, listings…'); ?>

      <header class="dashboard-header">
        <div class="header-title-group">
          <h1>Notifications</h1>
          <p>Stay on top of marketplace activity that needs attention.</p>
        </div>
        <div class="header-actions">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
            <input type="hidden" name="action" value="read_all" />
            <button type="submit" class="export-button"<?php echo $unread ? '' : ' disabled'; ?>>Mark All as Read</button>
          </form>
          <span class="notif-bell<?php echo $unread ? ' has-unread' : ''; ?>" title="<?php echo $unread ? $unread . ' unread' : 'All caught up'; ?>"><?php echo $icon($menu['notifications'][2]); ?><?php if ($unread): ?><em><?php echo $unread > 99 ? '99+' : $unread; ?></em><?php endif; ?></span>
        </div>
      </header>

      <div class="dashboard-content">
        <?php if ($flash): ?>
        <div class="adm-flash <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status"><?php echo $e($flash[1]); ?></div>
        <?php endif; ?>

        <?php foreach ($groups as $id => [$label, $list]):
          $new = count(array_filter($list, fn($ev) => $ev['unread']));
        ?>
        <section class="notif-group" data-group-section<?php echo $list ? '' : ' hidden'; ?>>
          <h2 class="notif-group-title"><?php echo $label; ?><?php if ($new): ?> <span class="notif-new"><?php echo $new; ?> new</span><?php endif; ?></h2>
          <ul class="notif-list">
            <?php foreach ($list as $ev): ?>
            <li class="notif-row<?php echo $ev['unread'] ? ' unread' : ''; ?>" data-group="<?php echo $ev['group']; ?>" data-unread="<?php echo $ev['unread'] ? 1 : 0; ?>"
                data-search="<?php echo $e(mb_strtolower($ev['title'] . ' ' . $ev['text'] . ' ' . $ev['badge'])); ?>">
              <form method="post" class="notif-form">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>" />
                <input type="hidden" name="action" value="open" />
                <input type="hidden" name="key" value="<?php echo $e($ev['key']); ?>" />
                <input type="hidden" name="go" value="<?php echo $e($ev['go']); ?>" />
                <span class="notif-icon <?php echo $ev['tone'] === 'flagged' ? 'danger' : ''; ?>"><?php echo $icon($menu[$ev['icon']][2], 17); ?></span>
                <button type="submit" class="notif-main"<?php echo $ev['action'] ? ' tabindex="-1"' : ''; ?>>
                  <span class="notif-title"><?php echo $e($ev['title']); ?><span class="notif-badge <?php echo $ev['tone']; ?>"><?php echo $e($ev['badge']); ?></span><?php if ($ev['unread']): ?><span class="visually-hidden"> (unread)</span><?php endif; ?></span>
                  <span class="notif-text"><?php echo $e($ev['text']); ?></span>
                </button>
                <span class="notif-side">
                  <time datetime="<?php echo date('c', $ev['ts']); ?>" title="<?php echo date('M j, Y g:i A', $ev['ts']); ?>"><?php echo sf_time_ago($ev['ts']); ?></time>
                  <?php if ($ev['action']): ?><button type="submit" class="notif-action"><?php echo $e($ev['action']); ?></button><?php endif; ?>
                </span>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endforeach; ?>

        <p class="empty-state notif-empty" id="emptyNotifs"<?php echo $events ? ' hidden' : ''; ?>><?php echo $events ? 'No notifications match.' : 'Nothing in the last 30 days. New seller applications, reports, payouts and disputes will show up here.'; ?></p>

        <div class="notif-filters" role="group" aria-label="Filter notifications">
          <span>Filter by:</span>
          <?php foreach ($filters as $value => $label): ?>
          <button type="button" class="notif-chip" data-filter="<?php echo $value; ?>" aria-pressed="<?php echo $value === 'all' ? 'true' : 'false'; ?>"><?php echo $icon('<path d="M3 4h18l-7 8v8l-4-2v-6Z"/>', 14); ?><?php echo $label; ?></button>
          <?php endforeach; ?>
        </div>
      </div>

  <?php sf_admin_shell_end(); ?>

  <script>
    const rows     = [...document.querySelectorAll('.notif-row')];
    const sections = [...document.querySelectorAll('[data-group-section]')];
    const chips    = [...document.querySelectorAll('.notif-chip')];
    let filter = 'all', query = '';

    function apply() {
      rows.forEach(r => {
        r.hidden = (filter === 'unread' ? r.dataset.unread !== '1' : filter !== 'all' && r.dataset.group !== filter)
          || (query && !r.dataset.search.includes(query));
      });
      sections.forEach(s => { s.hidden = ![...s.querySelectorAll('.notif-row')].some(r => !r.hidden); });
      if (rows.length) document.getElementById('emptyNotifs').hidden = rows.some(r => !r.hidden);
    }
    chips.forEach(c => c.addEventListener('click', () => {
      filter = c.dataset.filter;
      chips.forEach(x => x.setAttribute('aria-pressed', String(x === c)));
      apply();
    }));
    window.onAdminSearch = q => { query = q; apply(); };
  </script>
</body>
</html>
