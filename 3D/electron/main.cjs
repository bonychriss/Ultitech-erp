const { app, BrowserWindow, shell, dialog, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');
const { initAutoUpdater, checkForUpdates } = require('./updater.cjs');

const isDev = !app.isPackaged;
const DEV_SERVER_URL = process.env.VITE_DEV_SERVER_URL || 'http://localhost:5180';

function getDistIndexPath() {
  return path.join(__dirname, '..', 'dist', 'index.html');
}

function buildErrorPage(title, message) {
  const safeTitle = String(title).replace(/</g, '&lt;');
  const safeMessage = String(message).replace(/</g, '&lt;');
  return `data:text/html;charset=utf-8,${encodeURIComponent(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>${safeTitle}</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; margin: 0; padding: 24px; }
    .card { max-width: 560px; background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; }
    h1 { margin: 0 0 12px; font-size: 1.4rem; }
    p { margin: 0; line-height: 1.5; color: #94a3b8; }
    code { color: #93c5fd; }
  </style>
</head>
<body>
  <div class="card">
    <h1>${safeTitle}</h1>
    <p>${safeMessage}</p>
  </div>
</body>
</html>`)}`;
}

function createMainWindow() {
  const win = new BrowserWindow({
    width: 1320,
    height: 880,
    minWidth: 960,
    minHeight: 640,
    title: 'BCUT',
    backgroundColor: '#0f172a',
    show: false,
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload.cjs'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      webSecurity: true
    }
  });

  win.once('ready-to-show', () => {
    win.show();
  });

  win.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('http://') || url.startsWith('https://')) {
      shell.openExternal(url);
    }
    return { action: 'deny' };
  });

  if (isDev) {
    win.loadURL(DEV_SERVER_URL).catch((err) => {
      const message = `Could not connect to the Vite dev server at ${DEV_SERVER_URL}. Run <code>npm run dev</code> or <code>npm run electron:dev</code> first.<br><br>${err.message}`;
      win.loadURL(buildErrorPage('BCUT — Dev server not running', message));
    });
    win.webContents.openDevTools({ mode: 'detach' });
    return win;
  }

  const indexPath = getDistIndexPath();
  if (!fs.existsSync(indexPath)) {
    const message = 'Production build not found. Run <code>npm run build</code> before launching the packaged desktop app.';
    win.loadURL(buildErrorPage('BCUT — Build missing', message));
    return win;
  }

  win.loadFile(indexPath).catch((err) => {
    const message = `Failed to load the application bundle.<br><br>${err.message}`;
    win.loadURL(buildErrorPage('BCUT — Load error', message));
  });

  return win;
}

app.whenReady().then(() => {
  const mainWindow = createMainWindow();

  if (app.isPackaged) {
    initAutoUpdater(mainWindow);
  }

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      const win = createMainWindow();
      if (app.isPackaged) {
        initAutoUpdater(win);
      }
    }
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

ipcMain.handle('bcut:save-file', async (event, { data, defaultName }) => {
  const win = BrowserWindow.fromWebContents(event.sender);
  const ext = path.extname(defaultName || '').replace('.', '').toLowerCase();
  const filters = [
    {
      name: 'Images',
      extensions: ext ? [ext] : ['png', 'jpg', 'jpeg', 'webp']
    }
  ];

  const { canceled, filePath } = await dialog.showSaveDialog(win, {
    defaultPath: defaultName || 'bcut_export.png',
    filters
  });

  if (canceled || !filePath) {
    return { success: false, canceled: true };
  }

  const buffer = Buffer.from(data);
  await fs.promises.writeFile(filePath, buffer);
  return { success: true, filePath };
});

ipcMain.handle('bcut:get-version', () => app.getVersion());

ipcMain.handle('bcut:check-for-updates', async () => {
  if (!app.isPackaged) {
    return { success: false, reason: 'dev-mode' };
  }

  try {
    await checkForUpdates({ silent: false });
    return { success: true };
  } catch (err) {
    return { success: false, reason: err?.message || String(err) };
  }
});
