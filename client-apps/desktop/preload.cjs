const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('ultitechClient', {
  platform: 'desktop',
  version: '1.0.0'
});
