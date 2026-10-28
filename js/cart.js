/* ==========================================================================
   STATION FINDS — SHARED CART
   Include on any page:  <script src="js/cart.js"></script>
   Talks to cart_handler.php (JSON) and keeps the navbar cart badge up to date.

   SFCart.add(id, qty, units)  add a listing (units = rental days / service hours)
   SFCart.post(payload)        send any cart_handler.php action, returns its JSON
   SFCart.setBadge(count)      update the navbar badge
   SFCart.toast(message, isError)
   ========================================================================== */
(function () {
  const HANDLER = 'api/cart_handler.php';

  // Send an action to cart_handler.php and return its JSON reply.
  // Throws only when the server can't be reached or doesn't reply with JSON.
  async function post(payload) {
    let res;
    try {
      res = await fetch(HANDLER, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload)
      });
    } catch (e) {
      throw new Error('Could not reach the server. Check your connection and try again.');
    }

    let data;
    try {
      data = await res.json();
    } catch (e) {
      throw new Error('Something went wrong. Please try again.');
    }

    // Session expired: send the user to the login page
    if (res.status === 401) {
      toast(data.message || 'Please log in first.', true);
      setTimeout(() => { window.location.href = 'index.php?next=' + encodeURIComponent(location.pathname.split('/').pop() + location.search); }, 1200);
    }
    return data;
  }

  // Add a listing; shows an error toast and throws if it couldn't be added
  async function add(id, qty, units) {
    const payload = { action: 'add', id: String(id), qty: qty || 1 };
    if (units !== undefined && units !== null) payload.units = units;

    let r;
    try {
      r = await post(payload);
    } catch (e) {
      toast(e.message, true);
      throw e;
    }
    if (!r.success) {
      const msg = r.message || 'Could not add this item to your cart.';
      if (r.message !== 'Please log in first.') toast(msg, true);
      const err = new Error(msg);
      err.data = r;   // e.g. r.stock when there isn't enough left
      throw err;
    }
    setBadge(r.count);
    return r;
  }

  function setBadge(count) {
    count = parseInt(count, 10) || 0;
    document.querySelectorAll('.cart-badge').forEach(badge => {
      badge.textContent = count > 9 ? '9+' : String(count);
      badge.style.display = count > 0 ? '' : 'none';
    });
  }

  // Small pop-up message at the bottom of the screen
  let toastTimer = null;
  function toast(message, isError) {
    if (!message) return;
    injectStyles();
    let el = document.getElementById('sfToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'sfToast';
      el.className = 'sf-toast';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    el.textContent = message;
    el.classList.toggle('sf-toast-error', !!isError);
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
  }

  function injectStyles() {
    if (document.getElementById('sfToastStyles')) return;
    const style = document.createElement('style');
    style.id = 'sfToastStyles';
    style.textContent = `
      .sf-toast {
        position: fixed; left: 50%; bottom: 28px; z-index: 2000;
        transform: translate(-50%, 20px); opacity: 0; pointer-events: none;
        max-width: calc(100% - 32px);
        background: #18232A; color: #ffffff;
        padding: 12px 20px; border-radius: 10px;
        font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 14px; font-weight: 600; line-height: 1.4; text-align: center;
        border-left: 4px solid #d4a843;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        transition: opacity 0.2s ease, transform 0.2s ease;
      }
      .sf-toast.show { opacity: 1; transform: translate(-50%, 0); }
      .sf-toast.sf-toast-error { border-left-color: #ef4444; }
    `;
    document.head.appendChild(style);
  }

  // "Buy now": check out just this item (the cart is left alone); shows an error toast and throws if it can't
  async function buyNow(id, qty, units) {
    const payload = { action: 'buy_now', id: String(id), qty: qty || 1 };
    if (units !== undefined && units !== null) payload.units = units;
    let r;
    try {
      r = await post(payload);
    } catch (e) {
      toast(e.message, true);
      throw e;
    }
    if (!r.success) {
      const msg = r.message || 'Could not start checkout for this item.';
      if (r.message !== 'Please log in first.') toast(msg, true);
      const err = new Error(msg);
      err.data = r;   // e.g. r.stock when there isn't enough left
      throw err;
    }
    return r;
  }

  window.SFCart = { add: add, buyNow: buyNow, post: post, setBadge: setBadge, toast: toast };

  // ── The cart icon's drop-down: "Recently Added Products" (like Shopee) ──
  // Ctrl/Cmd-click still opens the cart page; View My Shopping Cart goes there too.
  let cartPanel = null, cartLink = null;
  const make = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  async function fillCartPanel() {
    const list = cartPanel.querySelector('.sf-cart-list');
    if (!list.children.length) list.append(make('p', 'sf-cart-empty', 'Loading…'));
    try {
      const r = await post({ action: 'preview' });
      if (!r.success) throw new Error();
      setBadge(r.count);
      list.replaceChildren();
      if (!r.items.length) {
        list.append(make('p', 'sf-cart-empty', 'Your cart is empty. Find something you like in the marketplace.'));
      }
      r.items.forEach(i => {
        const row = make('a', 'sf-cart-item');
        row.href = i.link;
        const img = make('img');
        img.src = i.image; img.alt = ''; img.loading = 'lazy';
        const name = make('span', 'sf-cart-name', i.title);
        if (i.quantity > 1) name.append(make('small', '', ' × ' + i.quantity));
        row.append(img, name, make('span', 'sf-cart-price', i.price));
        list.append(row);
      });
      if (r.more) list.append(make('p', 'sf-cart-more', r.more + ' more item' + (r.more === 1 ? '' : 's') + ' in your cart'));
    } catch (e) {
      list.replaceChildren(make('p', 'sf-cart-empty', 'Could not load your cart. Please try again.'));
    }
  }

  function setCartOpen(open) {
    cartPanel.hidden = !open;
    cartLink.setAttribute('aria-expanded', String(open));
    cartLink.classList.toggle('panel-open', open);
    if (open) fillCartPanel();
  }

  function initCartPanel() {
    cartLink = document.querySelector('.navbar a.cart-btn');
    if (!cartLink) return;
    cartPanel = make('div', 'sf-cart-panel');
    cartPanel.setAttribute('role', 'dialog');
    cartPanel.setAttribute('aria-label', 'Recently added products');
    cartPanel.hidden = true;
    const viewCart = make('a', 'sf-cart-view', 'View My Shopping Cart');
    viewCart.href = 'cart.php';
    cartPanel.append(make('p', 'sf-cart-head', 'Recently Added Products'), make('div', 'sf-cart-list'), viewCart);
    cartLink.parentElement.appendChild(cartPanel);
    cartLink.setAttribute('aria-haspopup', 'dialog');
    cartLink.setAttribute('aria-expanded', 'false');
    cartLink.addEventListener('click', e => {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
      setCartOpen(cartPanel.hidden);
    });
    document.addEventListener('click', e => {
      if (!cartPanel.hidden && !cartPanel.contains(e.target) && !cartLink.contains(e.target)) setCartOpen(false);
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && !cartPanel.hidden) { setCartOpen(false); cartLink.focus(); }
    });
  }

  // Show the right count in the navbar badge on page load
  function init() {
    if (window.SF_GUEST) return;   // guests have no cart (js/guest.js asks them to log in)
    if (!document.querySelector('.cart-badge')) return;
    initCartPanel();
    post({ action: 'count' })
      .then(r => { if (r && r.success) setBadge(r.count); })
      .catch(() => {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();