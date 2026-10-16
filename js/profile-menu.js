/**
 * Right-click (or long-press, or the keyboard's menu key) on anything with
 * data-profile-id="<user id>" data-profile-name="<name>" to get a small menu:
 *   View profile  → profile.php?id=…
 * Used on messages.php.
 */
(function () {
  const menu = document.createElement('div');
  menu.className = 'profile-menu';
  menu.setAttribute('role', 'menu');
  menu.hidden = true;
  document.body.appendChild(menu);
  let opener = null;

  function item(label, href, icon) {
    const a = document.createElement('a');
    a.href = href;
    a.setAttribute('role', 'menuitem');
    a.innerHTML = icon;                      // fixed SVG markup only
    a.appendChild(document.createTextNode(label));
    return a;
  }
  const ICON_USER = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';

  function open(target, x, y) {
    const id = target.dataset.profileId;
    menu.replaceChildren();
    const title = document.createElement('div');
    title.className = 'profile-menu-title';
    title.textContent = target.dataset.profileName || 'Member';
    menu.append(title, item('View profile', 'profile.php?id=' + encodeURIComponent(id), ICON_USER));
    menu.hidden = false;
    // Keep it on screen
    const w = menu.offsetWidth, h = menu.offsetHeight;
    menu.style.left = Math.max(8, Math.min(x, window.innerWidth - w - 8)) + 'px';
    menu.style.top = Math.max(8, Math.min(y, window.innerHeight - h - 8)) + 'px';
    opener = target;
    menu.querySelector('[role="menuitem"]').focus();
  }

  function close(returnFocus) {
    if (menu.hidden) return;
    menu.hidden = true;
    if (returnFocus && opener) opener.focus();
    opener = null;
  }

  document.addEventListener('contextmenu', e => {
    const target = e.target.closest('[data-profile-id]');
    if (!target) { close(false); return; }
    e.preventDefault();
    // From the keyboard menu key the position is 0,0: open beside the element instead
    if (e.clientX === 0 && e.clientY === 0) {
      const r = target.getBoundingClientRect();
      open(target, r.left + 16, r.bottom - 4);
    } else {
      open(target, e.clientX, e.clientY);
    }
  });
  document.addEventListener('click', e => { if (!menu.contains(e.target)) close(false); });
  document.addEventListener('keydown', e => {
    if (menu.hidden) return;
    const items = [...menu.querySelectorAll('[role="menuitem"]')];
    const i = items.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); close(true); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    else if (e.key === 'Tab') close(false);
  });
  window.addEventListener('scroll', () => close(false), true);
  window.addEventListener('resize', () => close(false));
})();
