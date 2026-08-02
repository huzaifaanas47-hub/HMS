const tabsEl = document.getElementById('tabs');
const settingsBtn = document.getElementById('settings-btn');

let currentTabs = [];

function render(tabs, activeId) {
  currentTabs = tabs;
  tabsEl.innerHTML = '';
  tabs.forEach(tab => {
    const el = document.createElement('div');
    el.className = 'tab-btn' + (tab.id === activeId ? ' active' : '');
    el.textContent = tab.label || tab.id;
    el.addEventListener('click', () => {
      window.api.switchTab(tab.id);
      [...tabsEl.children].forEach(c => c.classList.remove('active'));
      el.classList.add('active');
    });
    tabsEl.appendChild(el);
  });
}

window.api.onLoadTabs((tabs) => {
  render(tabs, currentTabs[0] ? currentTabs[0].id : (tabs[0] && tabs[0].id));
});

window.api.onActiveTab((tabId) => {
  [...tabsEl.children].forEach((c, i) => {
    if (currentTabs[i] && currentTabs[i].id === tabId) c.classList.add('active');
    else c.classList.remove('active');
  });
});

settingsBtn.addEventListener('click', () => {
  window.api.openSetup();
});
