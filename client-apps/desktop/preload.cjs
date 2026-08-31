const { contextBridge, ipcRenderer } = require('electron');
const pkg = require('./package.json');
const { setupUpdateBanner } = require('./update-banner.cjs');

contextBridge.exposeInMainWorld('ultitechClient', {
  platform: 'desktop',
  version: pkg.version,
  checkForUpdates: () => ipcRenderer.invoke('ultitech:check-for-updates'),
});

function initWhenDomReady() {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setupUpdateBanner(ipcRenderer), { once: true });
  } else {
    setupUpdateBanner(ipcRenderer);
  }
}

initWhenDomReady();
