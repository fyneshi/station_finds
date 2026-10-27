<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?next=' . rawurlencode(basename($_SERVER['REQUEST_URI'] ?? '')));
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
require_once __DIR__ . '/includes/reports.php';

$userId = (int) $_SESSION['user_id'];

// Which chat is open: messages.php?with=<user id>
// (no id = the most recent conversation, shown next to the list on wide screens)
$withId  = (int) ($_GET['with'] ?? 0);
$partner = ($withId && $withId !== $userId) ? sf_user($conn, $withId) : null;
if ($withId && !$partner) {
    header('Location: messages.php');
    exit;
}

$conversations = sf_conversations($conn, $userId);
if (!$partner && $conversations) {
    $partner = sf_user($conn, (int) $conversations[0]['id']);
}

$thread = [];
if ($partner) {
    $thread = sf_thread($conn, $userId, (int) $partner['id']);
    // A chat picked from the list is read now; the default one only once it's on screen (see markSeen below)
    if ($withId) {
        sf_mark_thread_read($conn, $userId, (int) $partner['id']);
        foreach ($conversations as &$c) {
            if ((int) $c['id'] === (int) $partner['id']) $c['unread'] = 0;
        }
        unset($c);
    }
}

// People you haven't chatted with yet (shown when searching)
$chattedWith = array_map(fn($c) => (int) $c['id'], $conversations);
$people = array_filter(sf_other_users($conn, $userId), fn($u) => !in_array((int) $u['id'], $chattedWith, true));

$e        = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$initials = fn($name) => strtoupper(mb_substr($name, 0, 2));
// Right-click → View profile: only people who have a public profile (not admins or suspended accounts)
$profileAttr = fn($u) => ($u['role'] ?? '') !== 'admin' && ($u['status'] ?? 'active') === 'active'
    ? ' data-profile-id="' . (int) $u['id'] . '" data-profile-name="' . htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') . '"' : '';
