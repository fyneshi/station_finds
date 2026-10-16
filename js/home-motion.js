/**
 * Home page motion (styles: the HOME MOTION section of css/home.css).
 * Headings and cards animate in as they scroll into view; cards in the same row
 * follow one another. home.php adds .js-motion to <html> before the page draws,
 * so they start hidden only when this script is going to show them.
 */
(function () {
  window.sfHomeMotion = true;
  const root = document.documentElement;
  if (!root.classList.contains('js-motion')) return;

  const items = [...document.querySelectorAll('.section-title, .product-card, .category-card')];
  // Position within its own grid, for the one-after-another delay
  document.querySelectorAll('.picks-grid, .category-grid').forEach(grid => {
    [...grid.children].forEach((card, i) => card.style.setProperty('--i', i));
  });

  const show = el => el.classList.add('is-in');
  // When a card's entrance ends, hand control back to its hover styles
  document.querySelectorAll('.product-card, .category-card').forEach(card => {
    card.addEventListener('animationend', e => { if (e.target === card) card.classList.add('is-done'); });
  });
  if (!('IntersectionObserver' in window)) { items.forEach(show); return; }

  const watcher = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      show(entry.target);
      watcher.unobserve(entry.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  items.forEach(el => watcher.observe(el));
})();
