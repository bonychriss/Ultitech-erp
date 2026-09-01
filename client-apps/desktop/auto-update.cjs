const { app, dialog, ipcMain } = require('electron');
const { autoUpdater } = require('electron-updater');
const { loadConfig } = require('./load-config.cjs');

/** @type {() => import('electron').BrowserWindow | null} */
let getMainWindow = () => null;

let manualCheckPending = false;
let pendingUpdateVersion = null;

function getUpdateFeedUrl() {
  const config = loadConfig();
  const url = String(config.updateFeedUrl || 'https://ultitech.io/client-apps/desktop/updates/').trim();
  return url.endsWith('/') ? url : `${url}/`;
}

function sendToRenderer(channel, payload) {
  const win = getMainWindow();
  if (win && !win.isDestroyed()) {
    win.webContents.send(channel, payload || {});
  }
}

function setupAutoUpdater(mainWindowGetter) {
  getMainWindow = mainWindowGetter;

  if (!app.isPackaged) {
    return;
  }

  autoUpdater.autoDownload = false;
  autoUpdater.autoInstallOnAppQuit = true;
  autoUpdater.logger = null;

  autoUpdater.setFeedURL({
    provider: 'generic',
    url: getUpdateFeedUrl(),
  });

  ipcMain.on('ultitech:update-download', () => {
    autoUpdater.downloadUpdate().catch((error) => {
      sendToRenderer('ultitech:update-dismiss');
      const win = getMainWindow();
      dialog.showMessageBox(win && !win.isDestroyed() ? win : undefined, {
        type: 'error',
        title: 'Update failed',
        message: 'Could not download the update.',
        detail: String(error?.message || error),
        buttons: ['OK'],
      });
    });
  });

  ipcMain.on('ultitech:update-install', () => {
    autoUpdater.quitAndInstall(false, true);
  });

  ipcMain.on('ultitech:update-dismiss', () => {
    sendToRenderer('ultitech:update-dismiss');
  });

  autoUpdater.on('error', async (error) => {
    sendToRenderer('ultitech:update-dismiss');
    if (!manualCheckPending) {
      return;
    }
    manualCheckPending = false;
    const win = getMainWindow();
    await dialog.showMessageBox(win && !win.isDestroyed() ? win : undefined, {
      type: 'error',
      title: 'Update failed',
      message: 'Could not check or download the update.',
      detail: String(error?.message || error),
      buttons: ['OK'],
    });
  });

  autoUpdater.on('update-not-available', async () => {
    if (!manualCheckPending) {
      return;
    }
    manualCheckPending = false;
    sendToRenderer('ultitech:update-up-to-date', { version: app.getVersion() });
  });

  autoUpdater.on('update-available', (info) => {
    manualCheckPending = false;
    pendingUpdateVersion = info.version || null;
    sendToRenderer('ultitech:update-available', { version: pendingUpdateVersion });
  });

  autoUpdater.on('download-progress', (progress) => {
    const win = getMainWindow();
    if (win && !win.isDestroyed() && typeof progress.percent === 'number') {
      win.setProgressBar(Math.max(0, Math.min(1, progress.percent / 100)));
    }
    sendToRenderer('ultitech:update-downloading', {
      version: pendingUpdateVersion,
      percent: progress.percent,
    });
  });

  autoUpdater.on('update-downloaded', (info) => {
    const win = getMainWindow();
    if (win && !win.isDestroyed()) {
      win.setProgressBar(-1);
    }
    pendingUpdateVersion = info.version || pendingUpdateVersion;
    sendToRenderer('ultitech:update-ready', { version: pendingUpdateVersion });
  });
}

function scheduleBackgroundUpdateCheck() {
  if (!app.isPackaged) {
    return;
  }
  setTimeout(() => {
    autoUpdater.checkForUpdates().catch(() => {
      /* silent background check */
    });
  }, 2000);
}

async function checkForUpdatesManual() {
  if (!app.isPackaged) {
    const win = getMainWindow();
    await dialog.showMessageBox(win && !win.isDestroyed() ? win : undefined, {
      type: 'info',
      title: 'Desktop updates',
      message: 'Automatic updates run in the installed Windows app.',
      detail: 'Build the installer with npm run dist:win, then publish latest.yml and the .exe to the server.',
      buttons: ['OK'],
    });
    return;
  }
  manualCheckPending = true;
  await autoUpdater.checkForUpdates();
}

async function checkForUpdatesSilent() {
  if (!app.isPackaged) {
    return;
  }
  await autoUpdater.checkForUpdates().catch(() => {
    /* silent background check */
  });
}

module.exports = {
  setupAutoUpdater,
  scheduleBackgroundUpdateCheck,
  checkForUpdatesManual,
  checkForUpdatesSilent,
};
