// kiosk-runtime.js
console.log('[kiosk-runtime v5] running', location.href);

(() => {
    
// --- Paused badge (visual feedback) ---
const pausedStyle = document.createElement('style');
pausedStyle.textContent = `
  body.kiosk-paused::after {
    content: "Paused";
    position: fixed;
    bottom: 12px;
    right: 16px;
    background: rgba(0,0,0,0.6);
    color: #fff;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 0.9rem;
    z-index: 2147483647;
  }
`;
document.head.appendChild(pausedStyle);

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

  if (!Array.isArray(playlist) || playlist.length === 0) return;

  // --- Behaviour knobs (single source of truth = jwg_kiosk_config written by kiosk.html) ---
  // IMPORTANT: Defaults here MUST match kiosk.html to avoid “why is it stuck?” confusion.

  // Scroll speed: px/sec (preferred + only speed knob)
  const pxPerSec      = num(config.speed, 50);        // kiosk.html default: 50 :contentReference[oaicite:1]{index=1}

  // Timings (ms)
  const dwellMs       = num(config.dwell, 11000);      // kiosk.html default: 11000 
  const topPauseMs    = num(config.toppause, 1000);    // kiosk.html default: 1000 
  const bottomPauseMs = num(config.bottompause, 1000); // kiosk.html default: 1000 

  // Treat pages as “not scrollable” if there’s less than this to scroll (px)
  const minScrollPx   = num(config.minscroll, 80);     // kiosk.html default: 80 :contentReference[oaicite:5]{index=5}

  // Fade timings (ms)
  const fadeMs        = num(config.fadeMs, 350);
  const fadeHoldMs    = num(config.fadeHoldMs, 60);

  // Fixed veil duration on the *next page* (ms)
  let transitionMs    = num(config.transition, 8000);  // kiosk.html default: 8000 :contentReference[oaicite:6]{index=6}
  if (!(transitionMs > 0)) transitionMs = 8000;

  // One-time resolved config log (helps catch stale localStorage vs expected URL overrides)
  console.log('[kiosk] knobs', {
    pxPerSec, dwellMs, topPauseMs, bottomPauseMs, minScrollPx,
    fadeMs, fadeHoldMs, transitionMs,
    cachebust: (config.cachebust !== false)
  });


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
    location.href = toSiteUrl('index.html');

  }

// --- Pause toggle: Space or P ---
let paused = false;

function togglePause() {
  paused = !paused;
  document.body.classList.toggle('kiosk-paused', paused);
}

document.addEventListener('keydown', (e) => {
  if (e.code === 'Space' || e.code === 'KeyP') {
      if (e.repeat) return;
    e.preventDefault();
    togglePause();
  }
});

// --- Touch controls (floating button) + long-press anywhere ---
const touchCss = document.createElement('style');
touchCss.textContent = `
  #jwg-kiosk-touchbar {
    position: fixed;
    left: 12px;
    bottom: 12px;
    display: flex;
    gap: 10px;
    z-index: 2147483647;
    pointer-events: auto;
  }
  .jwg-kiosk-btn {
    appearance: none;
    border: 0;
    border-radius: 14px;
    padding: 12px 14px;
    font: 700 15px/1 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    box-shadow: 0 6px 18px rgba(0,0,0,0.25);
    background: rgba(0,0,0,0.65);
    color: #fff;
    min-width: 120px;
    min-height: 44px; /* good touch size */
  }
  .jwg-kiosk-btn:active { transform: translateY(1px); }
  .jwg-kiosk-btn.secondary {
    background: rgba(255,255,255,0.85);
    color: #111;
  }
`;
document.head.appendChild(touchCss);

const touchBar = document.createElement('div');
touchBar.id = 'jwg-kiosk-touchbar';

const btnPause = document.createElement('button');
btnPause.className = 'jwg-kiosk-btn';
btnPause.type = 'button';

const btnHelp = document.createElement('button');
btnHelp.className = 'jwg-kiosk-btn secondary';
btnHelp.type = 'button';
btnHelp.textContent = 'Help';

function syncPauseLabel() {
  btnPause.textContent = paused ? 'Resume' : 'Pause';
}
syncPauseLabel();

btnPause.addEventListener('click', (e) => {
  e.preventDefault();
  togglePause();
  syncPauseLabel();
}, { passive: false });

btnHelp.addEventListener('click', (e) => {
  e.preventDefault();
  // toggleHelp() is defined in the Help overlay block above
  if (typeof toggleHelp === 'function') toggleHelp();
}, { passive: false });

touchBar.appendChild(btnPause);
touchBar.appendChild(btnHelp);
document.documentElement.appendChild(touchBar);

// Long-press anywhere (600ms) toggles pause (touch-friendly, avoids accidental taps)
let lpTimer = 0;
let lpFired = false;

function clearLongPress() {
  if (lpTimer) { clearTimeout(lpTimer); lpTimer = 0; }
  lpFired = false;
}

document.addEventListener('pointerdown', (e) => {
  // Ignore presses on actual controls/links/buttons/inputs
  const t = e.target;
  if (t && (t.closest?.('#jwg-kiosk-touchbar') || t.closest?.('a,button,input,select,textarea,label'))) return;

  clearLongPress();
  lpTimer = setTimeout(() => {
    lpFired = true;
    togglePause();
    syncPauseLabel();
    try { if (navigator.vibrate) navigator.vibrate(20); } catch {}
  }, 600);
}, { passive: true });

document.addEventListener('pointerup', clearLongPress, { passive: true });
document.addEventListener('pointercancel', clearLongPress, { passive: true });
document.addEventListener('pointermove', () => {
  // if finger/mouse moves, cancel long press
  if (lpTimer) clearLongPress();
}, { passive: true });


  // --- Fade overlay ---
const veil = ensureVeil();

const kv = parseInt(qs.get('kv') || '0', 10);

// URLSearchParams already decodes. Do NOT decodeURIComponent again.
const incomingText = String(qs.get('km') || '').trim();
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

// Scroll at px/sec (configured)
    const durationMs = (maxScroll / Math.max(10, pxPerSec)) * 1000;

    await animateScroll(0, maxScroll, durationMs);

    await pauseWait(bottomPauseMs);
    return goNext();
  }

  async function goNext() {
    const nextIdx = (idx + 1) % playlist.length;
    localStorage.setItem('jwg_kiosk_idx', String(nextIdx));

let nextPath = String(playlist[nextIdx] || '').trim();
if (!nextPath) nextPath = 'index.html';

// Titles map keys are stored without leading '/'
const titleKey = nextPath.replace(/^\/+/, '');
const nextTitle = (titles && typeof titles === 'object')
  ? String(titles[titleKey] ?? '').trim()
  : '';

const veilText = nextTitle ? `Coming next… ${nextTitle}` : 'Loading next page…';
setVeilMessage(veilText);

// Fade in veil to mask navigation
await fadeTo(1, fadeMs);

// Small readable hold (keep small to avoid Chrome throttling)
const hold = Math.min(fadeHoldMs, 250);
if (hold > 0) await pauseWait(hold);

// Build next URL under correct site base (works locally + GitHub project pages)
const u = new URL(toSiteUrl(nextPath));

// Required params so the next page stays in kiosk mode + shows fixed veil
u.searchParams.set('kiosk', '1');
if (config.cachebust !== false) u.searchParams.set('kiosk_ts', String(Date.now()));
u.searchParams.set('kv', String(transitionMs));
u.searchParams.set('km', veilText);

location.href = u.toString();


  }

  function addKioskParam(url) {
    const sep = url.includes('?') ? '&' : '?';
    const cb  = (config.cachebust === false) ? '' : `&kiosk_ts=${Date.now()}`;
    return `${url}${sep}kiosk=1${cb}`;
  }

