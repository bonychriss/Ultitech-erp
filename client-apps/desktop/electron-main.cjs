const { app, BrowserWindow, shell, session, dialog, Menu, ipcMain } = require('electron');
const path = require('node:path');
const { loadConfig, isAllowedNavigation, isErpOrigin } = require('./load-config.cjs');
const {
  setupAutoUpdater,
  scheduleBackgroundUpdateCheck,
  checkForUpdatesManual,
} = require('./auto-update.cjs');

const SESSION_PARTITION = 'persist:ultitech-erp';
const ERP_PERMISSIONS = new Set(['media', 'geolocation', 'fullscreen', 'clipboard-read']);

/** @type {import('electron').BrowserWindow | null} */
let mainWindow = null;
/** @type {import('electron').BrowserWindow | null} */
let splashWindow = null;
/** @type {ReturnType<typeof loadConfig> | null} */
let appConfig = null;
let isHandlingLoadError = false;

function getErpSession() {
  return session.fromPartition(SESSION_PARTITION);
}

function formatLoadError(error, errorCode, errorDescription) {
  if (error && error.message) {
    return error.message;
  }
  if (errorDescription) {
    return errorDescription;
  }
  if (typeof errorCode === 'number') {
    return `Network error (${errorCode})`;
  }
  return 'Unknown connection error';
}

function openExternalUrl(url) {
  if (!url || typeof url !== 'string') {
    return;
  }
  shell.openExternal(url).catch(() => {
    /* ignore */
  });
}

function handleDisallowedNavigation(url) {
  if (appConfig && appConfig.openExternalInBrowser !== false) {
    openExternalUrl(url);
  }
}

function configureWebContents(contents, config) {
  contents.setWindowOpenHandler(({ url }) => {
    if (isAllowedNavigation(url, config)) {
      return { action: 'allow' };
    }
    handleDisallowedNavigation(url);
    return { action: 'deny' };
  });

  contents.on('will-navigate', (event, url) => {
    if (!isAllowedNavigation(url, config)) {
      event.preventDefault();
      handleDisallowedNavigation(url);
    }
  });

  contents.on('will-redirect', (event, url) => {
    if (!isAllowedNavigation(url, config)) {
      event.preventDefault();
      handleDisallowedNavigation(url);
    }
  });

  contents.on('will-download', (_event, item) => {
    const downloadsDir = app.getPath('downloads');
    const filePath = path.join(downloadsDir, item.getFilename());
    item.setSavePath(filePath);
  });
}

function configurePermissionHandler(config) {
  getErpSession().setPermissionRequestHandler((webContents, permission, callback, details) => {
    const requestingUrl = details.requestingUrl || webContents.getURL();

    if (!isErpOrigin(requestingUrl, config)) {
      callback(false);
      return;
    }

    if (!ERP_PERMISSIONS.has(permission)) {
      callback(false);
      return;
    }

    if (permission === 'media') {
      callback(true);
      return;
    }

    if (permission === 'geolocation') {
      callback(true);
      return;
    }

    callback(true);
  });

  getErpSession().setPermissionCheckHandler((webContents, permission, requestingOrigin) => {
    if (!isErpOrigin(requestingOrigin, config)) {
      return false;
    }
    return ERP_PERMISSIONS.has(permission);
  });
}

async function showLoadErrorDialog(parentWindow, message, detail) {
  if (isHandlingLoadError) {
    return false;
  }
  isHandlingLoadError = true;

  try {
    const targetWindow = parentWindow && !parentWindow.isDestroyed() ? parentWindow : null;
    const { response } = await dialog.showMessageBox(targetWindow, {
      type: 'error',
      title: 'Unable to load UltiTech ERP',
      message,
      detail,
      buttons: ['Retry', 'Quit'],
      defaultId: 0,
      cancelId: 1,
      noLink: true,
    });
    return response === 0;
  } finally {
    isHandlingLoadError = false;
  }
}

async function loadErpIntoWindow(window) {
  appConfig = loadConfig();
  configurePermissionHandler(appConfig);

  const userAgent = window.webContents.getUserAgent() + (appConfig.userAgentSuffix || '');
  window.webContents.setUserAgent(userAgent);

  try {
    await window.loadURL(appConfig.activeUrl);
    return true;
  } catch (error) {
    const shouldRetry = await showLoadErrorDialog(
      splashWindow && !splashWindow.isDestroyed() ? splashWindow : window,
      'The application could not reach the server.',
      `${formatLoadError(error)}\n\nURL: ${appConfig.activeUrl}\n\nCheck your network connection or edit client-apps/desktop/config.json.`
    );
    if (shouldRetry) {
      return loadErpIntoWindow(window);
    }
    closeSplashWindow();
    app.quit();
    return false;
  }
}

