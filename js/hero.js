/* ==========================================================================
   STATION FINDS — HOME HERO: 3D EMBLEM TILT AND FEATURED LISTINGS (styles: HERO in css/home.css)
   The small "Featured" card under the logo shows one listing at a time and
   slides to the next every few seconds. Its header has one segment per
   listing (like stories): the current one fills up until the next, and
   clicking a segment jumps to it. It pauses while hovered or focused, and
   when the tab is hidden. With "reduce motion" on, it only moves on clicks.
   ========================================================================== */
(function () {
  // The 3D emblem leans toward the mouse (on desktops)
  const tilt = document.getElementById('heroTilt');
  if (tilt && window.matchMedia('(hover: hover)').matches && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const hero = tilt.closest('.hero');
    let frame = null;
    hero.addEventListener('mousemove', e => {
      if (frame) return;
      frame = requestAnimationFrame(() => {
        frame = null;
        const box = hero.getBoundingClientRect();
        tilt.style.setProperty('--tx', (((e.clientX - box.left) / box.width - 0.5) * 30).toFixed(1) + 'deg');
        tilt.style.setProperty('--ty', (((e.clientY - box.top) / box.height - 0.5) * -20).toFixed(1) + 'deg');
      });
    });
    hero.addEventListener('mouseleave', () => { tilt.style.removeProperty('--tx'); tilt.style.removeProperty('--ty'); });
  }
})();

(function () {
  const box = document.getElementById('heroFeature');
  if (!box) return;
  const items = [...box.querySelectorAll('.hero-feature-item')];
  const steps = [...box.querySelectorAll('.hero-feature-step')];
  if (items.length < 2) return;
  const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const DELAY = 3500;   // ms per listing (also the segment's fill time, see css/home.css)
  let active = 0, timer = null;

  function show(next) {
    if (next === active) return;
    items[active].classList.remove('is-active');
    items[active].classList.add('is-leaving');
    const leaving = items[active];
    setTimeout(() => leaving.classList.remove('is-leaving'), 600);
    items.forEach((item, i) => {
      item.tabIndex = i === next ? 0 : -1;
      item.setAttribute('aria-hidden', i === next ? 'false' : 'true');
    });
    items[next].classList.add('is-active');
    active = next;
    // Segments before this one are seen, this one fills up
    steps.forEach((step, i) => {
      step.classList.toggle('is-done', i < next);
      step.classList.toggle('is-current', i === next);
    });
    if (still || !timer) return;   // paused (hovered): the segment just shows full
    box.classList.remove('is-running');
    void box.offsetWidth;
    box.classList.add('is-running');
  }

  // Clicking a segment jumps to that listing (and keeps going from there)
  steps.forEach((step, i) => step.addEventListener('click', () => { show(i); if (!still && !box.matches(':hover')) start(); }));
  if (still) return;
  function start() {
    stop();
    box.classList.add('is-running');
    timer = setInterval(() => show((active + 1) % items.length), DELAY);
  }
  function stop() {
    clearInterval(timer);
    timer = null;
    box.classList.remove('is-running');
  }

  box.addEventListener('mouseenter', stop);
  box.addEventListener('mouseleave', start);
  box.addEventListener('focusin', stop);
  box.addEventListener('focusout', start);
  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
  start();
})();
