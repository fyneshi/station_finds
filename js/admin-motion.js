/**
 * Admin motion (styles: the MOTION section of css/admin-shell.css)
 *  - the page's entrance plays once: .adm-enter comes off afterwards, so rows a search
 *    or filter brings back later just appear
 *  - stat numbers count up from 0 ("12", "₱1,250", "4.8" keep their format). The count is
 *    drawn over the real number, which never changes, so exports still read the true value.
 * Nothing happens for people who ask their device for reduced motion.
 */
(function () {
  const main = document.querySelector('.adm-main');
  if (main) setTimeout(() => main.classList.remove('adm-enter'), 1500);
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  document.querySelectorAll('.adm-main .stat-value').forEach(el => {
    if (el.children.length) return;                       // only plain text values
    const text = el.textContent.trim();
    const m = text.match(/^(\D*?)([\d,]+(?:\.\d+)?)(.*)$/);
    if (!m) return;
    const [, before, numText, after] = m;
    const target = parseFloat(numText.replace(/,/g, ''));
    if (!isFinite(target) || target === 0) return;
    const decimals = (numText.split('.')[1] || '').length;
    const format = n => before + n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + after;
    const duration = 800, delay = 200;
    el.style.setProperty('--adm-count-color', getComputedStyle(el).color);
    el.dataset.count = format(0);
    setTimeout(() => {
      const start = performance.now();
      const step = now => {
        const t = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - t, 3);              // slows down at the end
        if (t < 1) {
          el.dataset.count = format(Math.round(target * eased * 10 ** decimals) / 10 ** decimals);
          requestAnimationFrame(step);
        } else {
          delete el.dataset.count;                         // back to the real number
          el.style.removeProperty('--adm-count-color');
        }
      };
      requestAnimationFrame(step);
    }, delay);
  });
})();
