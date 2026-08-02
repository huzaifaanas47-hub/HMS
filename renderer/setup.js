const rowsEl = document.getElementById('rows');
const saveBtn = document.getElementById('save');

function defaultTabs() {
  const arr = [];
  for (let i = 1; i <= 7; i++) {
    arr.push({ id: `role${i}`, label: `Role ${i}`, url: '', email: '' });
  }
  return arr;
}

let tabs = [];

async function init() {
  const existing = await window.api.getTabsForSetup();
  tabs = (existing && existing.length) ? existing.map(t => ({ ...t, password: '' })) : defaultTabs();
  render();
}

function render() {
  rowsEl.innerHTML = '';
  tabs.forEach((tab, idx) => {
    const row = document.createElement('div');
    row.className = 'row';

    const labelInput = document.createElement('input');
    labelInput.className = 'label-input';
    labelInput.value = tab.label;
    labelInput.placeholder = 'Tab name (e.g. Admin, Doctor)';
    labelInput.addEventListener('input', () => tabs[idx].label = labelInput.value);

    const urlInput = document.createElement('input');
    urlInput.className = 'url-input';
    urlInput.value = tab.url;
    urlInput.placeholder = 'https://yoursite.com/login';
    urlInput.addEventListener('input', () => tabs[idx].url = urlInput.value.trim());

    const emailInput = document.createElement('input');
    emailInput.className = 'email-input';
    emailInput.value = tab.email || '';
    emailInput.placeholder = 'Login email / username (optional)';
    emailInput.addEventListener('input', () => tabs[idx].email = emailInput.value.trim());

    const passInput = document.createElement('input');
    passInput.className = 'pass-input';
    passInput.type = 'password';
    passInput.value = '';
    passInput.placeholder = tab.hasPassword ? 'Password saved — leave blank to keep it' : 'Password (optional)';
    passInput.addEventListener('input', () => tabs[idx].password = passInput.value);

    row.appendChild(labelInput);
    row.appendChild(urlInput);
    row.appendChild(emailInput);
    row.appendChild(passInput);
    rowsEl.appendChild(row);
  });
}

saveBtn.addEventListener('click', () => {
  tabs.forEach(t => {
    if (t.url && !/^https?:\/\//i.test(t.url)) {
      t.url = 'https://' + t.url;
    }
  });
  window.api.saveTabs(tabs);
});

init();
