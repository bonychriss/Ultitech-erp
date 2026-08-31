/**
 * Injected into ERP pages via preload ù VS Code-style update bar at the bottom.
 * @param {typeof import('electron').ipcRenderer} ipcRenderer
 */
function setupUpdateBanner(ipcRenderer) {
  const BANNER_ID = 'ultitech-update-banner';
  const GIFT_ICON =
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="8" width="18" height="13" rx="1.5"/><path d="M12 8V21M12 8c-1.8 0-3-1.2-3-3s1.2-3 3-3 3 1.2 3 3-1.2 3-3 3zM3 12h18M7.5 8C6 6.5 6 4 8 4s2.5 2 4 4M16.5 8C18 6.5 18 4 16 4s-2.5 2-4 4"/></svg>';

  function isSelectModulePage() {
    try {
      return /select-module/i.test(window.location.pathname || '');
    } catch {
      return false;
    }
  }

  function notifySelectModule(type, payload) {
    try {
      window.dispatchEvent(
        new CustomEvent('ultitech:desktop-update', {
          detail: { type, ...(payload || {}) },
        })
      );
    } catch {
      /* ignore */
    }
  }

  /** @type {'available' | 'downloading' | 'ready' | null} */
  let state = null;
  /** @type {string | null} */
  let version = null;

  function removeBanner() {
    const existing = document.getElementById(BANNER_ID);
    if (existing) {
      existing.remove();
    }
    state = null;
    version = null;
  }

  function ensureStyles() {
    if (document.getElementById('ultitech-update-banner-styles')) {
      return;
    }
    const style = document.createElement('style');
    style.id = 'ultitech-update-banner-styles';
    style.textContent = `
      #${BANNER_ID} {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 48px;
        background: #2d2d2d;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 20px;
        z-index: 2147483647;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 13px;
        color: #cccccc;
        box-shadow: 0 -1px 6px rgba(0, 0, 0, 0.35);
        animation: ultitech-banner-in 0.25s ease-out;
      }
      @keyframes ultitech-banner-in {
        from { transform: translateY(100%); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
      }
      #${BANNER_ID} .ultitech-update-left {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
      }
      #${BANNER_ID} .ultitech-update-icon {
        display: flex;
        color: #e0e0e0;
        flex-shrink: 0;
      }
      #${BANNER_ID} .ultitech-update-text {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      #${BANNER_ID} .ultitech-update-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
      }
      #${BANNER_ID} .ultitech-btn-later {
        background: none;
        border: none;
        color: #cccccc;
        font: inherit;
        cursor: pointer;
        padding: 6px 10px;
        border-radius: 4px;
      }
      #${BANNER_ID} .ultitech-btn-later:hover {
        background: rgba(255, 255, 255, 0.08);
      }
      #${BANNER_ID} .ultitech-btn-primary {
        background: #0078d4;
        border: none;
        color: #fff;
        font: inherit;
        font-weight: 500;
        cursor: pointer;
        padding: 6px 16px;
        border-radius: 4px;
      }
      #${BANNER_ID} .ultitech-btn-primary:hover {
        background: #1084d8;
      }
      #${BANNER_ID} .ultitech-btn-primary:disabled {
        opacity: 0.65;
        cursor: default;
      }
    `;
    document.head.appendChild(style);
  }

  function messageForCurrentState() {
    if (state === 'downloading') {
      return 'Downloading updateù';
    }
    if (state === 'ready') {
      return version ? `Update ${version} ready to install` : 'Update ready to install';
    }
    return 'New update available';
  }

  function primaryLabel() {
    if (state === 'ready') {
      return 'Install Now';
    }
    if (state === 'downloading') {
      return 'Downloadingù';
    }
    return 'Install Now';
  }

  function renderBanner() {
    if (!state) {
      removeBanner();
      return;
    }

    ensureStyles();

    let banner = document.getElementById(BANNER_ID);
    if (!banner) {
      banner = document.createElement('div');
      banner.id = BANNER_ID;
      banner.setAttribute('role', 'status');
      document.body.appendChild(banner);
    }

    banner.innerHTML = `
      <div class="ultitech-update-left">
        <span class="ultitech-update-icon">${GIFT_ICON}</span>
        <span class="ultitech-update-text">${messageForCurrentState()}</span>
      </div>
      <div class="ultitech-update-actions">
        <button type="button" class="ultitech-btn-later" data-action="later">Later</button>
        <button type="button" class="ultitech-btn-primary" data-action="primary">${primaryLabel()}</button>
      </div>
    `;

    const laterBtn = banner.querySelector('[data-action="later"]');
    const primaryBtn = banner.querySelector('[data-action="primary"]');

    laterBtn.addEventListener('click', () => {
      removeBanner();
      ipcRenderer.send('ultitech:update-dismiss');
    });

    primaryBtn.disabled = state === 'downloading';
    primaryBtn.addEventListener('click', () => {
      if (state === 'ready') {
        ipcRenderer.send('ultitech:update-install');
        return;
      }
      if (state === 'available') {
        state = 'downloading';
        renderBanner();
        ipcRenderer.send('ultitech:update-download');
      }
    });
  }

  ipcRenderer.on('ultitech:update-available', (_event, payload) => {
    state = 'available';
    version = payload && payload.version ? String(payload.version) : null;
    notifySelectModule('available', { version });
    if (isSelectModulePage()) {
      return;
    }
    renderBanner();
  });

  ipcRenderer.on('ultitech:update-downloading', (_event, payload) => {
    state = 'downloading';
    if (payload && payload.version) {
      version = String(payload.version);
    }
    notifySelectModule('downloading', {
      version,
      percent: payload?.percent,
    });
    if (isSelectModulePage()) {
      return;
    }
    renderBanner();
    const text = document.querySelector(`#${BANNER_ID} .ultitech-update-text`);
    if (text && typeof payload?.percent === 'number') {
      text.textContent = `Downloading updateÖ ${Math.round(payload.percent)}%`;
    }
  });

  ipcRenderer.on('ultitech:update-ready', (_event, payload) => {
    state = 'ready';
    version = payload && payload.version ? String(payload.version) : version;
    notifySelectModule('ready', { version });
    if (isSelectModulePage()) {
      return;
    }
    renderBanner();
  });

  ipcRenderer.on('ultitech:update-dismiss', () => {
    notifySelectModule('dismiss');
    removeBanner();
  });

  ipcRenderer.on('ultitech:update-up-to-date', (_event, payload) => {
    notifySelectModule('up-to-date', { version: payload?.version || null });
    if (isSelectModulePage()) {
      state = null;
      version = null;
      return;
    }
    state = 'available';
    version = null;
    removeBanner();
    ensureStyles();
    const toast = document.createElement('div');
    toast.id = BANNER_ID;
    toast.setAttribute('role', 'status');
    toast.innerHTML = `
      <div class="ultitech-update-left">
        <span class="ultitech-update-icon">${GIFT_ICON}</span>
        <span class="ultitech-update-text">You have the latest version${payload?.version ? ` (${payload.version})` : ''}</span>
      </div>
      <div class="ultitech-update-actions">
        <button type="button" class="ultitech-btn-later" data-action="later">OK</button>
      </div>
    `;
    document.body.appendChild(toast);
    toast.querySelector('[data-action="later"]').addEventListener('click', () => {
      toast.remove();
      state = null;
    });
    setTimeout(() => {
      if (toast.parentNode) {
        toast.remove();
        state = null;
      }
    }, 4000);
  });
}

module.exports = { setupUpdateBanner };
