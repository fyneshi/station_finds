/* ==========================================================================
   STATION FINDS — BROWSING AS A GUEST
   Loaded by the header (includes/layout.php) only when nobody is logged in.
   Guests can look around freely; account features (notifications, messages,
   the cart, saved items, buying, booking, commenting, reporting) show a
   "Log in / Sign up" prompt instead. After logging in, the visitor comes back
   to this page (window.SF_LOGIN carries ?next=). The server checks too: every
   one of these features still requires a login there.
   ========================================================================== */
(function () {
  // What a guest clicked, and what the prompt says about it (first match wins)
  const GATES = [
    ['a[href^="notifications.php"]', 'Log in to see your notifications.'],
    ['a[href^="messages.php"], .detail-message-link', 'Log in to message sellers and read your conversations.'],
    ['#requestBtn, #buyNowBtn[onclick*="Rental"]', 'Log in to request this rental or service.'],
    ['a[href^="cart.php"], a[href^="checkout.php"], #addCartBtn, #buyNowBtn', 'Log in to add items to your cart and check out.'],
    ['a[href^="favorites.php"], .card-fav-btn, .card-favorite-btn, .detail-fav-btn', 'Log in to save listings you like.'],
    ['a[href^="account.php"], a[href^="listing_form.php"], a[href^="become_seller.php"]', 'Log in to see your account, orders and listings.'],
    ['.report-link, .report-card select, .report-card textarea, .report-card button', 'Log in to report a listing or a user.'],
    ['.comments-card textarea, .comments-card button:not(.comment-replies-toggle), .comments-card input[type="submit"]', 'Log in to ask a question or leave a comment.'],
    ['[data-guest-gate]', 'Log in or create an account to continue.'],
  ];
  const ALL = GATES.map(g => g[0]).join(', ');

  let dialog = null;
  function prompt(message) {
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.className = 'sf-guest-modal';
      dialog.setAttribute('aria-labelledby', 'sfGuestTitle');
      const login = window.SF_LOGIN || 'index.php';
      dialog.innerHTML =
        '<div class="sf-guest-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></div>' +
        '<h2 id="sfGuestTitle">Log in to continue</h2><p class="sf-guest-msg"></p>' +
        '<div class="sf-guest-actions"><a class="sf-guest-login"></a><a class="sf-guest-signup"></a></div>' +
        '<button type="button" class="sf-guest-later">Keep browsing</button>';
      const loginBtn = dialog.querySelector('.sf-guest-login');
      loginBtn.href = login;
      loginBtn.textContent = 'Log in';
      const signupBtn = dialog.querySelector('.sf-guest-signup');
      signupBtn.href = login + (login.indexOf('?') === -1 ? '?' : '&') + 'mode=signup';
      signupBtn.textContent = 'Sign up';
      dialog.querySelector('.sf-guest-later').addEventListener('click', () => dialog.close());
      dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
      document.body.appendChild(dialog);
    }
    dialog.querySelector('.sf-guest-msg').textContent = message + ' It only takes a minute to sign up.';
    if (!dialog.open) dialog.showModal();
  }

  // Runs before the page's own click handlers (capture phase), so they never fire for guests
  function gate(e) {
    const el = e.target.closest && e.target.closest(ALL);
    if (!el || (dialog && dialog.contains(el))) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const match = GATES.find(g => el.matches(g[0]));
    prompt(match ? match[1] : GATES[GATES.length - 1][1]);
  }
  window.addEventListener('click', gate, true);
  // Typing into a comment box opens the prompt too (e.g. reaching it with the Tab key)
  window.addEventListener('focusin', e => {
    if (e.target.matches && e.target.matches('.comments-card textarea')) { e.target.blur(); prompt(GATES[7][1]); }
  }, true);
  window.addEventListener('submit', e => {
    if (e.target.closest('.comments-card')) { e.preventDefault(); e.stopImmediatePropagation(); prompt(GATES[7][1]); }
  }, true);

  window.SFGuest = { prompt: prompt };
})();
