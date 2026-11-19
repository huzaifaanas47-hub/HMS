'use strict';
const { app, BrowserWindow, WebContentsView, ipcMain, Menu, session, shell, dialog, systemPreferences } = require('electron');
const path = require('path');
const fs = require('fs');
const { fileURLToPath } = require('url');
const Store = require('electron-store');

const appConfig = require('./app-config.json');
const origins = require('./src/origins');
const { createCredentials } = require('./src/credentials');
const { createPolicy } = require('./src/policy');

const TAB_BAR_HEIGHT = 52; // keep in sync with --bar-h in renderer/topbar.css
const RENDERER_DIR = path.join(__dirname, 'renderer');
const FALLBACK_URL = appConfig.defaultUrl || 'https://medicglobe.fun';
const LEGACY_CONFIG_NAME = 'multirole-config'; // pre-HMS name of the config file

const store = new Store({ name: 'hms-config' });
const creds = createCredentials(store);

let policy = null;
let mainWindow = null;
let setupWindow = null;
let locked = false;
let currentTab = null;
const views = {};          // tabId -> WebContentsView
const viewUrls = new Map(); // tabId -> URL last loaded by us
const hardenedSessions = new Set();

/* ------------------------------------------------------------------ config */

function migrateLegacyStore() {
  if (store.size > 0) return;
  const legacy = new Store({ name: LEGACY_CONFIG_NAME });
  if (legacy.size > 0) store.store = legacy.store;
}

// The installer writes the address typed on its "HMS Server Address" page next to the exe.
function applyInstallUrl() {
  if (!app.isPackaged) return;
  const file = path.join(path.dirname(process.execPath), 'install-url.txt');
  try {
    const st = fs.statSync(file);
    if (store.get('installStamp') === st.mtimeMs) return; // already applied; re-running the installer re-applies
    const url = origins.normalizeBaseUrl(fs.readFileSync(file, 'utf8'));
    if (url) store.set('baseUrl', url);
    store.set('installStamp', st.mtimeMs);
  } catch (e) { /* no install-url.txt: first run from source, or older install */ }
}

function ensureBaseUrl() {
  if (store.get('baseUrl')) return;
  const tabs = store.get('tabs');
  const first = tabs && tabs[0] && origins.parse(tabs[0].url);
  store.set('baseUrl', first && origins.isHttp(first) ? first.origin + '/' : FALLBACK_URL);
}

function getBaseUrl() {
  const managed = policy && policy.evaluate().baseUrl;
  return origins.normalizeBaseUrl(managed || '') || store.get('baseUrl') || FALLBACK_URL;
}

function isBaseManaged() {
  return !!(policy && policy.evaluate().baseUrl);
}

function getTabs() {
  return store.get('tabs', null);
}

// v1 stored absolute URLs. Convert the ones on the site's own origin so they follow the base URL.
function migrateTabs() {
  const tabs = getTabs();
  if (!tabs) return;
  const base = getBaseUrl();
  store.set('tabs', tabs.map(t => ({ id: t.id, label: t.label, url: origins.normalizeTabUrl(t.url, base) || '/' })));
}

function defaultTabs() {
  return [{ id: 'role1', label: 'Main', url: '/' }]; // one tab by default; add more from the + button
}

function resolveTab(tab) {
  return origins.resolveTabUrl(tab.url, getBaseUrl());
}

function allowedOrigins() {
  const set = new Set();
  const base = origins.parse(getBaseUrl());
  if (base) set.add(base.origin);
  (getTabs() || []).forEach(t => {
    const u = origins.parse(resolveTab(t));
    if (u) set.add(u.origin);
  });
  return set;
}

function isAllowedUrl(url) {
  const u = origins.parse(url);
  if (!u) return false;
  if (u.protocol === 'blob:') return allowedOrigins().has(u.origin);
  return origins.isHttp(u) && allowedOrigins().has(u.origin);
}

/* ---------------------------------------------------------- IPC sender check */

function samePath(a, b) {
  return process.platform === 'win32' ? a.toLowerCase() === b.toLowerCase() : a === b;
}

