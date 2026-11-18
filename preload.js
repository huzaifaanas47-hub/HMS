const { contextBridge, ipcRenderer } = require('electron');

// Exposed only to HMS's own renderer pages (tab bar + setup). Remote sites never get this.
contextBridge.exposeInMainWorld('api', {
  switchTab: (tabId) => ipcRenderer.send('switch-tab', tabId),
  openSetup: () => ipcRenderer.send('open-setup'),
  fillLogin: () => ipcRenderer.send('fill-login'),
  logoutTab: (tabId) => ipcRenderer.send('logout-tab', tabId),
  logoutAll: () => ipcRenderer.send('logout-all'),
  tabMenu: (tabId) => ipcRenderer.send('tab-menu', tabId),
  recheckPolicy: () => ipcRenderer.send('recheck-policy'),
  onLoadTabs: (cb) => ipcRenderer.on('load-tabs', (e, tabs) => cb(tabs)),
  onActiveTab: (cb) => ipcRenderer.on('active-tab', (e, tabId) => cb(tabId)),
  onPolicyState: (cb) => ipcRenderer.on('policy-state', (e, state) => cb(state)),
  getSetupData: () => ipcRenderer.invoke('get-setup-data'),
  saveSetup: (data) => ipcRenderer.invoke('save-setup', data)
});
