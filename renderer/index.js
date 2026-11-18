const tabsEl = document.getElementById('tabs');
const expiryEl = document.getElementById('expiry');
const lockEl = document.getElementById('lock');

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
    el.textContent = tab.label || tab.id;
    el.addEventListener('click', () => { activeId = tab.id; paintActive(); window.api.switchTab(tab.id); });
    el.addEventListener('contextmenu', (e) => { e.preventDefault(); window.api.tabMenu(tab.id); });
    tabsEl.appendChild(el);
  });
  if (!tabs.some(t => t.id === activeId)) activeId = tabs[0] ? tabs[0].id : null;
  paintActive();
}

window.api.onLoadTabs(render);
window.api.onActiveTab((id) => { activeId = id; paintActive(); });

window.api.onPolicyState((s) => {
  const locked = s.state !== 'ok';
  lockEl.classList.toggle('show', locked);
  if (locked) {
    const titles = { expired: 'Access has expired', blocked: 'Access has been disabled', stale: 'Verification required' };
    const texts = {
      expired: s.expiresAt ? 'Your access to HMS ended on ' + new Date(s.expiresAt).toLocaleString() + '.' : 'Your access to HMS has ended.',
      blocked: 'The administrator has disabled access for this installation.',
      stale: 'HMS has not been able to reach the administration server for too long. Connect to the internet and check again.'
    };
    document.getElementById('lock-title').textContent = titles[s.state] || 'Access unavailable';
    document.getElementById('lock-text').textContent = texts[s.state] || '';
    document.getElementById('lock-msg').textContent = s.message || '';
  }
  if (!locked && s.daysLeft !== null && s.daysLeft <= 7) {
    expiryEl.style.display = 'flex';
    expiryEl.textContent = s.daysLeft <= 1 ? 'Access expires within a day' : 'Access expires in ' + s.daysLeft + ' days';
  } else {
    expiryEl.style.display = 'none';
  }
});

document.getElementById('settings-btn').addEventListener('click', () => window.api.openSetup());
document.getElementById('fill-login-btn').addEventListener('click', () => window.api.fillLogin());
document.getElementById('logout-btn').addEventListener('click', () => { if (activeId) window.api.logoutTab(activeId); });
document.getElementById('recheck').addEventListener('click', () => window.api.recheckPolicy());