// Only our own renderer pages (file:// inside /renderer, top frame) may call IPC handlers,
// and only from the window that is meant to use that channel.
function fromWindow(event, win) {
  if (!win || win.isDestroyed() || event.sender !== win.webContents) return false;
  const frame = event.senderFrame;
  if (!frame || frame.parent) return false;
  try {
    const p = path.normalize(fileURLToPath(frame.url));
    return samePath(path.dirname(p), RENDERER_DIR);
  } catch (e) {
    return false;
  }
}

function onMain(channel, fn) {
  ipcMain.on(channel, (event, ...args) => { if (fromWindow(event, mainWindow)) fn(...args); });
}

/* ------------------------------------------------------ navigation hardening */

function openExternalSafe(url) {
  const u = origins.parse(url);
  if (origins.isHttp(u)) shell.openExternal(u.href);
}

function guardContents(contents) {
  const onNav = (event, legacyUrl) => {
    const url = event.url || legacyUrl;
    if (!isAllowedUrl(url)) {
      event.preventDefault();
      openExternalSafe(url);
    }
  };
  contents.on('will-navigate', onNav);
  contents.on('will-redirect', onNav);
  contents.setWindowOpenHandler(({ url }) => {
    if (url === 'about:blank' || isAllowedUrl(url)) {
      return {
        action: 'allow',
        overrideBrowserWindowOptions: { autoHideMenuBar: true, webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: true } }
      };
    }
    openExternalSafe(url);
    return { action: 'deny' };
  });
  contents.on('did-create-window', (win) => guardContents(win.webContents));
}

// What the HMS site may use (only on the configured site origins): camera + microphone ('media'), location, notifications, clipboard, fullscreen.
const ALLOWED_PERMISSIONS = new Set(['fullscreen', 'clipboard-sanitized-write', 'clipboard-read', 'notifications', 'media', 'geolocation']);

function hardenSession(ses, partition) {
  if (hardenedSessions.has(partition)) return;
  hardenedSessions.add(partition);
  ses.setPermissionRequestHandler((wc, permission, callback, details) => {
    callback(ALLOWED_PERMISSIONS.has(permission) && isAllowedUrl(details.requestingUrl || wc.getURL()));
  });
  ses.setPermissionCheckHandler((wc, permission, requestingOrigin) => {
    return ALLOWED_PERMISSIONS.has(permission) && isAllowedUrl(requestingOrigin);
  });
}

/* ------------------------------------------------------------------ autofill */

// Fills (never submits) the saved login - but only when the page is on exactly the origin
// configured for that tab, over a trusted transport. A redirect or injected page on another
// origin never receives the credentials.
function autofillCredentials(view, tabId) {
  const tab = (getTabs() || []).find(t => t.id === tabId);
  if (!tab || view.webContents.isDestroyed()) return;
  const saved = creds.get(tabId);
  if (!saved.email && !saved.password) return;

  const expected = origins.parse(resolveTab(tab));
  const current = origins.parse(view.webContents.getURL());
  if (!expected || !current || current.origin !== expected.origin || !origins.isTrustedTransport(current)) return;

  const script = `
    (function () {
      try {
        if (location.origin !== ${JSON.stringify(expected.origin)}) return;
        const emailVal = ${JSON.stringify(saved.email)};
        const passVal = ${JSON.stringify(saved.password)};
        function setVal(el, val) {
          if (!el || !val) return;
          const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set;
          setter.call(el, val);
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const emailSelectors = ['input[type="email"]', 'input[name*="email" i]', 'input[id*="email" i]',
          'input[name*="user" i]', 'input[id*="user" i]', 'input[name*="login" i]', 'input[id*="login" i]'];
        let emailEl = null;
        for (const sel of emailSelectors) { emailEl = document.querySelector(sel); if (emailEl) break; }
        setVal(emailEl, emailVal);
        setVal(document.querySelector('input[type="password"]'), passVal);
      } catch (e) { /* silent */ }
    })();`;
  setTimeout(() => {
    if (!view.webContents.isDestroyed()) view.webContents.executeJavaScript(script).catch(() => {});
  }, 700);
}

/* --------------------------------------------------------------------- views */

