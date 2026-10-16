/**
 * Account dashboard motion (styles: the DASHBOARD MOTION section of css/account.css)
 *  - stat numbers count up from 0 ("12", "₱1,250", "4.8 ★" keep their format)
 *  - rows in each panel appear one after another
 * Nothing happens for people who ask their device for reduced motion.
 */
(function () {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // Rows: give each visible row its place in the list, used as an animation delay
  document.querySelectorAll('.account-layout .panel').forEach(panel => {
    let i = 0;
    panel.querySelectorAll('.row-item:not([hidden]), .listing-row:not([hidden])').forEach(row => {
      row.style.setProperty('--row-i', i++);
    });
  });

  // Stat numbers: count up, keeping any text around the number (₱, ★, commas, decimals)
  document.querySelectorAll('.account-layout .stat-value').forEach(el => {
    if (el.children.length) return;                       // only plain text values
    const text = el.textContent.trim();
    const m = text.match(/^(\D*?)([\d,]+(?:\.\d+)?)(.*)$/);
    if (!m) return;
    const [, before, numText, after] = m;
    const target = parseFloat(numText.replace(/,/g, ''));
    if (!isFinite(target) || target === 0) return;
    const decimals = (numText.split('.')[1] || '').length;
    const format = n => before + n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + after;
    const duration = 900, delay = 250;
    el.textContent = format(0);
    setTimeout(() => {
      const start = performance.now();
      const step = now => {
        const t = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - t, 3);              // slows down at the end
        el.textContent = t < 1 ? format(Math.round(target * eased * 10 ** decimals) / 10 ** decimals) : text;
        if (t < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    }, delay);
  });
})();
