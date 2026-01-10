// kiosk-runtime.js
(() => {
  const qs = new URLSearchParams(location.search);
  if (qs.get('kiosk') !== '1') return;

// Ensure kiosk-collapsible sections start collapsed
document.addEventListener('DOMContentLoaded', () => {
  document
    .querySelectorAll('details[data-kiosk-collapse="1"]')
    .forEach(d => d.open = false);
});


  const playlist = readJson('jwg_kiosk_playlist', []);
  const config   = readJson('jwg_kiosk_config', {});
  const idxSaved = parseInt(localStorage.getItem('jwg_kiosk_idx') || '0', 10);

  if (!Array.isArray(playlist) || playlist.length === 0) return;

  // --- Behaviour knobs ---
  // “Lock speed to viewport height”: seconds per screenful (not px/sec).
  // You can override by setting in jwg_kiosk_config, or via kiosk.html query later if you like.
  const secondsPerScreen = num(config.secondsPerScreen, 10); // e.g. 8–14 feels good

  const dwellMs       = num(config.dwell, 10000);
  const topPauseMs    = num(config.toppause, 700);
  const bottomPauseMs = num(config.bottompause, 700);
  const minScrollPx   = num(config.minscroll, 80);

  // Fade timings (ms)
  const fadeMs        = num(config.fadeMs, 350);     // duration of fade animation
  const fadeHoldMs    = num(config.fadeHoldMs, 60);  // tiny hold at full black before navigation

  // Determine current page index based on pathname
  const current = normalisePath(location.pathname);
  let idx = playlist.findIndex(p => p === current);
  if (idx < 0) idx = (Number.isFinite(idxSaved) ? idxSaved : 0);
  localStorage.setItem('jwg_kiosk_idx', String(idx));

  // --- Hidden exit combo: Ctrl + Alt + Shift + X ---
  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.altKey && e.shiftKey && (e.code === 'KeyX')) {
      e.preventDefault();
      exitKiosk();
      return;
    }
  }, { passive: false });

  function exitKiosk() {
    try {
      localStorage.removeItem('jwg_kiosk_playlist');
      localStorage.removeItem('jwg_kiosk_config');
      localStorage.removeItem('jwg_kiosk_idx');
    } catch {}
    // Go back to normal home page
    location.href = '/index.html';
  }

  // --- Pause toggle: P only (as per your stabilised setup) ---
  let paused = false;
  document.addEventListener('keydown', (e) => {
    if (e.code === 'KeyP') paused = !paused;
  });

  // --- Fade overlay ---
  const veil = ensureVeil();
  //Start faded-in very briefly during load, then fade out after load settles
  veil.style.opacity = '1';
  window.addEventListener('load', () => {
    setTimeout(() => fadeTo(0, fadeMs), 120);
    setTimeout(run, 250);
  });

  // Do NOT start black on load (this is what creates the 1–2s black screen on heavier pages).
  // Keep veil available for the pre-navigation fade only.
  //veil.style.opacity = '0';

  // Start the kiosk behaviour as soon as DOM is ready (don't wait for all images/fonts).
  //const start = () => setTimeout(run, 0);
  //if (document.readyState === 'loading') {
  //  document.addEventListener('DOMContentLoaded', start, { once: true });
  //} else {
    //start();
  //}

  async function run() {
    await pauseWait(topPauseMs);

    const maxScroll = Math.max(
      0,
      document.documentElement.scrollHeight - window.innerHeight
    );

    // If not meaningfully scrollable, just dwell
    if (maxScroll < minScrollPx) {
      await pauseWait(dwellMs);
      return goNext();
    }

    // Scroll at “seconds per screenful”
    window.scrollTo(0, 0);

    const viewportH = Math.max(1, window.innerHeight || 1);
    const pxPerSec  = viewportH / Math.max(1, secondsPerScreen);
    const durationMs = (maxScroll / Math.max(10, pxPerSec)) * 1000;

    await animateScroll(0, maxScroll, durationMs);

    await pauseWait(bottomPauseMs);
    goNext();
  }

  async function goNext() {
    const nextIdx = (idx + 1) % playlist.length;
    localStorage.setItem('jwg_kiosk_idx', String(nextIdx));

    // Fade in to mask navigation
    await fadeTo(1, fadeMs);
    await pauseWait(fadeHoldMs);

    const nextUrl = addKioskParam(playlist[nextIdx]);
    location.href = nextUrl;
  }

  function addKioskParam(url) {
    const sep = url.includes('?') ? '&' : '?';
    const cb  = (config.cachebust === false) ? '' : `&kiosk_ts=${Date.now()}`;
    return `${url}${sep}kiosk=1${cb}`;
  }

  function animateScroll(from, to, durationMs) {
    return new Promise((resolve) => {
      const t0 = performance.now();

      function step(now) {
        if (paused) return requestAnimationFrame(step);

        const t = Math.min(1, (now - t0) / Math.max(1, durationMs));
        const y = from + (to - from) * t;
        window.scrollTo(0, y);

        if (t >= 1 || Math.abs(window.scrollY - to) < 2) return resolve();
        requestAnimationFrame(step);
      }

      requestAnimationFrame(step);
    });
  }

  async function pauseWait(ms) {
    const end = Date.now() + ms;
    while (Date.now() < end) {
      if (!paused) {
        const remaining = end - Date.now();
        await sleep(Math.min(150, remaining));
      } else {
        await sleep(150);
      }
    }
  }

  function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
  function num(v, fb) { const x = Number(v); return Number.isFinite(x) ? x : fb; }

  function readJson(key, fb) {
    try { return JSON.parse(localStorage.getItem(key) || ''); } catch { return fb; }
  }

  function normalisePath(pathname) {
    let p = (pathname || '').replace(/\\/g, '/');
    if (p.startsWith('/')) p = p.slice(1);
    if (p === '') p = 'index.html';
    return p;
  }

  function ensureVeil() {
  let el = document.getElementById('jwg-kiosk-veil');
  if (el) return el;

  el = document.createElement('div');
  el.id = 'jwg-kiosk-veil';
  el.style.position = 'fixed';
  el.style.inset = '0';

  // Softer than pure black (tweak to match your site palette)
  el.style.background = '#fdf8ea;';

  el.style.pointerEvents = 'none';
  el.style.opacity = '0';
  el.style.transition = `opacity ${fadeMs}ms ease`;
  el.style.zIndex = '2147483647';

  // Centred loading message (hidden unless veil is up)
  const msg = document.createElement('div');
  msg.id = 'jwg-kiosk-veil-msg';
  msg.textContent = 'Loading next page…';
  msg.style.position = 'absolute';
  msg.style.left = '50%';
  msg.style.top = '50%';
  msg.style.transform = 'translate(-50%, -50%)';
  msg.style.fontFamily = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
  msg.style.fontSize = '20px';
  msg.style.letterSpacing = '0.2px';
  msg.style.color = 'rgba(255,255,255,0.88)';
  msg.style.background = 'rgba(0,0,0,0.25)';
  msg.style.padding = '10px 14px';
  msg.style.borderRadius = '10px';
  msg.style.userSelect = 'none';
  msg.style.display = 'none';

  el.appendChild(msg);

  document.documentElement.appendChild(el);
  return el;
}

function fadeTo(opacity, ms) {
  // Ensure transition matches current ms if changed
  veil.style.transition = `opacity ${ms}ms ease`;
  veil.style.opacity = String(opacity);

  // Toggle message visibility based on veil opacity
  const msg = document.getElementById('jwg-kiosk-veil-msg');
  if (msg) msg.style.display = (opacity > 0.05 ? 'block' : 'none');

  return sleep(ms);
}

})();