// What the tab bar shows for a tab: the real host the page is on and whether it is encrypted.
// Computed here from the actual webContents URL, never from anything the page can set.
function tabInfo(tabId) {
  const info = { id: tabId, level: 'none', host: '', loading: false };
  const view = views[tabId];
  if (!view || view.webContents.isDestroyed()) return info;
  info.loading = view.webContents.isLoading();
  const u = origins.parse(view.webContents.getURL());
  if (origins.isHttp(u)) {
    info.host = u.host;
    info.level = u.protocol === 'https:' ? 'secure' : (origins.isTrustedTransport(u) ? 'local' : 'insecure');
  }
  return info;
}

function sendTabInfo(tabId) {
  if (mainWindow && !mainWindow.isDestroyed()) mainWindow.webContents.send('tab-info', tabInfo(tabId));
}

// Shown INSIDE the page while it navigates, so clicking a link gives instant feedback instead of
// a frozen-looking old page. Pure CSS via insertCSS: works under any page CSP, and is dropped
// automatically when the new document commits.
const LOADING_CSS = 'html::after{content:"";position:fixed;top:0;left:0;height:3px;width:40%;z-index:2147483647;pointer-events:none;' +
  'background:linear-gradient(90deg,transparent,#3b82f6,#22d3ee,transparent);animation:hms-load 1.1s ease-in-out infinite}' +
  'html,html *{cursor:progress!important}@keyframes hms-load{from{transform:translateX(-100%)}to{transform:translateX(260%)}}';

function trackLoadingStyle(wc) {
  let key = null;
  const clear = () => {
    const k = key; key = null;
    if (k && !wc.isDestroyed()) wc.removeInsertedCSS(k).catch(() => {});
  };
  wc.on('did-start-navigation', (e) => {
    if (!e.isMainFrame || e.isSameDocument || key) return;
    wc.insertCSS(LOADING_CSS).then((k) => { key = k; if (!wc.isLoading()) clear(); }).catch(() => {});
  });
  wc.on('did-stop-loading', clear);
  wc.on('did-fail-load', clear);
}

function createViewForTab(tab) {
  const partition = `persist:${tab.id}`; // isolated session per tab (cookies, localStorage, cache)
  const view = new WebContentsView({
    webPreferences: { partition, contextIsolation: true, nodeIntegration: false, sandbox: true, webviewTag: false }
  });
  view.setBackgroundColor('#ffffff');
  hardenSession(session.fromPartition(partition), partition);
  guardContents(view.webContents);
  trackLoadingStyle(view.webContents);
  ['did-start-loading', 'did-stop-loading', 'did-navigate', 'did-navigate-in-page', 'did-fail-load'].forEach((evt) => {
    view.webContents.on(evt, () => { if (!view.webContents.isDestroyed()) sendTabInfo(tab.id); });
  });
  view.webContents.on('did-finish-load', () => autofillCredentials(view, tab.id));
  const url = resolveTab(tab);
  viewUrls.set(tab.id, url);
  view.webContents.loadURL(url).catch(() => {});
  return view;
}

function ensureView(tab) {
  if (!views[tab.id]) views[tab.id] = createViewForTab(tab);
  return views[tab.id];
}

function destroyView(tabId) {
  const view = views[tabId];
  if (!view) return;
  try { if (mainWindow && !mainWindow.isDestroyed()) mainWindow.contentView.removeChildView(view); } catch (e) { /* not attached */ }
  try { if (!view.webContents.isDestroyed()) view.webContents.close(); } catch (e) { /* already gone */ }
  delete views[tabId];
  viewUrls.delete(tabId);
}

function destroyAllViews() {
  Object.keys(views).forEach(destroyView);
}

function resizeCurrentView() {
  if (!currentTab || !views[currentTab] || !mainWindow || mainWindow.isDestroyed()) return;
  const { width, height } = mainWindow.getContentBounds();
  views[currentTab].setBounds({ x: 0, y: TAB_BAR_HEIGHT, width: Math.max(0, width), height: Math.max(0, height - TAB_BAR_HEIGHT) });
}

function switchTab(tabId) {
  if (locked || !mainWindow) return;
  const tab = (getTabs() || []).find(t => t.id === tabId);
  if (!tab) return;
  const view = ensureView(tab);
  if (currentTab && currentTab !== tabId && views[currentTab]) {
    mainWindow.contentView.removeChildView(views[currentTab]);
  }
  mainWindow.contentView.addChildView(view);
  currentTab = tabId;
  resizeCurrentView();
  setTimeout(resizeCurrentView, 50);
  mainWindow.webContents.send('active-tab', tabId);
  sendTabInfo(tabId);
}

