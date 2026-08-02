const { app, BrowserWindow, BrowserView, ipcMain, Menu, session, safeStorage } = require('electron');
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

// --- Credentials: password is OS-encrypted (Windows DPAPI via safeStorage) before saving to disk ---
function saveCredentials(tabId, email, password) {
  const record = { email: email || '' };
  if (password) {
    if (safeStorage.isEncryptionAvailable()) {
      record.password = safeStorage.encryptString(password).toString('base64');
      record.encrypted = true;
    } else {
      record.password = password; // fallback, unencrypted
      record.encrypted = false;
    }
  } else {
    // no new password provided: keep existing one if present
    const existing = store.get(`creds.${tabId}`, null);
    if (existing) {
      record.password = existing.password;
      record.encrypted = existing.encrypted;
    }
  }
  store.set(`creds.${tabId}`, record);
}

function getCredentials(tabId) {
  const record = store.get(`creds.${tabId}`, null);
  if (!record) return { email: '', password: '' };
  let password = '';
  if (record.password) {
    if (record.encrypted && safeStorage.isEncryptionAvailable()) {
      try {
        password = safeStorage.decryptString(Buffer.from(record.password, 'base64'));
      } catch (e) {
        password = '';
      }
    } else {
      password = record.password;
    }
  }
  return { email: record.email || '', password };
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
    icon: path.join(__dirname, 'build', 'icon.ico'),
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
        {
          label: 'Fill Saved Login',
          click: () => {
            if (currentTab && views[currentTab]) autofillCredentials(views[currentTab], currentTab);
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

  view.webContents.on('did-finish-load', () => {
    autofillCredentials(view, tab.id);
  });

  if (tab.url) {
    view.webContents.loadURL(tab.url);
  }
  return view;
}

// Best-effort autofill: finds common email/username + password fields and fills them.
// Does NOT auto-submit — user reviews and clicks login themselves.
function autofillCredentials(view, tabId) {
  const creds = getCredentials(tabId);
  if (!creds.email && !creds.password) return;

  const script = `
    (function() {
      try {
        const emailVal = ${JSON.stringify(creds.email)};
        const passVal = ${JSON.stringify(creds.password)};

        function setVal(el, val) {
          if (!el || !val) return;
          const proto = Object.getPrototypeOf(el);
          const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
          setter.call(el, val);
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const emailSelectors = [
          'input[type="email"]',
          'input[name*="email" i]',
          'input[id*="email" i]',
          'input[name*="user" i]',
          'input[id*="user" i]',
          'input[name*="login" i]',
          'input[id*="login" i]'
        ];
        const passSelectors = ['input[type="password"]'];

        let emailEl = null;
        for (const sel of emailSelectors) {
          emailEl = document.querySelector(sel);
          if (emailEl) break;
        }
        let passEl = document.querySelector(passSelectors[0]);

        setVal(emailEl, emailVal);
        setVal(passEl, passVal);
      } catch (e) { /* silent */ }
    })();
  `;
  // small delay so dynamically-rendered login forms exist before we try
  setTimeout(() => {
    view.webContents.executeJavaScript(script).catch(() => {});
  }, 700);
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
  const tabs = getConfig() || defaultTabs();
  // attach current saved email (never send password back to the UI for security)
  return tabs.map(t => {
    const creds = getCredentials(t.id);
    return { ...t, email: creds.email, hasPassword: !!creds.password };
  });
});

ipcMain.on('save-tabs', (e, tabs) => {
  const cleanTabs = tabs.map(({ id, label, url }) => ({ id, label, url }));
  saveConfig(cleanTabs);

  tabs.forEach(t => {
    if (t.email || t.password) {
      saveCredentials(t.id, t.email, t.password);
    }
  });

  // rebuild views: remove views whose URL changed, keep others
  for (const tab of cleanTabs) {
    const existing = views[tab.id];
    if (existing) {
      const loadedUrl = existing.webContents.getURL();
      if (tab.url && !loadedUrl) {
        existing.webContents.loadURL(tab.url);
      }
    }
  }
  if (mainWindow) {
    mainWindow.webContents.send('load-tabs', cleanTabs);
    if (!currentTab && cleanTabs.length) switchTab(cleanTabs[0].id, cleanTabs);
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
    icon: path.join(__dirname, 'build', 'icon.ico'),
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
