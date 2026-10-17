<?php
session_start();

// Redirect to login page if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Admins only use the admin dashboard
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/db_setup.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/inbox.php';

$userId        = (int) $_SESSION['user_id'];
$notifications = sf_notifications($conn, $userId);
$unreadCount   = count(array_filter($notifications, fn($n) => !$n['is_read']));

// Split into "Today" and "Earlier"
$groups = ['Today' => [], 'Earlier' => []];
foreach ($notifications as $n) {
    $groups[sf_is_today($n['ts']) ? 'Today' : 'Earlier'][] = $n;
}

$icons = [
    'message'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
    'order'    => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path>',
    'payout'   => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
    'price'    => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>',
    'security' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
    'info'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
];

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Notifications — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=5" />
  <link rel="stylesheet" href="css/inbox.css?v=6" />
</head>
<body>

  <?php sf_render_navbar(sf_cart_count($conn, $userId), 'notifications'); ?>

  <main class="inbox-wrapper">
    <div class="inbox-head">
      <div>
        <h1 class="shop-title">Notifications</h1>
        <p class="shop-subtitle">Stay up to date with your orders, messages, and account activity.</p>
      </div>
      <?php if ($notifications): ?>
      <button type="button" class="link-btn" id="markAllBtn" onclick="markAllRead()"<?php echo $unreadCount ? '' : ' disabled'; ?>>Mark all as read</button>
      <?php endif; ?>
    </div>

    <?php if (!$notifications): ?>
    <div class="card inbox-empty">
      <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><?php echo $icons['info']; ?></svg>
      <h2>No notifications yet</h2>
      <p>Messages, order updates and account changes will show up here.</p>
    </div>
    <?php endif; ?>

    <?php foreach ($groups as $label => $items): if (!$items) continue; ?>
    <h2 class="notif-group-label"><?php echo $label; ?></h2>
    <div class="notif-list">
      <?php foreach ($items as $n):
        $link = sf_safe_link($n['link']);
        $tag  = $link !== '' ? 'a' : 'div';
      ?>
      <<?php echo $tag; ?> class="notif-item<?php echo $n['is_read'] ? '' : ' unread'; ?>" data-id="<?php echo (int) $n['id']; ?>"<?php if ($link !== ''): ?> href="<?php echo $e($link); ?>" onclick="openNotification(event, this)"<?php endif; ?>>
        <span class="notif-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $icons[$n['type']] ?? $icons['info']; ?></svg></span>
        <span class="notif-text">
          <span class="notif-title"><?php echo $e($n['title']); ?></span>
          <?php if ($n['body'] !== ''): ?><span class="notif-body"><?php echo $e($n['body']); ?></span><?php endif; ?>
        </span>
        <span class="notif-time"><?php echo sf_time_ago($n['ts']); ?></span>
      </<?php echo $tag; ?>>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=5"></script>
  <script src="js/inbox.js?v=3"></script>
  <script>
    function post(payload) {
      return fetch('api/inbox_handler.php', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload)
      }).then(res => res.json());
    }

    // Mark as read, then follow the link
    function openNotification(e, el) {
      if (!el.classList.contains('unread') || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
      const go = () => { window.location.href = el.getAttribute('href'); };
      post({ action: 'read', id: Number(el.dataset.id) }).then(go, go);
    }

    async function markAllRead() {
      const btn = document.getElementById('markAllBtn');
      btn.disabled = true;
      try {
        const r = await post({ action: 'mark_all_read' });
        if (!r.success) throw new Error(r.message);
        document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
        SFInbox.show(r);
      } catch (e) {
        btn.disabled = false;
        SFCart.toast('Could not update your notifications. Please try again.', true);
      }
    }
  </script>
</body>
</html>
