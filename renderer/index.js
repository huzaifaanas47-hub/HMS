'use strict';

const $ = (id) => document.getElementById(id);
const tabsEl = $('tabs');
const expiryEl = $('expiry');
const siteChip = $('site-chip');
const siteHost = $('site-host');
const progressEl = $('progress');
const lockEl = $('lock');
const enrollForm = $('enroll-form');
const codeInput = $('enroll-code');
const enrollError = $('enroll-error');
const enrollBtn = $('enroll-btn');
const recheckBtn = $('recheck');
const needsTab = ['reload-btn', 'fill-login-btn', 'logout-btn'].map($);

let activeId = null;
let locked = false;
const tabInfo = new Map(); // tabId -> { level: 'secure'|'local'|'insecure'|'none', host, loading }

const LEVEL_TEXT = {
  secure: (h) => ({ text: h, title: 'Secure connection (HTTPS) to ' + h }),
  local: (h) => ({ text: h + ' · local', title: 'Local network address, not encrypted: ' + h }),
  insecure: (h) => ({ text: 'Not secure · ' + h, title: 'This page is not encrypted (HTTP). Do not enter passwords or patient data.' })
};

function tabButtons() { return [...tabsEl.querySelectorAll('.tab')]; }

function paintInfo() {
  const i = tabInfo.get(activeId);
  const fmt = i && !locked && LEVEL_TEXT[i.level];
  siteChip.classList.toggle('hidden', !fmt);
  siteChip.classList.remove('secure', 'local', 'insecure');
  if (fmt) {
    const t = fmt(i.host);
    siteChip.classList.add(i.level);
    siteHost.textContent = t.text;
    siteChip.title = t.title;
  }
  progressEl.classList.toggle('on', !!(i && i.loading) && !locked);
  tabButtons().forEach((el) => {
    const x = tabInfo.get(el.dataset.id);
    el.classList.toggle('loading', !!(x && x.loading));
  });
}

function paint() {
  tabButtons().forEach((el) => {
    const on = el.dataset.id === activeId;
    el.setAttribute('aria-selected', String(on));
    el.tabIndex = on ? 0 : -1;
    el.disabled = locked;
  });
  needsTab.forEach((b) => { b.disabled = locked || !activeId; });
  const cur = tabsEl.querySelector('.tab[aria-selected="true"]');
  if (cur) cur.scrollIntoView({ inline: 'nearest', block: 'nearest' });
  paintInfo();
}

function selectTab(id) {
  if (locked || !id) return;
  activeId = id;
  paint();
  window.api.switchTab(id);
}

function render(tabs) {
  const ids = new Set(tabs.map((t) => t.id));
  [...tabInfo.keys()].forEach((k) => { if (!ids.has(k)) tabInfo.delete(k); });

  tabsEl.textContent = '';
  tabs.forEach((tab) => {
    const label = tab.label || tab.id;
    const el = document.createElement('button');
    el.type = 'button';
    el.className = 'tab';
    el.setAttribute('role', 'tab');
    el.dataset.id = tab.id;
    el.title = label;

    const dot = document.createElement('span');
    dot.className = 'dot';
    const name = document.createElement('span');
    name.className = 'name';
    name.textContent = label; // never innerHTML: labels are user-edited text

    el.append(dot, name);
    el.addEventListener('click', () => selectTab(tab.id));
    el.addEventListener('contextmenu', (e) => { e.preventDefault(); if (!locked) window.api.tabMenu(tab.id); });
    tabsEl.appendChild(el);
  });

  if (!ids.has(activeId)) activeId = tabs[0] ? tabs[0].id : null;
  paint();
}

// Arrow keys / Home / End move between tabs (roving tabindex).
tabsEl.addEventListener('keydown', (e) => {
  const btns = tabButtons();
  const i = btns.findIndex((b) => b.dataset.id === activeId);
  let next = -1;
  if (e.key === 'ArrowRight') next = (i + 1) % btns.length;
  else if (e.key === 'ArrowLeft') next = (i - 1 + btns.length) % btns.length;
  else if (e.key === 'Home') next = 0;
  else if (e.key === 'End') next = btns.length - 1;
  if (next < 0 || !btns[next]) return;
  e.preventDefault();
  selectTab(btns[next].dataset.id);
  btns[next].focus();
});

window.api.onLoadTabs(render);
window.api.onActiveTab((id) => { activeId = id; paint(); });
window.api.onTabInfo((i) => {
  if (!i || typeof i.id !== 'string' || typeof i.host !== 'string') return;
  tabInfo.set(i.id, { level: i.level, host: i.host, loading: !!i.loading });
  paintInfo();
});

window.api.onPolicyState((s) => {
  locked = s.state !== 'ok';
  lockEl.classList.toggle('show', locked);
  if (locked) {
    const titles = { expired: 'Access has expired', blocked: 'Access has been disabled', stale: 'Verification required', enroll: 'Enter your access code', unverified: 'Connecting to HMS' };
    const texts = {
      expired: s.expiresAt ? 'Your access to HMS ended on ' + new Date(s.expiresAt).toLocaleString() + '.' : 'Your access to HMS has ended.',
      blocked: 'The administrator has disabled access for this installation.',
      unverified: 'HMS is checking with the server. If this stays here, connect to the internet and click Check again.',
      enroll: 'Your administrator gave you a code like ABCD-EFGH. You only need to enter it once.',
      stale: 'HMS has not been able to reach the administration server for too long. Connect to the internet and check again.'
    };
    $('lock-title').textContent = titles[s.state] || 'Access unavailable';
    $('lock-text').textContent = texts[s.state] || '';
    $('lock-msg').textContent = s.state === 'enroll' ? '' : (s.message || '');
    enrollForm.classList.toggle('hidden', s.state !== 'enroll');
    recheckBtn.classList.toggle('hidden', s.state === 'enroll');
    (s.state === 'enroll' ? codeInput : recheckBtn).focus();
  }
  paint();

  const days = s.daysLeft;
  if (!locked && typeof days === 'number' && days <= 7) {
    expiryEl.classList.remove('hidden');
    expiryEl.textContent = days <= 1 ? 'Access expires within a day' : 'Access expires in ' + days + ' days';
  } else {
    expiryEl.classList.add('hidden');
  }
});

$('settings-btn').addEventListener('click', () => window.api.openSetup());
$('add-btn').addEventListener('click', () => window.api.openSetup());
$('reload-btn').addEventListener('click', () => { if (activeId && !locked) window.api.reloadTab(activeId); });
$('fill-login-btn').addEventListener('click', () => { if (!locked) window.api.fillLogin(); });
$('logout-btn').addEventListener('click', () => { if (activeId && !locked) window.api.logoutTab(activeId); });
recheckBtn.addEventListener('click', () => window.api.recheckPolicy());

// Access codes look like ABCD-EFGH: keep only letters/digits, uppercase, add the dash.
codeInput.addEventListener('input', () => {
  const raw = codeInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
  codeInput.value = raw.length > 4 ? raw.slice(0, 4) + '-' + raw.slice(4) : raw;
});

enrollForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  enrollError.textContent = '';
  enrollBtn.disabled = true;
  enrollBtn.textContent = 'Checking...';
  try {
    const r = await window.api.enroll(codeInput.value);
    if (!r.ok) enrollError.textContent = r.error;
    else codeInput.value = '';
  } catch (err) {
    enrollError.textContent = 'Something went wrong. Try again.';
  }
  enrollBtn.disabled = false;
  enrollBtn.textContent = 'Join';
});
