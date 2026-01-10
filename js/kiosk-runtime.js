// kiosk-runtime.js
console.log('[kiosk-runtime v3] running', location.href);

(() => {
  const qs = new URLSearchParams(location.search);
  if (qs.get('kiosk') !== '1') return;

  // Prevent browser restoring scroll positions across navigations
  try {
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  } catch {}

  // Ensure kiosk-collapsible sections start collapsed
  document.addEventListener('DOMContentLoaded', () => {
    document
      .querySelectorAll('details[data-kiosk-collapse="1"]')
      .forEach(d => d.open = false);
  });

  const playlist = readJson('jwg_kiosk_playlist', []);
  const config   = readJson('jwg_kiosk_config', {});
  const titles   = readJson('jwg_kiosk_titles', {});

  if (!Array.isArray(playlist) || playlist.length === 0) return;

  // --- Behaviour knobs ---
  const secondsPerScreen = num(config.secondsPerScreen, 10);

  const dwellMs       = num(config.dwell, 10000);
  const topPauseMs    = num(config.toppause, 700);
  const bottomPauseMs = num(config.bottompause, 700);
  const minScrollPx   = num(config.minscroll, 80);

  // Fade timings (ms)
  const fadeMs        = num(config.fadeMs, 350);
  const fadeHoldMs    = num(config.fadeHoldMs, 60);

  // Fixed veil duration on the *next page* (ms)
 let transitionMs = num(config.transition, 1200);
if (!(transitionMs > 0)) transitionMs = 1200;

  const idxSaved = parseInt(localStorage.getItem('jwg_kiosk_idx') || '0', 10);

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
      localStorage.removeItem('jwg_kiosk_titles');
      localStorage.removeItem('jwg_kiosk_idx');
    } catch {}
    location.href = '/index.html';
  }

  // --- Pause toggle: P only ---
  let paused = false;
  document.addEventListener('keydown', (e) => {
    if (e.code === 'KeyP') paused = !paused;
  });

  // --- Fade overlay ---
  const veil = ensureVeil();

  // Querystring-driven fixed veil on *this* page (set by previous page)
  const kv = parseInt(qs.get('kv') || '0', 10);
  const kmRaw = qs.get('km') || '';
  const incomingText = kmRaw ? decodeURIComponent(kmRaw) : '';
  if (incomingText) setVeilMessage(incomingText);

  // If kv is present, start covered and hold for exactly kv ms (identical across pages)
  if (Number.isFinite(kv) && kv > 0) {
    veil.style.opacity = '1';

    const start = () => {
      // Ensure we start from top consistently
      try { window.scrollTo(0, 0); } catch {}

      setTimeout(async () => {
        await fadeTo(0, fadeMs);
        run();
      }, kv);
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
      start();
    }
    return; // IMPORTANT: don't run the normal startup path too
  }

  // Normal startup (no kv): start with veil hidden and run when DOM is ready
  veil.style.opacity = '0';
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      try { window.scrollTo(0, 0); } catch {}
      run();
    }, { once: true });
  } else {
    try { window.scrollTo(0, 0); } catch {}
    run();
  }

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

    // Start at top for scrollable pages
    window.scrollTo(0, 0);

    // Scroll at “seconds per screenful”
    const viewportH  = Math.max(1, window.innerHeight || 1);
    const pxPerSec   = viewportH / Math.max(1, secondsPerScreen);
    const durationMs = (maxScroll / Math.max(10, pxPerSec)) * 1000;

    await animateScroll(0, maxScroll, durationMs);

    await pauseWait(bottomPauseMs);
    return goNext();
  }

  async function goNext() {
    const nextIdx = (idx + 1) % playlist.length;
    localStorage.setItem('jwg_kiosk_idx', String(nextIdx));

let nextPath = String(playlist[nextIdx] || '').trim();

// Normalise for title lookup (manifest keys never start with '/')
const titleKey = nextPath.replace(/^\/+/, '');

// Build a site-relative URL that works locally AND on GitHub project pages
const nextUrl = toSiteUrl(nextPath);

const nextTitle = (titles && typeof titles === 'object')
  ? String(titles[titleKey] ?? '').trim()
  : '';

    console.log(nextTitle);
    const veilText = nextTitle ? `Coming next… ${nextTitle}` : 'Loading next page…';

    // Show message during fade-out of current page
    setVeilMessage(veilText);

    // Fade in veil to mask navigation
    await fadeTo(1, fadeMs);

    // Small readable hold (kept small to avoid Chrome throttling)
    const hold = Math.min(fadeHoldMs, 250);
    if (hold > 0) await pauseWait(hold);

    // Navigate to next page, passing fixed veil duration + message for next page
   const u = new URL(addKioskParam(nextPath), location.href);
    u.searchParams.set('kv', String(transitionMs));
    u.searchParams.set('km', veilText); // URLSearchParams will encode it
    console.log('[kiosk-runtime] navigating to:', u.toString());

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

    // Pick a colour that looks deliberate during transitions.
    // (Change to match your site palette.)
    el.style.background = '#fdf8ea';

    el.style.pointerEvents = 'none';
    el.style.opacity = '0';
    el.style.transition = `opacity ${fadeMs}ms ease`;
    el.style.zIndex = '2147483647';

    const msgEl = document.createElement('div');
    msgEl.id = 'jwg-kiosk-veil-msg';
    msgEl.textContent = 'Loading next page…';
    msgEl.style.position = 'absolute';
    msgEl.style.left = '50%';
    msgEl.style.top = '50%';
    msgEl.style.transform = 'translate(-50%, -50%)';
    msgEl.style.fontFamily = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
    msgEl.style.fontSize = '20px';
    msgEl.style.letterSpacing = '0.2px';
    msgEl.style.color = 'rgba(255,255,255,0.88)';
    msgEl.style.background = 'rgba(0,0,0,0.25)';
    msgEl.style.padding = '10px 14px';
    msgEl.style.borderRadius = '10px';
    msgEl.style.userSelect = 'none';
    msgEl.style.display = 'block';

    el.appendChild(msgEl);
    document.documentElement.appendChild(el);
    return el;
  }

  function setVeilMessage(text) {
    const msgEl = document.getElementById('jwg-kiosk-veil-msg');
    if (msgEl) msgEl.textContent = text || 'Loading next page…';
  }

  function fadeTo(opacity, ms) {
    veil.style.transition = `opacity ${ms}ms ease`;
    veil.style.opacity = String(opacity);

    return sleep(ms);
  }
  
  function siteBasePath() {
  // Prefer an explicit <base href="..."> if you ever add one
  const baseEl = document.querySelector('base[href]');
  if (baseEl) {
    try { return new URL(baseEl.getAttribute('href'), location.origin).pathname; } catch {}
  }

  // GitHub Pages project sites: https://user.github.io/<repo>/
  if (location.hostname.endsWith('github.io')) {
    const seg = (location.pathname.split('/').filter(Boolean)[0] || '').trim();
    if (seg) return `/${seg}/`;
  }

  // Local / normal sites
  return '/';
}

const SITE_BASE = siteBasePath();

function toSiteUrl(path) {
  let p = String(path || '').trim();
  if (!p) return location.href;

  // absolute URL already?
  if (/^https?:\/\//i.test(p)) return p;

  // normalise to *relative to site base*
  p = p.replace(/^\/+/, ''); // remove leading slashes
  return new URL(SITE_BASE + p, location.origin).toString();
}

})();
