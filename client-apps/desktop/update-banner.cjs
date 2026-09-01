/**
 * Forwards Electron auto-updater IPC events to the hosted ERP page.
 * Queues events until the page is ready so early update checks are not lost.
 * @param {typeof import('electron').ipcRenderer} ipcRenderer
 */
function setupUpdateBanner(ipcRenderer) {
  /** @type {{ type: string, payload?: Record<string, unknown> } | null} */
  let pending = null;

  function notifyPage(type, payload) {
    const detail = { type, ...(payload || {}) };
    pending = detail;

    try {
      window.dispatchEvent(
        new CustomEvent('ultitech:desktop-update', {
          detail,
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

  try {
    Object.defineProperty(window, '__ULTITECH_DESKTOP_UPDATE_PENDING__', {
      configurable: true,
      get() {
        return pending;
      },
    });
  } catch {
    /* ignore */
  }
}

module.exports = { setupUpdateBanner };
