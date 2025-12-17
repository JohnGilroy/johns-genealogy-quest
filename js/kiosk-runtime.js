// kiosk-runtime.js
(() => {
  const qs = new URLSearchParams(location.search);
  if (qs.get('kiosk') !== '1') return;

// --- Fullscreen keep-alive (ChromeOS friendly) ---
function ensureFullscreen() {
  const el = document.documentElement;

  if (!document.fullscreenElement &&
      el.requestFullscreen) {
    el.requestFullscreen().catch(() => {
      /* ChromeOS may block occasionally; retry later */
    });
  }
}

// Try immediately and again shortly after load
window.addEventListener('load', () => {
  setTimeout(ensureFullscreen, 300);
  setTimeout(ensureFullscreen, 600);
});

// Also re-assert after navigation-triggered scroll completes
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) {
    setTimeout(ensureFullscreen, 100);
  }
});


  const playlist = readJson('jwg_kiosk_playlist', []);
  const config   = readJson('jwg_kiosk_config', {});
  const idxSaved = parseInt(localStorage.getItem('jwg_kiosk_idx') || '0', 10);

  if (!Array.isArray(playlist) || playlist.length === 0) return;

  const speedPxPerSec = num(config.speed, 80);
  const dwellMs       = num(config.dwell, 10000);
  const topPauseMs    = num(config.toppause, 700);
  const bottomPauseMs = num(config.bottompause, 700);
  const minScrollPx   = num(config.minscroll, 80);

  // Determine current page index based on pathname
  const current = normalisePath(location.pathname);
  let idx = playlist.findIndex(p => p === current);
  if (idx < 0) idx = (Number.isFinite(idxSaved) ? idxSaved : 0);

  // Ensure stored index is aligned with what we're showing
  localStorage.setItem('jwg_kiosk_idx', String(idx));

  // Optional: pause toggle (space)
let paused = false;

// Toggle pause with "P" only (keeps Chromebook fullscreen key untouched)
document.addEventListener('keydown', (e) => {
  if (e.code === 'KeyP') {
    paused = !paused;
  }
});


  // Wait for full load + a small settle
  window.addEventListener('load', () => {
    setTimeout(run, 250);
  });

  async function run() {
    await pauseWait(topPauseMs);

    const maxScroll = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);

    if (maxScroll < minScrollPx) {
      await pauseWait(dwellMs);
      return goNext();
    }

    // Scroll from top to bottom at constant speed
    window.scrollTo(0, 0);

    const durationMs = (maxScroll / Math.max(10, speedPxPerSec)) * 1000;
    await animateScroll(0, maxScroll, durationMs);

    await pauseWait(bottomPauseMs);
    goNext();
  }

  function goNext() {
    const nextIdx = (idx + 1) % playlist.length;
    localStorage.setItem('jwg_kiosk_idx', String(nextIdx));

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
    // convert "/bios/a.html" -> "bios/a.html"
    let p = (pathname || '').replace(/\\/g, '/');
    if (p.startsWith('/')) p = p.slice(1);
    if (p === '') p = 'index.html';
    return p;
  }
})();
