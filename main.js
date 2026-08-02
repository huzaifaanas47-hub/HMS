const { app, BrowserWindow, BrowserView, ipcMain, Menu, session } = require('electron');
const path = require('path');
const Store = require('electron-store');

const store = new Store({ name: 'multirole-config' });

let mainWindow;
let views = {}; // tabId -> BrowserView
let currentTab = null;

const TAB_BAR_HEIGHT = 46;

function getConfig() {
  return store.get('tabs', null); // array of { id, label, url }
}

function saveConfig(tabs) {
  store.set('tabs', tabs);
}

function defaultTabs() {
  const arr = [];
  for (let i = 1; i <= 7; i++) {
    arr.push({ id: `role${i}`, label: `Role ${i}`, url: '' });
  }
  return arr;
}

function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 860,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });

  mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));

  mainWindow.on('resize', () => resizeCurrentView());

  buildMenu();

  mainWindow.webContents.once('did-finish-load', () => {
    const tabs = getConfig();
    mainWindow.webContents.send('load-tabs', tabs);
    if (tabs && tabs.length) {
      switchTab(tabs[0].id, tabs);
    }
  });
}

function buildMenu() {
  const template = [
    {
      label: 'File',
      submenu: [
        {
          label: 'Edit Tab URLs...',
          click: () => openSetupWindow(true)
        },
        { type: 'separator' },
        {
          label: 'Reload Current Tab',
          click: () => {
            if (currentTab && views[currentTab]) views[currentTab].webContents.reload();
          }
        },
        { type: 'separator' },
        { role: 'quit' }
      ]
    },
    {
      label: 'View',
      submenu: [
        { role: 'toggleDevTools' },
        { role: 'resetZoom' },
        { role: 'zoomIn' },
        { role: 'zoomOut' }
      ]
    }
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

function createViewForTab(tab) {
  const view = new BrowserView({
    webPreferences: {
      partition: `persist:${tab.id}`, // isolated session per tab (cookies, localStorage, cache)
      contextIsolation: true,
      nodeIntegration: false
    }
  });
  if (tab.url) {
    view.webContents.loadURL(tab.url);
  }
  return view;
}

function ensureView(tab) {
  if (!views[tab.id]) {
    views[tab.id] = createViewForTab(tab);
  }
  return views[tab.id];
}

function resizeCurrentView() {
  if (!currentTab || !views[currentTab] || !mainWindow) return;
  const [w, h] = mainWindow.getContentSize();
  views[currentTab].setBounds({ x: 0, y: TAB_BAR_HEIGHT, width: w, height: h - TAB_BAR_HEIGHT });
  views[currentTab].setAutoResize({ width: true, height: true });
}

function switchTab(tabId, tabsOverride) {
  const tabs = tabsOverride || getConfig();
  const tab = tabs.find(t => t.id === tabId);
  if (!tab) return;

  const view = ensureView(tab);

  if (currentTab && views[currentTab]) {
    mainWindow.removeBrowserView(views[currentTab]);
  }

  mainWindow.addBrowserView(view);
  currentTab = tabId;
  resizeCurrentView();

  if (!view.webContents.getURL() && tab.url) {
    view.webContents.loadURL(tab.url);
  }

  mainWindow.webContents.send('active-tab', tabId);
}

ipcMain.on('switch-tab', (e, tabId) => switchTab(tabId));

ipcMain.on('open-setup', () => openSetupWindow(true));

ipcMain.handle('get-tabs-for-setup', () => {
  return getConfig() || defaultTabs();
});

ipcMain.on('save-tabs', (e, tabs) => {
  saveConfig(tabs);
  // rebuild views: remove views whose URL changed, keep others
  for (const tab of tabs) {
    const existing = views[tab.id];
    if (existing) {
      const loadedUrl = existing.webContents.getURL();
      if (tab.url && !loadedUrl) {
        existing.webContents.loadURL(tab.url);
      }
    }
  }
  if (mainWindow) {
    mainWindow.webContents.send('load-tabs', tabs);
    if (!currentTab && tabs.length) switchTab(tabs[0].id, tabs);
  }
  if (setupWindow) setupWindow.close();
});

let setupWindow = null;
function openSetupWindow(isEdit) {
  if (setupWindow) {
    setupWindow.focus();
    return;
  }
  setupWindow = new BrowserWindow({
    width: 640,
    height: 720,
    parent: isEdit ? mainWindow : undefined,
    modal: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });
  setupWindow.loadFile(path.join(__dirname, 'renderer', 'setup.html'));
  setupWindow.on('closed', () => { setupWindow = null; });
}

app.whenReady().then(() => {
  const tabs = getConfig();
  if (!tabs) {
    // first run: show setup before main window
    openSetupWindow(false);
    ipcMain.once('save-tabs', () => {
      createMainWindow();
    });
  } else {
    createMainWindow();
  }

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createMainWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
