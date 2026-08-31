const { contextBridge, ipcRenderer } = require('electron');
const pkg = require('./package.json');

contextBridge.exposeInMainWorld('ultitechClient', {
  platform: 'desktop',
  version: pkg.version,
  checkForUpdates: () => ipcRenderer.invoke('ultitech:check-for-updates'),
});