function cycleTab(delta) {
  const tabs = getTabs() || [];
  if (locked || tabs.length < 2) return;
  const i = tabs.findIndex(t => t.id === currentTab);
  switchTab(tabs[(i + delta + tabs.length) % tabs.length].id);
}

// Reload any tab whose resolved URL changed (base URL pushed from the admin panel, edited in setup).
function syncViews() {
  const tabs = getTabs() || [];
  const keep = new Set(tabs.map(t => t.id));
  Object.keys(views).forEach(id => {
    if (!keep.has(id)) {
      if (currentTab === id) currentTab = null;
      destroyView(id);
    }
  });
  if (locked) return;
  tabs.forEach(tab => {
    const view = views[tab.id];
    if (!view) return;
    const url = resolveTab(tab);
    if (viewUrls.get(tab.id) !== url) {
      viewUrls.set(tab.id, url);
      view.webContents.loadURL(url).catch(() => {});
    }
  });
}

/* -------------------------------------------------------------------- logout */

async function logoutTab(tabId) {
  const tab = (getTabs() || []).find(t => t.id === tabId);
  if (!tab) return;
  const view = views[tab.id];
  if (view) await view.webContents.loadURL('about:blank').catch(() => {}); // stop the page re-saving state
  const ses = session.fromPartition(`persist:${tab.id}`);
  await ses.clearStorageData();
  await ses.clearCache();
  await ses.clearAuthCache();
  if (view && !view.webContents.isDestroyed()) {
    const url = resolveTab(tab);
    viewUrls.set(tab.id, url);
    view.webContents.loadURL(url).catch(() => {});
  }
}

async function confirmLogout(tabId) {
  const tabs = getTabs() || [];
  const all = tabId === null;
  const tab = tabs.find(t => t.id === tabId);
  if (!all && !tab) return;
  const { response } = await dialog.showMessageBox(mainWindow, {
    type: 'question',
    buttons: ['Log out', 'Cancel'],
    defaultId: 1,
    cancelId: 1,
    message: all ? 'Log out of all tabs?' : `Log out of "${tab.label}"?`,
    detail: all
      ? 'This clears the saved session (cookies and site data) of every tab.'
      : 'This clears the saved session (cookies and site data) of this tab only. Other tabs stay logged in.'
  });
  if (response !== 0) return;
  for (const t of (all ? tabs : [tab])) await logoutTab(t.id);
}

async function confirmForgetLogin(tabId) {
  const tab = (getTabs() || []).find(t => t.id === tabId);
  if (!tab) return;
  const { response } = await dialog.showMessageBox(mainWindow, {
    type: 'question', buttons: ['Forget', 'Cancel'], defaultId: 1, cancelId: 1,
    message: `Forget the saved login for "${tab.label}"?`,
    detail: 'The saved email and password are deleted from this computer.'
  });
  if (response === 0) creds.clear(tabId);
}

/* ------------------------------------------------------------------- policy */

function publicPolicyState(eff) {
  return { state: eff.state, message: eff.message, expiresAt: eff.expiresAt, daysLeft: eff.daysLeft, update: eff.update ? { version: eff.update.version, notes: eff.update.notes } : null };
}

function sendPolicyState() {
  if (mainWindow && !mainWindow.isDestroyed() && policy) {
    mainWindow.webContents.send('policy-state', publicPolicyState(policy.evaluate()));
  }
}

function applyPolicy(eff) {
  const wasLocked = locked;
  locked = eff.state !== 'ok';
  sendPolicyState();
  if (locked) {
    destroyAllViews(); // an expired or blocked install shows nothing but the lock screen
  } else if (wasLocked) {
    const tabs = getTabs() || [];
    if (tabs.length) switchTab(currentTab && tabs.some(t => t.id === currentTab) ? currentTab : tabs[0].id);
    else openSetupWindow(false); // just joined with an access code: choose the tabs
  } else {
    syncViews();
  }
}

/* ------------------------------------------------------------------- windows */

