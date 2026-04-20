const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('caify', {
  getConfig:      ()    => ipcRenderer.invoke('get-config'),
  setConfig:      (cfg) => ipcRenderer.invoke('set-config', cfg),
  testConnection: (cfg) => ipcRenderer.invoke('test-connection', cfg)
});
