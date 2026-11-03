/**
 * Site-wide motion (styles: the MOTION section of css/theme.css).
 * Cards and rows further down the page rise in as they scroll into view; the ones
 * entering together follow one another. Only cards that start below the screen are
 * hidden, so nothing already on screen blinks. Home (js/home-motion.js) and the
 * account dashboard (js/dashboard-motion.js) have their own entrances.
 */
(function () {
  if (window.sfHomeMotion || !('IntersectionObserver' in window)) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const SELECTOR = '.listing-card, .search-card, .fav-card, .pf-listing, .review-card, .comment-item:not([hidden]), ' +
                   '.cart-item, .contact-card, .policy-section, .notif-item, .about-section';
  const below = window.innerHeight;
  const items = [...document.querySelectorAll(SELECTOR)].filter(el => {
    if (el.closest('.account-layout, .navbar, .footer')) return false;
    const box = el.getBoundingClientRect();
    return box.height > 0 && box.top > below;   // hidden (display: none) and on-screen ones are left alone
  });
  if (!items.length) return;

  // When the entrance ends, take the classes off so hover lifts work again
  const done = e => {
    if (e.target !== e.currentTarget) return;
    e.currentTarget.classList.remove('sf-reveal', 'is-in');
    e.currentTarget.style.removeProperty('--sf-i');
    e.currentTarget.removeEventListener('animationend', done);
  };
  items.forEach(el => { el.classList.add('sf-reveal'); el.addEventListener('animationend', done); });

  const watcher = new IntersectionObserver(entries => {
    entries.filter(entry => entry.isIntersecting).forEach((entry, i) => {
      entry.target.style.setProperty('--sf-i', Math.min(i, 6));
      entry.target.classList.add('is-in');
      watcher.unobserve(entry.target);
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });
  items.forEach(el => watcher.observe(el));
})();