function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 860,
    title: 'HMS',
    backgroundColor: '#0f1420',
    icon: path.join(__dirname, 'build', 'icon.ico'),
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      webviewTag: false
    }
  });
  mainWindow.webContents.on('will-attach-webview', e => e.preventDefault());
  mainWindow.webContents.on('will-navigate', e => e.preventDefault());
  mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  mainWindow.loadFile(path.join(RENDERER_DIR, 'index.html'));

  ['resize', 'maximize', 'unmaximize', 'enter-full-screen', 'leave-full-screen', 'restore', 'show'].forEach(evt => {
    mainWindow.on(evt, () => { resizeCurrentView(); setTimeout(resizeCurrentView, 50); });
  });
  mainWindow.on('resized', resizeCurrentView);
  mainWindow.on('closed', () => {
    Object.keys(views).forEach(id => { try { views[id].webContents.close(); } catch (e) { /* gone */ } });
    Object.keys(views).forEach(id => delete views[id]);
    viewUrls.clear();
    mainWindow = null;
    currentTab = null;
  });
  mainWindow.maximize();
  buildMenu();

  mainWindow.webContents.once('did-finish-load', () => {
    const tabs = getTabs() || [];
    sendTabs();
    sendPolicyState();
    if (!locked && tabs.length) switchTab(tabs[0].id);
  });
}

function sendTabs() {
  if (!mainWindow || mainWindow.isDestroyed()) return;
  mainWindow.webContents.send('load-tabs', (getTabs() || []).map(({ id, label }) => ({ id, label })));
}

function openSetupWindow(isEdit) {
  if (setupWindow) { setupWindow.focus(); return; }
  setupWindow = new BrowserWindow({
    width: 760,
    height: 780,
    backgroundColor: '#0f1420',
    title: 'HMS Setup',
    icon: path.join(__dirname, 'build', 'icon.ico'),
    parent: isEdit && mainWindow ? mainWindow : undefined,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      webviewTag: false
    }
  });
  setupWindow.webContents.on('will-attach-webview', e => e.preventDefault());
  setupWindow.setMenuBarVisibility(false);
  setupWindow.webContents.on('will-navigate', e => e.preventDefault());
  setupWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  setupWindow.loadFile(path.join(RENDERER_DIR, 'setup.html'));
  setupWindow.on('closed', () => { setupWindow = null; });
}

function showAbout() {
  const eff = policy ? policy.evaluate() : null;
  const lines = [`Version ${app.getVersion()}`, `Site: ${getBaseUrl()}${isBaseManaged() ? ' (set by administrator)' : ''}`];
  if (policy && policy.active) {
    lines.push(`Install ID: ${policy.installId}`);
    lines.push(eff.expiresAt ? `Access until: ${new Date(eff.expiresAt).toLocaleString()}` : 'Access: no expiry');
    if (policy.getLastError()) lines.push(`Last admin-server check failed: ${policy.getLastError()}`);
  }
  dialog.showMessageBox(mainWindow, { type: 'info', title: 'About HMS', message: 'HMS', detail: lines.join('\n') });
}

function buildMenu() {
  const withTab = (fn) => () => { if (currentTab) fn(currentTab); };
  const isMac = process.platform === 'darwin';
  Menu.setApplicationMenu(Menu.buildFromTemplate([
    ...(isMac ? [{ role: 'appMenu' }] : []),
    {
      label: 'File',
      submenu: [
        { label: 'Edit Tabs...', click: () => openSetupWindow(true) },
        { type: 'separator' },
        { label: 'Reload Current Tab', accelerator: 'CmdOrCtrl+R', click: withTab(id => views[id] && views[id].webContents.reload()) },
        { label: 'Fill Saved Login', click: withTab(id => views[id] && autofillCredentials(views[id], id)) },
        { type: 'separator' },
        { label: 'Log Out This Tab...', click: withTab(id => confirmLogout(id)) },
        { label: 'Log Out All Tabs...', click: () => confirmLogout(null) },
        { type: 'separator' },
        ...(isMac ? [{ role: 'close' }] : [{ role: 'quit' }])
      ]
    },
    { label: 'Edit', submenu: [{ role: 'undo' }, { role: 'redo' }, { type: 'separator' }, { role: 'cut' }, { role: 'copy' }, { role: 'paste' }, { role: 'selectAll' }] },
    {
      label: 'View',
      submenu: [
        { label: 'Next Tab', accelerator: 'Ctrl+Tab', click: () => cycleTab(1) },
        { label: 'Previous Tab', accelerator: 'Ctrl+Shift+Tab', click: () => cycleTab(-1) },
        { type: 'separator' },
        ...(app.isPackaged ? [] : [{ role: 'toggleDevTools' }, { type: 'separator' }]), // no DevTools in release builds
        { role: 'resetZoom' }, { role: 'zoomIn' }, { role: 'zoomOut' }
      ]
    },
    { label: 'Help', submenu: [{ label: 'About HMS', click: showAbout }] }
  ]));
}

