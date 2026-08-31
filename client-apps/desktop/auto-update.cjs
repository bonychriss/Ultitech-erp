const { app, dialog } = require('electron');
const { autoUpdater } = require('electron-updater');
const { loadConfig } = require('./load-config.cjs');

/** @type {() => import('electron').BrowserWindow | null} */
let getMainWindow = () => null;

let manualCheckPending = false;

function getUpdateFeedUrl() {
  const config = loadConfig();
  const url = String(config.updateFeedUrl || 'https://ultitech.io/client-apps/desktop/updates/').trim();
  return url.endsWith('/') ? url : `${url}/`;
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

  autoUpdater.on('error', async (error) => {
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
    const win = getMainWindow();
    await dialog.showMessageBox(win && !win.isDestroyed() ? win : undefined, {
      type: 'info',
      title: 'Up to date',
      message: 'You already have the latest UltiTech ERP desktop app.',
      detail: `Current version: ${app.getVersion()}`,
      buttons: ['OK'],
    });
  });

  autoUpdater.on('update-available', async (info) => {
    manualCheckPending = false;
    const win = getMainWindow();
    const { response } = await dialog.showMessageBox(win && !win.isDestroyed() ? win : undefined, {
      type: 'info',
      title: 'Update available',
      message: `UltiTech ERP ${info.version} is available.`,
      detail: `You are on ${app.getVersion()}.\n\nDownload and install the update now?`,
      buttons: ['Download', 'Later'],
      defaultId: 0,
      cancelId: 1,
      noLink: true,
    });
    if (response === 0) {
      await autoUpdater.downloadUpdate();
    }
  });

  autoUpdater.on('download-progress', (progress) => {
    const win = getMainWindow();
    if (win && !win.isDestroyed() && typeof progress.percent === 'number') {
      win.setProgressBar(Math.max(0, Math.min(1, progress.percent / 100)));
    }
  });

  autoUpdater.on('update-downloaded', async (info) => {
    const win = getMainWindow();
    if (win && !win.isDestroyed()) {
      win.setProgressBar(-1);
    }
    const { response } = await dialog.showMessageBox(win && !win.isDestroyed() ? win : undefined, {
      type: 'info',
      title: 'Update ready',
      message: `Version ${info.version} has been downloaded.`,
      detail: 'Restart now to install the update.',
      buttons: ['Restart now', 'Later'],
      defaultId: 0,
      cancelId: 1,
      noLink: true,
    });
    if (response === 0) {
      autoUpdater.quitAndInstall(false, true);
    }
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
  }, 10000);
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

module.exports = {
  setupAutoUpdater,
  scheduleBackgroundUpdateCheck,
  checkForUpdatesManual,
};
