const tabsEl = document.getElementById('tabs');
const expiryEl = document.getElementById('expiry');
const lockEl = document.getElementById('lock');
const enrollForm = document.getElementById('enroll-form');
const codeInput = document.getElementById('enroll-code');
const enrollError = document.getElementById('enroll-error');
const enrollBtn = document.getElementById('enroll-btn');
const recheckBtn = document.getElementById('recheck');

let currentTabs = [];
let activeId = null;

function paintActive() {
  [...tabsEl.children].forEach(el => el.classList.toggle('active', el.dataset.id === activeId));
}

function render(tabs) {
  currentTabs = tabs;
  tabsEl.innerHTML = '';
  tabs.forEach(tab => {
    const el = document.createElement('div');
    el.className = 'tab-btn';
    el.dataset.id = tab.id;
    const dot = document.createElement('span');
    dot.className = 'dot';
    const name = document.createElement('span');
    name.className = 'name';
    name.textContent = tab.label || tab.id;
    el.title = tab.label || tab.id;
    el.appendChild(dot);
    el.appendChild(name);
    el.addEventListener('click', () => { activeId = tab.id; paintActive(); window.api.switchTab(tab.id); });
    el.addEventListener('contextmenu', (e) => { e.preventDefault(); window.api.tabMenu(tab.id); });
    tabsEl.appendChild(el);
  });
  if (!tabs.some(t => t.id === activeId)) activeId = tabs[0] ? tabs[0].id : null;
  paintActive();
  // A single tab needs no tab strip chrome beyond the one tab; keep it visible so the user sees where they are.
}

window.api.onLoadTabs(render);
window.api.onActiveTab((id) => { activeId = id; paintActive(); });

window.api.onPolicyState((s) => {
  const locked = s.state !== 'ok';
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
    document.getElementById('lock-title').textContent = titles[s.state] || 'Access unavailable';
    document.getElementById('lock-text').textContent = texts[s.state] || '';
    document.getElementById('lock-msg').textContent = s.state === 'enroll' ? '' : (s.message || '');
    enrollForm.classList.toggle('show', s.state === 'enroll');
    recheckBtn.style.display = s.state === 'enroll' ? 'none' : '';
    if (s.state === 'enroll') codeInput.focus();
  }
  if (!locked && s.daysLeft !== null && s.daysLeft <= 7) {
    expiryEl.style.display = 'flex';
    expiryEl.textContent = s.daysLeft <= 1 ? 'Access expires within a day' : 'Access expires in ' + s.daysLeft + ' days';
  } else {
    expiryEl.style.display = 'none';
  }
});

document.getElementById('settings-btn').addEventListener('click', () => window.api.openSetup());
document.getElementById('add-btn').addEventListener('click', () => window.api.openSetup());
document.getElementById('reload-btn').addEventListener('click', () => { if (activeId) window.api.reloadTab(activeId); });
document.getElementById('fill-login-btn').addEventListener('click', () => window.api.fillLogin());
document.getElementById('logout-btn').addEventListener('click', () => { if (activeId) window.api.logoutTab(activeId); });
document.getElementById('recheck').addEventListener('click', () => window.api.recheckPolicy());

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
