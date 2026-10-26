const { contextBridge, ipcRenderer } = require('electron');
const pkg = require('./package.json');
const { setupUpdateBanner } = require('./update-banner.cjs');

// Register IPC → page event bridge before any page script runs.
setupUpdateBanner(ipcRenderer);

contextBridge.exposeInMainWorld('ultitechClient', {
  platform: 'desktop',
  version: pkg.version,
  checkForUpdates: () => ipcRenderer.invoke('ultitech:check-for-updates-silent'),
  downloadUpdate: () => ipcRenderer.send('ultitech:update-download'),
  installUpdate: () => ipcRenderer.send('ultitech:update-install'),
  dismissUpdate: () => ipcRenderer.send('ultitech:update-dismiss'),
});
