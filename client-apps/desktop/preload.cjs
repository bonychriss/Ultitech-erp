const { contextBridge, ipcRenderer } = require('electron');
const pkg = require('./package.json');
const { setupUpdateBanner } = require('./update-banner.cjs');

contextBridge.exposeInMainWorld('ultitechClient', {
  platform: 'desktop',
  version: pkg.version,
  checkForUpdates: () => ipcRenderer.invoke('ultitech:check-for-updates'),
  downloadUpdate: () => ipcRenderer.send('ultitech:update-download'),
  installUpdate: () => ipcRenderer.send('ultitech:update-install'),
  dismissUpdate: () => ipcRenderer.send('ultitech:update-dismiss'),
});

function initWhenDomReady() {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setupUpdateBanner(ipcRenderer), { once: true });
  } else {
    setupUpdateBanner(ipcRenderer);
  }
}

initWhenDomReady();
