const rowsEl = document.getElementById('rows');
const saveBtn = document.getElementById('save');
const addTabBtn = document.getElementById('add-tab');

const DEFAULT_URL = 'https://medicglobe.fun';

function defaultTabs() {
  const arr = [];
  for (let i = 1; i <= 7; i++) {
    arr.push({ id: `role${i}`, label: `Role ${i}`, url: DEFAULT_URL, email: '' });
  }
  return arr;
}

let tabs = [];
let nextIdCounter = 1;

function freshId() {
  // find an id not currently used
  let id;
  do {
    id = `role${Date.now()}_${nextIdCounter++}`;
  } while (tabs.some(t => t.id === id));
  return id;
}

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

    const removeBtn = document.createElement('button');
    removeBtn.className = 'remove-btn';
    removeBtn.textContent = '✕';
    removeBtn.title = 'Remove this tab';
    removeBtn.addEventListener('click', () => {
      tabs.splice(idx, 1);
      render();
    });

    row.appendChild(labelInput);
    row.appendChild(urlInput);
    row.appendChild(emailInput);
    row.appendChild(passInput);
    row.appendChild(removeBtn);
    rowsEl.appendChild(row);
  });
}

addTabBtn.addEventListener('click', () => {
  tabs.push({ id: freshId(), label: `Tab ${tabs.length + 1}`, url: DEFAULT_URL, email: '', password: '' });
  render();
});

saveBtn.addEventListener('click', () => {
  if (tabs.length === 0) {
    alert('Add at least one tab before saving.');
    return;
  }
  tabs.forEach(t => {
    if (t.url && !/^https?:\/\//i.test(t.url)) {
      t.url = 'https://' + t.url;
    }
  });
  window.api.saveTabs(tabs);
});

init();
