const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('bcut', {
  isDesktop: true,
  getVersion: () => ipcRenderer.invoke('bcut:get-version'),
  saveFile: ({ data, defaultName }) => ipcRenderer.invoke('bcut:save-file', { data, defaultName }),
  checkForUpdates: () => ipcRenderer.invoke('bcut:check-for-updates'),
  onUpdateStatus: (callback) => {
    if (typeof callback !== 'function') {
      return () => {};
    }

    const listener = (_event, payload) => callback(payload);
    ipcRenderer.on('bcut:update-status', listener);
    return () => ipcRenderer.removeListener('bcut:update-status', listener);
  }
});
