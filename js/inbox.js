/* ==========================================================================
   STATION FINDS — NAVBAR UNREAD MARKERS
   Include on any page with the navbar:  <script src="js/inbox.js"></script>
   Asks inbox_handler.php for unread counts and shows a dot on the bell and
   a count next to "Messages". Refreshes every 30 seconds.
   With Push Notifications on (Account → Preferences) it also shows a browser
   pop-up for each new notification while the site is open.

   SFInbox.refresh()          fetch the counts again
   SFInbox.show(counts)       draw { notifications, messages }
   ========================================================================== */
(function () {
  const HANDLER = 'api/inbox_handler.php';

  function show(counts) {
    if (!counts) return;
    injectStyles();

    document.querySelectorAll('a.icon-btn[href="notifications.php"]').forEach(bell => {
      let dot = bell.querySelector('.sf-notif-dot');
      if (!dot) {
        dot = document.createElement('span');
        dot.className = 'sf-notif-dot';
        bell.appendChild(dot);
      }
      const n = parseInt(counts.notifications, 10) || 0;
      dot.style.display = n > 0 ? '' : 'none';
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
      .sf-notif-dot {
        position: absolute; top: 5px; right: 6px;
        width: 8px; height: 8px; border-radius: 50%;
        background: #d4a843; box-shadow: 0 0 0 2px #18232A;
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
    if (window.SF_GUEST) return;   // nothing to count for guests
    if (!document.querySelector('a.icon-btn[href="notifications.php"], a.nav-link[href="messages.php"]')) return;
    refresh();
    setInterval(refresh, 30000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
