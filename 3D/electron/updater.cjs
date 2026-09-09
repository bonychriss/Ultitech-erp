const { dialog } = require('electron');
const { autoUpdater } = require('electron-updater');

let mainWindow = null;
let updateCheckPromise = null;

function getMainWindow() {
  if (mainWindow && !mainWindow.isDestroyed()) {
    return mainWindow;
  }
  return null;
}

function sendUpdateStatus(payload) {
  const win = getMainWindow();
  if (win && !win.isDestroyed()) {
    win.webContents.send('bcut:update-status', payload);
  }
}

function showDialog(options) {
  const win = getMainWindow();
  if (win && !win.isDestroyed()) {
    return dialog.showMessageBox(win, options);
  }
  return dialog.showMessageBox(options);
}

function registerAutoUpdaterHandlers() {
  autoUpdater.autoDownload = true;
  autoUpdater.autoInstallOnAppQuit = true;
  autoUpdater.allowDowngrade = false;

  autoUpdater.on('checking-for-update', () => {
    sendUpdateStatus({ status: 'checking' });
  });

  autoUpdater.on('update-available', (info) => {
    sendUpdateStatus({
      status: 'available',
      version: info.version,
      releaseDate: info.releaseDate
    });
  });

  autoUpdater.on('update-not-available', (info) => {
    sendUpdateStatus({
      status: 'not-available',
      version: info?.version
    });
  });

  autoUpdater.on('download-progress', (progress) => {
    sendUpdateStatus({
      status: 'downloading',
      percent: progress.percent,
      transferred: progress.transferred,
      total: progress.total
    });
  });

  autoUpdater.on('update-downloaded', (info) => {
    sendUpdateStatus({
      status: 'downloaded',
      version: info.version
    });

    showDialog({
      type: 'info',
      title: 'Update ready',
      message: `BCUT ${info.version} has been downloaded.`,
      detail: 'Restart the application to install the update.',
      buttons: ['Restart now', 'Later'],
      defaultId: 0,
      cancelId: 1
    }).then(({ response }) => {
      if (response === 0) {
        autoUpdater.quitAndInstall(false, true);
      }
    });
  });

  autoUpdater.on('error', (err) => {
    console.error('[BCUT updater]', err);
    sendUpdateStatus({
      status: 'error',
      message: err?.message || String(err)
    });
  });
}

function scheduleStartupCheck() {
  if (process.env.BCUT_DISABLE_UPDATES === '1') {
    return;
  }

  setTimeout(() => {
    checkForUpdates({ silent: true }).catch((err) => {
      console.error('[BCUT updater] startup check failed:', err);
    });
  }, 8000);
}

function initAutoUpdater(win) {
  if (process.env.BCUT_DISABLE_UPDATES === '1') {
    return;
  }

  mainWindow = win;
  registerAutoUpdaterHandlers();
  scheduleStartupCheck();
}

async function checkForUpdates({ silent = false } = {}) {
  if (process.env.BCUT_DISABLE_UPDATES === '1') {
    return null;
  }

  if (updateCheckPromise) {
    return updateCheckPromise;
  }

  updateCheckPromise = (async () => {
    sendUpdateStatus({ status: 'checking' });
    const result = await autoUpdater.checkForUpdates();

    if (!silent && result?.updateInfo == null) {
      await showDialog({
        type: 'info',
        title: 'No updates',
        message: 'You are running the latest version of BCUT.',
        buttons: ['OK']
      });
    }

    return result;
  })()
    .catch(async (err) => {
      sendUpdateStatus({
        status: 'error',
        message: err?.message || String(err)
      });

      if (!silent) {
        await showDialog({
          type: 'error',
          title: 'Update check failed',
          message: 'Could not check for updates.',
          detail: err?.message || String(err),
          buttons: ['OK']
        });
      }

      throw err;
    })
    .finally(() => {
      updateCheckPromise = null;
    });

  return updateCheckPromise;
}

module.exports = {
  initAutoUpdater,
  checkForUpdates
};