function attachMainFrameFailureHandler(window) {
  window.webContents.on('did-fail-load', async (event, errorCode, errorDescription, validatedURL, isMainFrame) => {
    if (!isMainFrame) {
      return;
    }
    // ERR_ABORTED (-3) happens during intentional redirects/navigation.
    if (errorCode === -3) {
      return;
    }

    const shouldRetry = await showLoadErrorDialog(
      window,
      'The ERP server is unavailable or the connection failed.',
      `${formatLoadError(null, errorCode, errorDescription)}\n\nURL: ${validatedURL || appConfig?.activeUrl || ''}\n\nCheck your internet connection and try again.`
    );

    if (shouldRetry) {
      await loadErpIntoWindow(window);
    } else {
      closeSplashWindow();
      app.quit();
    }
  });
}

function closeSplashWindow() {
  if (splashWindow && !splashWindow.isDestroyed()) {
    splashWindow.close();
  }
  splashWindow = null;
}

function createSplashWindow(config) {
  splashWindow = new BrowserWindow({
    width: 1280,
    height: 860,
    minWidth: 960,
    minHeight: 640,
    frame: false,
    resizable: false,
    center: true,
    alwaysOnTop: true,
    show: false,
    backgroundColor: '#0f172a',
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  splashWindow.loadFile(path.join(__dirname, 'splash.html'), {
    query: { appName: config.appName || 'UltiTech ERP' },
  });

  splashWindow.once('ready-to-show', () => {
    if (splashWindow && !splashWindow.isDestroyed()) {
      splashWindow.show();
    }
  });

  splashWindow.on('closed', () => {
    splashWindow = null;
  });

  return splashWindow;
}

function waitForSplashDuration(config) {
  const ms = Math.max(1500, Number(config.splashDurationMs) || 2800);
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function waitForMainFramePaint(window) {
  return new Promise((resolve) => {
    if (!window || window.isDestroyed()) {
      resolve();
      return;
    }
    const { webContents } = window;
    const done = () => resolve();
    if (!webContents.isLoading()) {
      setTimeout(done, 120);
      return;
    }
    webContents.once('did-finish-load', () => {
      setTimeout(done, 120);
    });
  });
}

async function createWindow() {
  appConfig = loadConfig();
  configurePermissionHandler(appConfig);
  createSplashWindow(appConfig);

  mainWindow = new BrowserWindow({
    width: 1280,
    height: 860,
    minWidth: 960,
    minHeight: 640,
    title: appConfig.appName || 'UltiTech ERP',
    autoHideMenuBar: true,
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.cjs'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      partition: SESSION_PARTITION,
      webviewTag: false,
    },
  });

  configureWebContents(mainWindow.webContents, appConfig);
  attachMainFrameFailureHandler(mainWindow);

  mainWindow.on('closed', () => {
    mainWindow = null;
    closeSplashWindow();
  });

  const [, loadOk] = await Promise.all([
    waitForSplashDuration(appConfig),
    loadErpIntoWindow(mainWindow),
    waitForMainFramePaint(mainWindow),
  ]);

  closeSplashWindow();

  if (!loadOk || !mainWindow || mainWindow.isDestroyed()) {
    return;
  }

  mainWindow.show();
  mainWindow.focus();
  scheduleBackgroundUpdateCheck();
}

function buildApplicationMenu() {
  const template = [
    {
      label: 'Help',
      submenu: [
        {
          label: 'Check for updates',
          click: () => {
            checkForUpdatesManual().catch(() => {
              /* dialog shown in auto-update module */
            });
          },
        },
        { type: 'separator' },
        {
          label: 'About UltiTech ERP',
          click: async () => {
            const win = mainWindow && !mainWindow.isDestroyed() ? mainWindow : undefined;
            await dialog.showMessageBox(win, {
              type: 'info',
              title: 'UltiTech ERP',
              message: 'UltiTech ERP Desktop',
              detail: `Version ${app.getVersion()}\n\nLoads the hosted ERP from ultitech.io.`,
              buttons: ['OK'],
            });
          },
        },
      ],
    },
  ];

  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

app.whenReady().then(() => {
  appConfig = loadConfig();
  setupAutoUpdater(() => mainWindow);
  buildApplicationMenu();
  ipcMain.handle('ultitech:check-for-updates', () => checkForUpdatesManual());

  createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

app.on('web-contents-created', (_event, contents) => {
  contents.on('will-attach-webview', (event) => {
    event.preventDefault();
  });

  if (!appConfig) {
    appConfig = loadConfig();
  }

  configureWebContents(contents, appConfig);
});
