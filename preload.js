const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('api', {
  switchTab: (tabId) => ipcRenderer.send('switch-tab', tabId),
  openSetup: () => ipcRenderer.send('open-setup'),
  fillLogin: () => ipcRenderer.send('fill-login'),
  onLoadTabs: (cb) => ipcRenderer.on('load-tabs', (e, tabs) => cb(tabs)),
  onActiveTab: (cb) => ipcRenderer.on('active-tab', (e, tabId) => cb(tabId)),
  getTabsForSetup: () => ipcRenderer.invoke('get-tabs-for-setup'),
  saveTabs: (tabs) => ipcRenderer.send('save-tabs', tabs)
});
