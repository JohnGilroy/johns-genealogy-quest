(function () {

  // -------------------------
  // State
  // -------------------------
  let kioskPaused = false;

  function pauseKiosk() {
    kioskPaused = true;
    document.body.classList.add('kiosk-paused');
  }

  function resumeKiosk() {
    kioskPaused = false;
    document.body.classList.remove('kiosk-paused');
  }

  function togglePause() {
    kioskPaused ? resumeKiosk() : pauseKiosk();
  }

  function toggleHelp() {
    const el = document.getElementById('kiosk-help');
    if (el) el.classList.toggle('hidden');
  }

  // -------------------------
  // Inject CSS
  // -------------------------
  const style = document.createElement('style');
  style.textContent = `
    .kiosk-help {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }
    .kiosk-help.hidden { display: none; }

    .kiosk-help-card {
      background: #fff;
      color: #222;
      padding: 20px 28px;
      border-radius: 12px;
      box-shadow: 0 8px 30px rgba(0,0,0,0.25);
      max-width: 320px;
      text-align: center;
      font-family: system-ui, sans-serif;
    }

    .kiosk-help-card h3 {
      margin-top: 0;
      margin-bottom: 12px;
    }

    .kiosk-help-card ul {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    .kiosk-help-card li {
      margin: 8px 0;
      font-size: 1rem;
    }

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
      z-index: 9999;
    }
  `;
  document.head.appendChild(style);

  // -------------------------
  // Inject HTML
  // -------------------------
  const help = document.createElement('div');
  help.id = 'kiosk-help';
  help.className = 'kiosk-help hidden';
  help.innerHTML = `
    <div class="kiosk-help-card">
      <h3>Kiosk Controls</h3>
      <ul>
        <li><strong>Space</strong> – Pause / Resume</li>
        <li><strong>H</strong> – Show / Hide help</li>
      </ul>
    </div>
  `;
  document.body.appendChild(help);

  // -------------------------
  // Keyboard handling
  // -------------------------
  document.addEventListener('keydown', function (e) {
    if (e.code === 'Space') {
      e.preventDefault();
      togglePause();
    }
    if (e.key === 'h' || e.key === 'H') {
      toggleHelp();
    }
  });

  // -------------------------
  // Public hook for existing timers
  // -------------------------
  window.isKioskPaused = function () {
    return kioskPaused;
  };

})();