function animateScroll(from, to, durationMs) {
  return new Promise((resolve) => {
    let t0 = performance.now();

    // Track pause time so elapsed excludes it
    let pauseStartedAt = 0;
    let pausedAccumMs = 0;

    function step(now) {
      if (paused) {
        // mark when we entered pause (once)
        if (!pauseStartedAt) pauseStartedAt = now;
        return requestAnimationFrame(step);
      }

      // if we just resumed, add paused duration
      if (pauseStartedAt) {
        pausedAccumMs += (now - pauseStartedAt);
        pauseStartedAt = 0;
      }

      const effectiveElapsed = now - t0 - pausedAccumMs;
      const t = Math.min(1, effectiveElapsed / Math.max(1, durationMs));
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
    msgEl.style.color = '#ffffff';
    msgEl.style.background = 'var(--jwg-blue)';
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
  

function toSiteUrl(path) {
  // Compute base at call time (no TDZ, no init-order issues)
  const base = siteBasePath();

  let p = String(path || '').trim();
  if (!p) return location.href;

  // absolute URL already?
  if (/^https?:\/\//i.test(p)) return p;

  // normalise to *relative to site base*
  p = p.replace(/^\/+/, '');
  return new URL(base + p, location.origin).toString();
}

// --- Kiosk help overlay (H key) ---
const help = document.createElement('div');
help.id = 'jwg-kiosk-help';
help.style.position = 'fixed';
help.style.inset = '0';
help.style.background = 'rgba(0,0,0,0.45)';
help.style.display = 'none';          // start hidden
help.style.alignItems = 'center';
help.style.justifyContent = 'center';
help.style.zIndex = '2147483646';

const card = document.createElement('div');
card.style.background = '#fff';
card.style.color = '#222';
card.style.padding = '20px 28px';
card.style.borderRadius = '12px';
card.style.boxShadow = '0 8px 30px rgba(0,0,0,0.25)';
card.style.fontFamily = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
card.style.textAlign = 'center';

card.innerHTML = `
  <h3 style="margin-top:0">Kiosk Controls</h3>
  <p><strong>Tap Pause</strong> – Pause / Resume</p>
  <p><strong>Long-press</strong> anywhere – Pause / Resume</p>
  <p><strong>H</strong> – Show / hide help</p>
  <p style="opacity:.7;font-size:.9em">Ctrl + Alt + Shift + X – Exit kiosk</p>
`;

help.appendChild(card);
document.documentElement.appendChild(help);

function toggleHelp() {
  const showing = (help.style.display === 'flex');
  help.style.display = showing ? 'none' : 'flex';
}

document.addEventListener('keydown', (e) => {
  if (e.code === 'KeyH') {
    e.preventDefault();
    toggleHelp();
  }
});


})();