$roleText = fn($u) => $u['role'] === 'admin' ? 'Station Finds admin' : 'Student member';
$lastId   = $thread ? (int) end($thread)['id'] : 0;
// Drawn by the script below: text, quoted reply, reactions (sf_message_payloads in inbox.php)
$threadData = $partner ? sf_message_payloads($conn, $userId, $thread, $partner['username']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Messages — Station Finds</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/cart.css?v=6" />
  <link rel="stylesheet" href="css/inbox.css?v=6" />
  <link rel="stylesheet" href="css/header.css?v=1" />
  <link rel="stylesheet" href="css/responsive.css?v=2" />
</head>
<body>

  <?php sf_render_navbar(sf_cart_count($conn, $userId), 'messages'); ?>

  <main class="inbox-wrapper">
    <div class="inbox-head">
      <h1 class="shop-title">Messages</h1>
    </div>

    <div class="chat-layout<?php echo $withId ? ' has-chat' : ''; ?>">

      <!-- Conversation list -->
      <aside class="card chat-sidebar">
        <div class="chat-search">
          <label class="search-bar">
            <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="search" id="convoSearch" class="search-input" placeholder="Search conversations..." autocomplete="off" aria-label="Search conversations or people" />
          </label>
        </div>

        <div class="convo-list">
          <div id="convoGroup">
            <?php if ($partner && !in_array((int) $partner['id'], $chattedWith, true)): ?>
            <a href="messages.php?with=<?php echo (int) $partner['id']; ?>" class="convo-item active" data-name="<?php echo $e(mb_strtolower($partner['username'])); ?>"<?php echo $profileAttr($partner); ?>>
              <span class="presence"><span class="avatar"><?php echo sf_avatar_html($partner['username'], $partner['avatar']); ?></span><?php echo sf_online_dot($partner); ?></span>
              <span class="convo-text">
                <span class="convo-name"><?php echo $e($partner['username']); ?></span>
                <span class="convo-preview">New conversation</span>
              </span>
            </a>
            <?php endif; ?>

            <?php foreach ($conversations as $c):
              $isActive = $partner && (int) $c['id'] === (int) $partner['id'];
              $preview  = ((int) $c['last_sender'] === $userId ? 'You: ' : '') . ($c['last_unsent'] ? 'Message unsent' : ($c['last_body'] === '' && $c['last_photo'] ? '📷 Photo' : sf_excerpt($c['last_body'], 40)));
            ?>
            <a href="messages.php?with=<?php echo (int) $c['id']; ?>" class="convo-item<?php echo $isActive ? ' active' : ''; echo $c['unread'] ? ' has-unread' : ''; ?>" data-name="<?php echo $e(mb_strtolower($c['username'])); ?>"<?php echo $profileAttr($c); ?>>
              <span class="presence"><span class="avatar"><?php echo sf_avatar_html($c['username'], $c['avatar']); ?></span><?php echo sf_online_dot($c); ?></span>
              <span class="convo-text">
                <span class="convo-name"><?php echo $e($c['username']); ?></span>
                <span class="convo-preview"><?php echo $e($preview); ?></span>
              </span>
              <span class="convo-meta">
                <span class="convo-time"><?php echo sf_time_ago($c['ts']); ?></span>
                <?php if ($c['unread']): ?><span class="convo-dot" title="<?php echo (int) $c['unread']; ?> unread"></span><?php endif; ?>
              </span>
            </a>
            <?php endforeach; ?>

            <?php if (!$conversations && !$partner): ?>
            <p class="convo-none" id="noConvos">No conversations yet. Search for a classmate above to start one.</p>
            <?php endif; ?>
          </div>

          <!-- Other members: only shown while searching -->
          <div id="peopleGroup" hidden>
            <div class="convo-section-label">Start a new chat</div>
            <?php foreach ($people as $p): if ($partner && (int) $p['id'] === (int) $partner['id']) continue; ?>
            <a href="messages.php?with=<?php echo (int) $p['id']; ?>" class="convo-item" data-name="<?php echo $e(mb_strtolower($p['username'])); ?>"<?php echo $profileAttr($p); ?>>
              <span class="presence"><span class="avatar"><?php echo sf_avatar_html($p['username'], $p['avatar']); ?></span><?php echo sf_online_dot($p); ?></span>
              <span class="convo-text">
                <span class="convo-name"><?php echo $e($p['username']); ?></span>
                <span class="convo-preview"><?php echo $e($roleText($p)); ?></span>
              </span>
            </a>
            <?php endforeach; ?>
          </div>
          <p class="convo-none" id="noMatches" hidden>No one matches your search.</p>
        </div>
      </aside>

      <!-- Open chat -->
      <section class="card chat-panel">
        <?php if ($partner): ?>
        <div class="chat-header"<?php echo $profileAttr($partner); ?>>
          <a href="messages.php" class="chat-back" aria-label="Back to conversations">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
          </a>
          <span class="presence"><span class="avatar"><?php echo sf_avatar_html($partner['username'], $partner['avatar']); ?></span><?php echo sf_online_dot($partner); ?></span>
          <div>
            <div class="chat-name"><?php if ($partner['role'] !== 'admin' && $partner['status'] === 'active'): ?><a href="profile.php?id=<?php echo (int) $partner['id']; ?>" class="chat-profile-link" title="View profile"><?php echo $e($partner['username']); ?></a><?php else: ?><?php echo $e($partner['username']); ?><?php endif; ?></div>
            <div class="chat-sub"><?php $active = sf_active_text($partner); ?><?php if ($active === 'Active now'): ?><span class="active-now">Active now</span><?php else: ?><?php echo $e($active !== '' && $partner['role'] !== 'admin' ? $active : $roleText($partner)); ?><?php endif; ?></div>
          </div>
          <?php if ($partner['role'] !== 'admin'): ?>
          <button type="button" class="report-link chat-report" data-report-type="user" data-report-id="<?php echo (int) $partner['id']; ?>" data-report-name="<?php echo $e($partner['username']); ?>" data-report-reasons="<?php echo $e(json_encode(SF_REPORT_REASONS['user'])); ?>">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
            Report user
          </button>
          <?php endif; ?>
        </div>

        <div class="chat-messages" id="chatMessages" aria-live="polite">
          <?php if (!$thread): ?>
          <p class="chat-day-start" id="chatEmpty">This is the start of your conversation with <?php echo $e($partner['username']); ?>. Say hi!</p>
          <?php endif; ?>
        </div>

        <div class="reply-bar" id="replyBar" hidden>
          <div class="reply-bar-text"><strong id="replyBarWho"></strong><span id="replyBarQuote"></span></div>
          <button type="button" class="reply-bar-close" id="replyBarClose" aria-label="Cancel reply">&times;</button>
        </div>
        <div class="photo-bar" id="photoBar" hidden>
          <img id="photoPreview" alt="Photo to send" />
          <div class="photo-bar-text"><strong>Photo ready to send</strong><span id="photoName"></span></div>
          <button type="button" class="reply-bar-close" id="photoClear" aria-label="Remove photo">&times;</button>
        </div>
        <form class="chat-compose" id="chatForm" autocomplete="off">
          <label class="btn-attach" title="Send a photo (e.g. a payment screenshot)">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" aria-label="Send a photo" />
          </label>
          <input type="text" id="chatInput" class="text-input" placeholder="Write a message..." maxlength="<?php echo SF_MESSAGE_MAX; ?>" aria-label="Write a message" />
          <button type="submit" class="btn-send" id="sendBtn">Send</button>
        </form>
        <?php else: ?>
        <div class="chat-placeholder">
          <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
          <h2>Your messages</h2>
          <p>Search for a classmate on the left to ask about a listing or arrange a meetup.</p>
        </div>
        <?php endif; ?>
      </section>
    </div>
  </main>

  <?php sf_render_footer(); ?>

  <script src="js/cart.js?v=6"></script>
  <script src="js/inbox.js?v=4"></script>
  <script src="js/report.js?v=3"></script>
  <script src="js/profile-menu.js?v=2"></script>
  <script>
    // ── Conversation search: filters chats and shows matching people ──
    (function () {
      const input  = document.getElementById('convoSearch');
      const people = document.getElementById('peopleGroup');
      const none   = document.getElementById('noMatches');
      const noConvos = document.getElementById('noConvos');

      input.addEventListener('input', function () {
        const q = input.value.trim().toLowerCase();
        let shown = 0, shownPeople = 0;
        document.querySelectorAll('#convoGroup .convo-item').forEach(el => {
          const match = el.dataset.name.includes(q);
          el.hidden = !match;
          if (match) shown++;
        });
        document.querySelectorAll('#peopleGroup .convo-item').forEach(el => {
          const match = q !== '' && el.dataset.name.includes(q);
          el.hidden = !match;
          if (match) shownPeople++;
        });
        people.hidden = shownPeople === 0;
        if (noConvos) noConvos.hidden = q !== '';
        none.hidden = q === '' || shown + shownPeople > 0;
      });
    })();

    <?php if ($partner): ?>
    // ── Open chat: send, react, reply, unsend / remove, and check for changes every few seconds ──
    (function () {
      const PARTNER_ID   = <?php echo (int) $partner['id']; ?>;
      const PARTNER_NAME = <?php echo json_encode($partner['username'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
      const REACTIONS    = <?php echo json_encode(SF_REACTIONS, JSON_UNESCAPED_UNICODE); ?>;
      const CSRF         = <?php echo json_encode(sf_csrf_token()); ?>;   // photo uploads need it
      const PHOTO_MAX    = <?php echo SF_CHAT_MAX_BYTES; ?>;
      const list  = document.getElementById('chatMessages');
      const form  = document.getElementById('chatForm');
      const input = document.getElementById('chatInput');
      const btn   = document.getElementById('sendBtn');
      let lastId  = <?php echo $lastId; ?>;
      let polling = false;
      let replyTo = null;          // { id, author, text } while replying

      function post(payload) {
        return fetch('api/inbox_handler.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(payload)
        }).then(res => res.json());
      }
      function el(tag, cls, text) {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
      }
      const ICONS = {
        react: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>',
        reply: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 17 4 12 9 7"></polyline><path d="M20 18v-2a4 4 0 0 0-4-4H4"></path></svg>',
        more:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"></circle><circle cx="12" cy="12" r="2"></circle><circle cx="12" cy="19" r="2"></circle></svg>'
      };
      function actionButton(kind, label) {
        const b = el('button', 'msg-action');
        b.type = 'button';
        b.dataset.action = kind;
        b.setAttribute('aria-label', label);
        b.title = label;
        b.innerHTML = ICONS[kind];      // fixed icon markup only
        return b;
      }

      function scrollToEnd() { list.scrollTop = list.scrollHeight; }

      // ── Drawing a message ──
      function renderReactions(wrap, reactions) {
        let box = wrap.querySelector('.msg-reactions');
        if (!reactions || !reactions.length) { if (box) box.remove(); return; }
        if (!box) { box = el('div', 'msg-reactions'); wrap.querySelector('.msg-row').after(box); }
        box.replaceChildren(...reactions.map(r => {
          const chip = el('button', 'reaction-chip' + (r.mine ? ' mine' : ''));
          chip.type = 'button';
          chip.dataset.emoji = r.emoji;
          chip.title = r.mine ? 'You reacted ' + r.emoji + ' (click to remove)' : 'Reacted ' + r.emoji;
          chip.setAttribute('aria-label', r.emoji + ' ' + r.count + (r.mine ? ', including you' : ''));
          chip.append(el('span', '', r.emoji));
          if (r.count > 1) chip.append(el('span', 'reaction-count', String(r.count)));
          return chip;
        }));
      }
      function markUnsent(wrap) {
        wrap.classList.add('unsent');
        const bubble = wrap.querySelector('.msg-bubble');
        bubble.textContent = wrap.classList.contains('mine') ? 'You unsent a message' : 'Message unsent';
        wrap.querySelectorAll('.msg-action[data-action="react"], .msg-action[data-action="reply"], .msg-reactions').forEach(n => n.remove());
      }
      function addMessage(m) {
        if (list.querySelector('.msg[data-id="' + m.id + '"]')) return;
        const empty = document.getElementById('chatEmpty');
        if (empty) empty.remove();

        const wrap = el('div', 'msg' + (m.mine ? ' mine' : ''));
        wrap.dataset.id = m.id;
        wrap.dataset.author = m.mine ? 'You' : PARTNER_NAME;
        if (m.reply) {
          const quote = el('button', 'msg-quote' + (m.reply.unsent ? ' unsent' : ''));
          quote.type = 'button';
          quote.dataset.jump = m.reply.id;
          quote.append(el('span', 'msg-quote-who', (m.mine ? 'You replied to ' : PARTNER_NAME + ' replied to ') + (m.reply.author === 'You' ? (m.mine ? 'yourself' : 'you') : m.reply.author)),
                       el('span', 'msg-quote-text', m.reply.text));
          wrap.append(quote);
        }
        const row = el('div', 'msg-row');
        const bubble = el('div', 'msg-bubble' + (m.photo ? ' has-photo' : '') + (m.photo && !m.body ? ' photo-only' : ''));
        if (m.photo) {
          const link = el('a', 'msg-photo-link');
          link.href = m.photo;
          link.target = '_blank';
          link.rel = 'noopener';
          link.title = 'Open full size';
          const img = el('img', 'msg-photo');
          img.src = m.photo;
          img.alt = m.mine ? 'Photo you sent' : 'Photo from ' + PARTNER_NAME;
          img.loading = 'lazy';
          img.addEventListener('load', () => { if (m.mine) scrollToEnd(); });
          link.append(img);
          bubble.append(link);
        }
        if (m.body) bubble.append(el('span', 'msg-text', m.body));
        bubble.tabIndex = 0;
        const actions = el('div', 'msg-actions');
        actions.append(actionButton('react', 'React'), actionButton('reply', 'Reply'), actionButton('more', 'More'));
        row.append(bubble, actions);
        wrap.append(row, el('span', 'msg-time', m.time));
        if (m.unsent) markUnsent(wrap); else renderReactions(wrap, m.reactions);

        const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
        list.appendChild(wrap);
        if (m.mine || nearBottom) scrollToEnd();
        lastId = Math.max(lastId, m.id);
      }

      // ── Floating pickers: reactions, and the ⋮ menu ──
      const picker = el('div', 'reaction-picker');
      picker.setAttribute('role', 'menu');
      picker.hidden = true;
      REACTIONS.forEach(emoji => {
        const b = el('button', 'reaction-option', emoji);
        b.type = 'button';
        b.dataset.emoji = emoji;
        b.setAttribute('role', 'menuitem');
        picker.append(b);
      });
      const menu = el('div', 'msg-menu');
      menu.setAttribute('role', 'menu');
      menu.hidden = true;
      document.body.append(picker, menu);
      let target = null;           // the message a picker/menu is open for

      function place(pop, anchor) {
        pop.hidden = false;
        const r = anchor.getBoundingClientRect();
        const w = pop.offsetWidth, h = pop.offsetHeight;
        let top = r.top - h - 6;
        if (top < 8) top = r.bottom + 6;
        pop.style.top = top + 'px';
        pop.style.left = Math.max(8, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 8)) + 'px';
      }
      function closePops() {
        picker.hidden = menu.hidden = true;
        list.querySelectorAll('.msg.pinned').forEach(m => m.classList.remove('pinned'));
      }
      function openPicker(wrap, anchor) {
        closePops();
        target = wrap;
        wrap.classList.add('pinned');
        const mine = [...wrap.querySelectorAll('.reaction-chip.mine')].map(c => c.dataset.emoji);
        picker.querySelectorAll('.reaction-option').forEach(b => b.classList.toggle('chosen', mine.includes(b.dataset.emoji)));
        place(picker, anchor);
        picker.querySelector('.reaction-option').focus();
      }
      function openMenu(wrap, anchor) {
        closePops();
        target = wrap;
        wrap.classList.add('pinned');
        menu.replaceChildren();
        const item = (label, action, danger) => {
          const b = el('button', 'msg-menu-item' + (danger ? ' danger' : ''), label);
          b.type = 'button';
          b.dataset.action = action;
          b.setAttribute('role', 'menuitem');
          menu.append(b);
        };
        if (!wrap.classList.contains('unsent')) item('Copy text', 'copy');
        if (wrap.classList.contains('mine') && !wrap.classList.contains('unsent')) item('Unsend for everyone', 'unsend', true);
        item('Remove for you', 'hide', true);
        place(menu, anchor);
        menu.querySelector('button').focus();
      }

      async function react(wrap, emoji) {
        try {
          const r = await post({ action: 'react', id: Number(wrap.dataset.id), emoji });
          if (!r.success) throw new Error(r.message);
          renderReactions(wrap, r.reactions);
        } catch (err) { SFCart.toast(err.message || 'Could not react. Please try again.', true); }
      }
      function startReply(wrap) {
        replyTo = { id: Number(wrap.dataset.id), author: wrap.dataset.author, text: wrap.querySelector('.msg-bubble').textContent.trim() || '📷 Photo' };
        document.getElementById('replyBarWho').textContent = 'Replying to ' + (replyTo.author === 'You' ? 'yourself' : replyTo.author);
        document.getElementById('replyBarQuote').textContent = replyTo.text.length > 90 ? replyTo.text.slice(0, 90) + '…' : replyTo.text;
        document.getElementById('replyBar').hidden = false;
        input.focus();
      }
      function cancelReply() {
        replyTo = null;
        document.getElementById('replyBar').hidden = true;
      }
      document.getElementById('replyBarClose').addEventListener('click', () => { cancelReply(); input.focus(); });

      // Clicks inside the chat: action buttons, reaction chips, quotes, and tapping a bubble on phones
      list.addEventListener('click', e => {
        const wrap = e.target.closest('.msg');
        const action = e.target.closest('.msg-action');
        const chip = e.target.closest('.reaction-chip');
        const quote = e.target.closest('.msg-quote');
        if (action && wrap) {
          e.stopPropagation();
          if (action.dataset.action === 'react') openPicker(wrap, action);
          else if (action.dataset.action === 'reply') { closePops(); startReply(wrap); }
          else openMenu(wrap, action);
        } else if (chip && wrap && chip.classList.contains('mine')) {
          react(wrap, chip.dataset.emoji);           // tapping your own reaction removes it
        } else if (quote) {
          const original = list.querySelector('.msg[data-id="' + quote.dataset.jump + '"]');
          if (original) {
            original.scrollIntoView({ behavior: 'smooth', block: 'center' });
            original.classList.remove('flash');
            void original.offsetWidth;
            original.classList.add('flash');
          } else {
            SFCart.toast('That message is no longer in this chat.', true);
          }
        } else if (wrap && e.target.closest('.msg-bubble')) {
          list.querySelectorAll('.msg.show-actions').forEach(m => { if (m !== wrap) m.classList.remove('show-actions'); });
          wrap.classList.toggle('show-actions');
        }
      });
      picker.addEventListener('click', e => {
        const b = e.target.closest('.reaction-option');
        if (!b || !target) return;
        const wrap = target;
        closePops();
        react(wrap, b.dataset.emoji);
      });
      menu.addEventListener('click', async e => {
        const b = e.target.closest('.msg-menu-item');
        if (!b || !target) return;
        const wrap = target;
        closePops();
        if (b.dataset.action === 'copy') {
          try { await navigator.clipboard.writeText(wrap.querySelector('.msg-bubble').textContent); SFCart.toast('Message copied.'); }
          catch (err) { SFCart.toast('Could not copy the message.', true); }
          return;
        }
        const unsend = b.dataset.action === 'unsend';
        if (!confirm(unsend ? 'Unsend this message? It will be removed for both of you.' : 'Remove this message for you? The other person will still see it.')) return;
        try {
          const r = await post({ action: unsend ? 'unsend' : 'hide', id: Number(wrap.dataset.id) });
          if (!r.success) throw new Error(r.message);
          if (unsend) markUnsent(wrap); else wrap.remove();
          if (replyTo && replyTo.id === Number(wrap.dataset.id)) cancelReply();
        } catch (err) { SFCart.toast(err.message || 'That did not work. Please try again.', true); }
      });
      document.addEventListener('click', e => {
        if (!picker.contains(e.target) && !menu.contains(e.target)) closePops();
        if (!e.target.closest('.msg')) list.querySelectorAll('.msg.show-actions').forEach(m => m.classList.remove('show-actions'));
      });
      document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (!picker.hidden || !menu.hidden) {
          const back = target && target.querySelector('.msg-action');
          closePops();
          if (back) back.focus();
        } else if (replyTo) cancelReply();
      });
      list.addEventListener('scroll', closePops);
      window.addEventListener('resize', closePops);

      // ── Sending ──
      // ── Photo to send (e.g. a payment screenshot) ──
      const photoInput = document.getElementById('photoInput');
      let photo = null;
      function clearPhoto() {
        photo = null;
        photoInput.value = '';
        const preview = document.getElementById('photoPreview');
        if (preview.src) URL.revokeObjectURL(preview.src);
        preview.removeAttribute('src');
        document.getElementById('photoBar').hidden = true;
      }
      photoInput.addEventListener('change', () => {
        const file = photoInput.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { SFCart.toast('Send a JPG, PNG or WebP photo.', true); photoInput.value = ''; return; }
        if (file.size > PHOTO_MAX) { SFCart.toast('That photo is over 5 MB.', true); photoInput.value = ''; return; }
        clearPhoto();
        photo = file;
        document.getElementById('photoPreview').src = URL.createObjectURL(file);
        document.getElementById('photoName').textContent = file.name + ' · add a caption or press Send';
        document.getElementById('photoBar').hidden = false;
        input.focus();
      });
      document.getElementById('photoClear').addEventListener('click', () => { clearPhoto(); input.focus(); });

      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const text = input.value.trim();
        if (!text && !photo) return;
        btn.disabled = true;
        try {
          let r;
          if (photo) {
            const data = new FormData();
            data.append('action', 'send_photo');
            data.append('csrf_token', CSRF);
            data.append('to', PARTNER_ID);
            data.append('text', text);
            data.append('reply_to', replyTo ? replyTo.id : 0);
            data.append('photo', photo);
            btn.textContent = 'Sending…';
            r = await fetch('api/inbox_handler.php', { method: 'POST', credentials: 'same-origin', body: data }).then(res => res.json());
          } else {
            r = await post({ action: 'send', to: PARTNER_ID, text: text, reply_to: replyTo ? replyTo.id : 0 });
          }
          if (!r.success) throw new Error(r.message);
          input.value = '';
          clearPhoto();
          cancelReply();
          addMessage(r.message);
        } catch (err) {
          SFCart.toast(err.message || 'Your message could not be sent. Please try again.', true);
        }
        btn.textContent = 'Send';
        btn.disabled = false;
        input.focus();
      });

      // ── New messages, and reactions / unsends on the ones already shown ──
      async function poll() {
        if (polling || document.hidden) return;
        polling = true;
        try {
          const r = await post({ action: 'poll', with: PARTNER_ID, after: lastId });
          if (r.success) {
            r.messages.forEach(addMessage);
            (r.state || []).forEach(s => {
              const wrap = list.querySelector('.msg[data-id="' + s.id + '"]');
              if (!wrap) return;
              if (s.unsent && !wrap.classList.contains('unsent')) markUnsent(wrap);
              else if (!s.unsent) renderReactions(wrap, s.reactions);
            });
            if (r.messages.length) SFInbox.refresh();
          }
        } catch (e) { /* try again next time */ }
        polling = false;
      }

      // The default chat (no ?with=) counts as read only when it's actually visible (not on phones)
      async function markSeen() {
        const active = document.querySelector('#convoGroup .convo-item.active');
        if (!list.offsetParent || !active || !active.classList.contains('has-unread')) return;
        try {
          const r = await post({ action: 'read_thread', with: PARTNER_ID });
          if (!r.success) return;
          active.classList.remove('has-unread');
          const dot = active.querySelector('.convo-dot');
          if (dot) dot.remove();
          SFInbox.show(r);
        } catch (e) { /* stays unread */ }
      }

      <?php echo json_encode($threadData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>.forEach(addMessage);
      scrollToEnd();
      markSeen();
      window.addEventListener('resize', markSeen);
      setInterval(poll, 4000);
      document.addEventListener('visibilitychange', poll);
    })();
    <?php endif; ?>
  </script>
  <?php sf_render_tabbar('messages'); ?>
</body>
</html>