/* ----------------------------------------------------------------------- IPC */

onMain('switch-tab', (tabId) => { if (typeof tabId === 'string') switchTab(tabId); });
onMain('open-setup', () => openSetupWindow(true));
onMain('fill-login', () => { if (currentTab && views[currentTab]) autofillCredentials(views[currentTab], currentTab); });
onMain('reload-tab', (tabId) => { if (typeof tabId === 'string' && views[tabId]) views[tabId].webContents.reload(); });
onMain('logout-tab', (tabId) => { if (typeof tabId === 'string') confirmLogout(tabId); });
onMain('logout-all', () => confirmLogout(null));
onMain('recheck-policy', () => { if (policy) policy.check(); });
// Opens the download page the admin server announced (signed). The renderer sends no URL: only the verified one is used.
onMain('open-update', () => {
  const u = policy && policy.getUpdate();
  if (u && /^https?:\/\//i.test(u.url)) shell.openExternal(u.url).catch(() => {});
});
ipcMain.handle('enroll', async (event, code) => {
  if (!fromWindow(event, mainWindow)) throw new Error('Untrusted sender');
  if (!policy || typeof code !== 'string' || !code.trim() || code.length > 40) return { ok: false, error: 'Enter the code your administrator gave you.' };
  return policy.enroll(code);
});
onMain('tab-menu', (tabId) => {
  if (typeof tabId !== 'string' || !(getTabs() || []).some(t => t.id === tabId)) return;
  Menu.buildFromTemplate([
    { label: 'Reload', click: () => views[tabId] && views[tabId].webContents.reload() },
    { label: 'Log Out This Tab...', click: () => confirmLogout(tabId) },
    { label: 'Forget Saved Login...', click: () => confirmForgetLogin(tabId) }
  ]).popup({ window: mainWindow });
});

ipcMain.handle('get-setup-data', (event) => {
  if (!fromWindow(event, setupWindow)) throw new Error('Untrusted sender');
  const tabs = getTabs() || defaultTabs();
  return {
    baseUrl: getBaseUrl(),
    baseManaged: isBaseManaged(),
    secureStorage: creds.isSecure(),
    tabs: tabs.map(t => ({ id: t.id, label: t.label, url: t.url, email: creds.get(t.id).email, hasPassword: creds.hasPassword(t.id) }))
  };
});

function validateSetup(payload) {
  if (!payload || !Array.isArray(payload.tabs) || payload.tabs.length < 1 || payload.tabs.length > 20) {
    return { error: 'Add between 1 and 20 tabs.' };
  }
  let baseUrl = getBaseUrl();
  if (!isBaseManaged()) {
    baseUrl = origins.normalizeBaseUrl(payload.baseUrl);
    if (!baseUrl) return { error: 'Enter a valid site address (https://...).' };
  }
  const seen = new Set();
  const tabs = [];
  for (const [i, t] of payload.tabs.entries()) {
    if (!t || typeof t.id !== 'string' || !/^[A-Za-z0-9_-]{1,64}$/.test(t.id) || seen.has(t.id)) return { error: `Tab ${i + 1} is invalid.` };
    seen.add(t.id);
    const label = (typeof t.label === 'string' ? t.label.trim() : '').slice(0, 40) || `Tab ${i + 1}`;
    const url = origins.normalizeTabUrl(t.url, baseUrl);
    if (!url) return { error: `"${label}": enter a path like /login or a full https:// address.` };
    const email = typeof t.email === 'string' ? t.email.trim().slice(0, 254) : '';
    const password = typeof t.password === 'string' ? t.password.slice(0, 256) : '';
    tabs.push({ id: t.id, label, url, email, password });
  }
  return { baseUrl, tabs };
}

ipcMain.handle('save-setup', (event, payload) => {
  if (!fromWindow(event, setupWindow)) throw new Error('Untrusted sender');
  const v = validateSetup(payload);
  if (v.error) return { ok: false, error: v.error };

  const warnings = [];
  if (!isBaseManaged()) store.set('baseUrl', v.baseUrl);
  store.set('tabs', v.tabs.map(({ id, label, url }) => ({ id, label, url })));
  v.tabs.forEach(t => {
    if (!t.email && !t.password) return;
    const r = creds.save(t.id, t.email, t.password);
    if (r.passwordSaved === false) warnings.push(`Password for "${t.label}" was not saved: secure storage is not available on this computer.`);
  });

  if (!mainWindow) createMainWindow();
  else { sendTabs(); syncViews(); if (!currentTab && !locked && v.tabs.length) switchTab(v.tabs[0].id); }
  return { ok: true, warnings };
});

/* ----------------------------------------------------------------------- updates */

// Updates come from this repo's GitHub Releases (see build.publish in package.json). They download in
// the background and install on the next restart. Skipped for `npm start`; failures never bother the user.
function setupAutoUpdate() {
  if (!app.isPackaged) return;
  let autoUpdater;
  try { ({ autoUpdater } = require('electron-updater')); } catch (e) { return; }
  autoUpdater.autoDownload = true;
  autoUpdater.autoInstallOnAppQuit = true;
  autoUpdater.on('error', (e) => console.warn('[update]', e && e.message));
  autoUpdater.on('update-downloaded', (info) => {
    const opts = {
      type: 'info', buttons: ['Restart now', 'Later'], defaultId: 0, cancelId: 1, title: 'Update ready',
      message: `HMS ${info.version} is ready to install.`,
      detail: 'Restart to finish updating. Otherwise it installs automatically the next time you close HMS.'
    };
    const parent = mainWindow && !mainWindow.isDestroyed() ? mainWindow : null;
    (parent ? dialog.showMessageBox(parent, opts) : dialog.showMessageBox(opts))
      .then((r) => { if (r.response === 0) autoUpdater.quitAndInstall(); })
      .catch(() => {});
  });
  const check = () => autoUpdater.checkForUpdates().catch(() => {});
  setTimeout(check, 15000);
  setInterval(check, 4 * 60 * 60 * 1000);
}

/* ----------------------------------------------------------------------- app */

app.whenReady().then(async () => {
  // HMS's own windows (tab bar, setup) never need camera, location, etc. Only the per-tab
  // sessions get the narrow allow-list in hardenSession().
  session.defaultSession.setPermissionRequestHandler((wc, permission, callback) => callback(false));
  session.defaultSession.setPermissionCheckHandler(() => false);

  migrateLegacyStore();
  const removed = creds.migrateLegacy();
  applyInstallUrl();
  ensureBaseUrl();
  migrateTabs();

  policy = createPolicy({ store, config: appConfig, appVersion: app.getVersion(), onChange: applyPolicy });
  locked = policy.evaluate().state !== 'ok';

  // A brand-new install has no policy yet: give the first check a moment so the admin's
  // default URL is used from the very first page load.
  if (policy.active && !store.get('policy')) {
    await Promise.race([policy.check(), new Promise(r => setTimeout(r, 8000))]);
    locked = policy.evaluate().state !== 'ok';
  }

  if (!getTabs() && !locked) openSetupWindow(false);
  else createMainWindow(); // locked (e.g. waiting for an access code): the lock screen is shown first
  policy.start();
  setupAutoUpdate();

  // macOS asks for camera/microphone access once; Windows uses its own privacy settings.
  if (process.platform === 'darwin') {
    ['camera', 'microphone'].forEach(t => systemPreferences.askForMediaAccess(t).catch(() => {}));
  }

  if (removed > 0) {
    dialog.showMessageBox({
      type: 'warning',
      message: 'Saved passwords removed',
      detail: `${removed} saved password(s) were stored unencrypted by an older version. Secure storage is not available on this computer, so they were deleted. Please re-enter them once it is.`
    });
  }

  app.on('activate', () => { if (BrowserWindow.getAllWindows().length === 0 && getTabs()) createMainWindow(); });
});

app.on('before-quit', () => { if (policy) policy.persist(); });
app.on('window-all-closed', () => { if (process.platform !== 'darwin') app.quit(); });
