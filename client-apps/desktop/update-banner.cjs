/**
 * Forwards Electron auto-updater IPC events to the hosted ERP page.
 * UI is rendered by assets/js/desktop-update-banner.js (loaded from ERP header).
 * @param {typeof import('electron').ipcRenderer} ipcRenderer
 */
function setupUpdateBanner(ipcRenderer) {
  function notifyPage(type, payload) {
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

  ipcRenderer.on('ultitech:update-available', (_event, payload) => {
    notifyPage('available', {
      version: payload && payload.version ? String(payload.version) : null,
    });
  });

  ipcRenderer.on('ultitech:update-downloading', (_event, payload) => {
    notifyPage('downloading', {
      version: payload?.version ? String(payload.version) : null,
      percent: payload?.percent,
    });
  });

  ipcRenderer.on('ultitech:update-ready', (_event, payload) => {
    notifyPage('ready', {
      version: payload && payload.version ? String(payload.version) : null,
    });
  });

  ipcRenderer.on('ultitech:update-dismiss', () => {
    notifyPage('dismiss');
  });

  ipcRenderer.on('ultitech:update-up-to-date', (_event, payload) => {
    notifyPage('up-to-date', {
      version: payload?.version || null,
    });
  });
}

module.exports = { setupUpdateBanner };
