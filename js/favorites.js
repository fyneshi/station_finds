/* ==========================================================================
   STATION FINDS — SHARED FAVORITES
   Include on any page with heart buttons:  <script src="js/favorites.js"></script>
   Buttons call toggleFavorite(this). Hearts are synced with the database
   through favorite_handler.php, so they stay saved across pages and logins.
   ========================================================================== */
(function () {
  const CARD_SEL = '.listing-card, .product-card, .search-card, .fav-card';
  const BTN_SEL  = '.card-fav-btn, .card-favorite-btn';
  let saved = new Set();

  function slug(str) {
    return String(str).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  function textOf(card, sel) {
    const el = card.querySelector(sel);
    return el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
  }

  function cardType(card) {
    if (card.dataset.type) return card.dataset.type;
    const view = card.closest('[id^="view-"]');
    if (view) {
      const t = view.id.replace('view-', '');
      if (['products', 'rentals', 'services'].includes(t)) return t;
    }
    if (card.querySelector('.badge-rental'))  return 'rentals';
    if (card.querySelector('.badge-service')) return 'services';
    return 'products';
  }

  function cardInfo(card) {
    const type  = cardType(card);
    const title = card.dataset.title || textOf(card, '.card-title, .card-product-title');
    const priceDisplay = card.dataset.priceDisplay || textOf(card, '.card-price');
    const price = card.dataset.price !== undefined
      ? parseFloat(card.dataset.price) || 0
      : parseFloat(priceDisplay.replace(/[^0-9.]/g, '')) || 0;
    const rating = card.dataset.rating !== undefined
      ? parseFloat(card.dataset.rating) || 0
      : parseFloat(textOf(card, '.rating-val, .rating-value, .card-rating span')) || 0;
    const img = card.querySelector('img.card-img');
    let link = card.dataset.link || '';
    if (!link) {
      const m = (card.getAttribute('onclick') || '').match(/href\s*=\s*'([^']+)'/);
      if (m) link = m[1];
    }

    return {
      key: card.dataset.favKey || (type + ':' + slug(title)),
      type: type,
      title: title,
      price: price,
      price_display: priceDisplay,
      rating: rating,
      badge_sub: card.dataset.badgeSub || textOf(card, '.badge-condition, .badge-subtle, .badge-service-cat'),
      image: img ? img.src : '',
      link: link
    };
  }

  function paint(btn, isFav) {
    btn.classList.toggle('active', isFav);
    btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
    btn.setAttribute('aria-label', isFav ? 'Remove from favorites' : 'Save item');
    const svg = btn.querySelector('svg');
    if (svg) {
      svg.setAttribute('fill', isFav ? '#ef4444' : 'none');
      svg.setAttribute('stroke', isFav ? '#ef4444' : 'currentColor');
    }
  }

  function refreshHearts() {
    document.querySelectorAll(BTN_SEL).forEach(btn => {
      const card = btn.closest(CARD_SEL);
      if (card) paint(btn, saved.has(cardInfo(card).key));
    });
  }

  async function post(payload) {
    const res = await fetch('api/favorite_handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    return res.json();
  }

  // Global so existing onclick="toggleFavorite(this)" attributes keep working
  window.toggleFavorite = function (btn) {
    const card = btn.closest(CARD_SEL);
    if (!card) return;

    const info = cardInfo(card);
    const willSave = !saved.has(info.key);

    // Optimistic update (also syncs duplicate cards of the same listing)
    willSave ? saved.add(info.key) : saved.delete(info.key);
    refreshHearts();

    const payload = willSave ? { action: 'add', item: info } : { action: 'remove', key: info.key };
    post(payload)
      .then(r => { if (!r || !r.success) throw new Error(r && r.message); })
      .catch(() => {
        willSave ? saved.delete(info.key) : saved.add(info.key);
        refreshHearts();
        alert('Could not update your favorites. Please try again.');
      });
  };

  window.SFFavorites = { post: post, cardInfo: cardInfo, refresh: refreshHearts };

  function init() {
    if (window.SF_GUEST) return;   // guests have no saved items
    // Clean up data from the old "removed favorites" approach
    try { localStorage.removeItem('sf_removed_favorites'); } catch (e) {}
    document.cookie = 'sf_removed_favorites=; path=/; max-age=0';

    post({ action: 'list' })
      .then(r => {
        if (r && r.success) {
          saved = new Set(r.keys);
          refreshHearts();
        }
      })
      .catch(() => {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();