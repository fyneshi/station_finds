/* ==========================================================================
   STATION FINDS — NAVBAR UNREAD MARKERS AND THE NOTIFICATIONS PANEL
   Include on any page with the navbar:  <script src="js/inbox.js"></script>
   Asks inbox_handler.php for unread counts and shows a red count on the bell
   and a count next to "Messages". Refreshes every 30 seconds.
   The bell opens a drop-down panel (like Facebook's, and the admin bell):
   All / Unread, New and Earlier, Mark all as read, and See all (notifications.php).
   With Push Notifications on (Account → Preferences) it also shows a browser
   pop-up for each new notification while the site is open.

   SFInbox.refresh()          fetch the counts again
   SFInbox.show(counts)       draw { notifications, messages }
   ========================================================================== */
(function () {
  const HANDLER = 'api/inbox_handler.php';
  const BELL = 'a.icon-btn[href="notifications.php"]';

  function post(payload) {
    return fetch(HANDLER, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    }).then(res => res.json());
  }

  function show(counts) {
    if (!counts) return;
    injectStyles();

    document.querySelectorAll(BELL).forEach(bell => {
      let badge = bell.querySelector('.sf-notif-count');
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'sf-notif-count';
        bell.appendChild(badge);
      }
      const n = parseInt(counts.notifications, 10) || 0;
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.style.display = n > 0 ? '' : 'none';
      bell.setAttribute('aria-label', n > 0 ? 'Notifications (' + n + ' unread)' : 'Notifications');
    });

    document.querySelectorAll('a.nav-link[href="messages.php"]').forEach(link => {
      let pill = link.querySelector('.sf-msg-count');
      if (!pill) {
        pill = document.createElement('span');
        pill.className = 'sf-msg-count';
        link.appendChild(pill);
      }
      const n = parseInt(counts.messages, 10) || 0;
      pill.textContent = n > 9 ? '9+' : String(n);
      pill.style.display = n > 0 ? '' : 'none';
    });
  }

  function refresh() {
    return fetch(HANDLER, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ action: 'unread' })
    })
      .then(res => res.json())
      .then(r => { if (r && r.success) { show(r); popUp(r); } return r; })
      .catch(() => {});
  }

  // ── The bell's panel ──
  // Icon and colour for each kind of notification (the same icons as notifications.php)
  const ICONS = {
    message:  ['<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>', 'gold'],
    order:    ['<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path>', 'gold'],
    payout:   ['<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>', 'green'],
    price:    ['<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>', 'red'],
    security: ['<rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>', ''],
    info:     ['<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>', ''],
  };
  let panel = null, bellLink = null, items = [], unreadOnly = false;

  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function buildPanel(bell) {
    panel = el('div', 'sf-bell-panel');
    panel.id = 'sfBellPanel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Notifications');
    panel.hidden = true;
    panel.innerHTML =
      '<div class="sf-bell-head"><h2>Notifications</h2><button type="button" class="sf-bell-read-all" hidden>Mark all as read</button></div>' +
      '<div class="sf-bell-tabs" role="tablist">' +
      '<button type="button" class="sf-bell-tab active" role="tab" aria-selected="true" data-show="all">All</button>' +
      '<button type="button" class="sf-bell-tab" role="tab" aria-selected="false" data-show="unread">Unread</button></div>' +
      '<div class="sf-bell-list"></div>' +
      '<a href="notifications.php" class="sf-bell-see-all">See all notifications</a>';
    bell.parentElement.appendChild(panel);

    panel.querySelectorAll('.sf-bell-tab').forEach(tab => tab.addEventListener('click', () => {
      unreadOnly = tab.dataset.show === 'unread';
      panel.querySelectorAll('.sf-bell-tab').forEach(t => { t.classList.toggle('active', t === tab); t.setAttribute('aria-selected', String(t === tab)); });
      render();
    }));
    panel.querySelector('.sf-bell-read-all').addEventListener('click', async () => {
      try {
        const r = await post({ action: 'mark_all_read' });
        if (!r.success) throw new Error();
        items.forEach(i => { i.unread = false; });
        render();
        show(r);
        document.querySelectorAll('.notif-item.unread').forEach(n => n.classList.remove('unread'));   // notifications.php
      } catch (e) {
        if (window.SFCart) SFCart.toast('Could not update your notifications. Please try again.', true);
      }
    });
  }

  // New (today) and Earlier, like Facebook
  function render() {
    const list = panel.querySelector('.sf-bell-list');
    list.replaceChildren();
    const shown = items.filter(i => !unreadOnly || i.unread);
    panel.querySelector('.sf-bell-read-all').hidden = !items.some(i => i.unread);
    if (!shown.length) {
      list.append(el('p', 'sf-bell-empty', unreadOnly ? 'No unread notifications.' : 'No notifications yet. Messages, order updates and account changes will show up here.'));
      return;
    }
    [['New', shown.filter(i => i.today)], ['Earlier', shown.filter(i => !i.today)]].forEach(([heading, group]) => {
      if (!group.length) return;
      list.append(el('p', 'sf-bell-section', heading));
      group.forEach(i => {
        const row = el(i.link ? 'a' : 'button', 'sf-bell-item' + (i.unread ? ' unread' : ''));
        if (i.link) row.href = i.link; else row.type = 'button';
        const [paths, tone] = ICONS[i.type] || ICONS.info;
        const icon = el('span', 'sf-bell-icon ' + tone);
        icon.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
        const text = el('span', 'sf-bell-text');
        const line = el('span', 'sf-bell-line');
        line.append(el('strong', '', i.title));
        if (i.body) line.append(document.createTextNode(' · ' + i.body));
        text.append(line, el('span', 'sf-bell-time', i.time));
        row.append(icon, text);
        if (i.unread) row.append(el('span', 'sf-bell-dot'));
        row.addEventListener('click', e => openItem(e, i, row));
        list.append(row);
      });
    });
  }

  // Mark it read, then follow its link
  function openItem(e, item, row) {
    if (!item.unread) return;
    if (item.link && (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0)) return;
    e.preventDefault();
    item.unread = false;
    const go = r => { if (r && r.success) show(r); if (item.link) window.location.href = item.link; else render(); };
    post({ action: 'read', id: item.id }).then(go, () => go(null));
  }

  async function load() {
    const list = panel.querySelector('.sf-bell-list');
    if (!items.length) list.replaceChildren(el('p', 'sf-bell-empty', 'Loading…'));
    try {
      const r = await post({ action: 'list' });
      if (!r.success) throw new Error();
      items = r.items;
      show(r);
      render();
    } catch (e) {
      list.replaceChildren(el('p', 'sf-bell-empty', 'Could not load your notifications. Please try again.'));
    }
  }

  function setOpen(open) {
    panel.hidden = !open;
    bellLink.setAttribute('aria-expanded', String(open));
    bellLink.classList.toggle('panel-open', open);
    if (open) load();
  }

  function initPanel() {
    bellLink = document.querySelector('.navbar ' + BELL);
    if (!bellLink) return;
    injectStyles();
    buildPanel(bellLink);
    bellLink.setAttribute('aria-haspopup', 'dialog');
    bellLink.setAttribute('aria-expanded', 'false');
    bellLink.addEventListener('click', e => {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;   // new tab: the full page
      e.preventDefault();
      setOpen(panel.hidden);
    });
    document.addEventListener('click', e => {
      if (!panel.hidden && !panel.contains(e.target) && !bellLink.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && !panel.hidden) { setOpen(false); bellLink.focus(); }
    });
  }

  // Browser pop-up for notifications newer than the last one shown on this device.
  // The first check only remembers where we are, so old alerts don't pop up.
  const SEEN_KEY = 'sfPushSeen';
  function popUp(r) {
    if (!r.push || !('Notification' in window) || Notification.permission !== 'granted') return;
    let seen = null;
    try { seen = parseInt(localStorage.getItem(SEEN_KEY), 10); } catch (e) { return; }
    const newest = r.latest ? r.latest.ts : r.now;     // server time when there's nothing unread
    try { localStorage.setItem(SEEN_KEY, String(Math.max(newest, seen || 0))); } catch (e) { /* private mode */ }
    if (!seen || !r.latest || r.latest.ts <= seen) return;

    const note = new Notification(r.latest.title, {
      body: r.latest.body,
      icon: 'assets/images/logo2.png',
      tag: 'sf-' + r.latest.id + '-' + r.latest.ts    // one pop-up even with several tabs open
    });
    note.onclick = () => { window.focus(); window.location.href = r.latest.link; note.close(); };
  }

  function injectStyles() {
    if (document.getElementById('sfInboxStyles')) return;
    const style = document.createElement('style');
    style.id = 'sfInboxStyles';
    style.textContent = `
      .sf-notif-count {
        position: absolute; top: -3px; right: -5px;
        min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px;
        background: #e41e3f; color: #fff; font-size: 10.5px; font-weight: 800; line-height: 18px; text-align: center;
        box-shadow: 0 0 0 2px #18232A;
      }
      .sf-msg-count {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 17px; height: 17px; padding: 0 4px; margin-left: 6px;
        border-radius: 9px; background: #d4a843; color: #111827;
        font-size: 10px; font-weight: 800; line-height: 1; vertical-align: 1px;
      }
    `;
    document.head.appendChild(style);
  }

  window.SFInbox = { refresh: refresh, show: show };

  function init() {
    if (window.SF_GUEST) return;   // nothing to count for guests (js/guest.js asks them to log in)
    if (!document.querySelector(BELL + ', a.nav-link[href="messages.php"]')) return;
    initPanel();
    refresh();
    setInterval(refresh, 30000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
