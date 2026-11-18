const rowsEl = document.getElementById('rows');
const baseEl = document.getElementById('base');
const errorEl = document.getElementById('error');
let tabs = [];
let secureStorage = true;
let counter = 1;

function freshId() {
  let id;
  do { id = `role${Date.now()}_${counter++}`; } while (tabs.some(t => t.id === id));
  return id;
}

function input(cls, value, placeholder, onInput, type) {
  const el = document.createElement('input');
  el.className = cls;
  if (type) el.type = type;
  el.value = value || '';
  el.placeholder = placeholder;
  el.addEventListener('input', () => onInput(el.value));
  return el;
}

function render() {
  rowsEl.innerHTML = '';
  tabs.forEach((tab, idx) => {
    const row = document.createElement('div');
    row.className = 'row';
    row.appendChild(input('label-input', tab.label, 'Tab name', v => tab.label = v));
    row.appendChild(input('url-input', tab.url, '/login or https://...', v => tab.url = v.trim()));
    row.appendChild(input('email-input', tab.email, 'Login email / username (optional)', v => tab.email = v.trim()));
    const pass = input('pass-input', '', tab.hasPassword ? 'Password saved - leave blank to keep' : 'Password (optional)', v => tab.password = v, 'password');
    pass.disabled = !secureStorage;
    row.appendChild(pass);
    const remove = document.createElement('button');
    remove.className = 'remove-btn';
    remove.textContent = '✕';
    remove.title = 'Remove this tab';
    remove.addEventListener('click', () => { tabs.splice(idx, 1); render(); });
    row.appendChild(remove);
    rowsEl.appendChild(row);
  });
}

document.getElementById('add-tab').addEventListener('click', () => {
  tabs.push({ id: freshId(), label: `Tab ${tabs.length + 1}`, url: '/', email: '', password: '' });
  render();
});

document.getElementById('save').addEventListener('click', async () => {
  errorEl.textContent = '';
  if (tabs.length === 0) { errorEl.textContent = 'Add at least one tab before saving.'; return; }
  const result = await window.api.saveSetup({
    baseUrl: baseEl.value,
    tabs: tabs.map(({ id, label, url, email, password }) => ({ id, label, url, email, password: password || '' }))
  });
  if (!result.ok) { errorEl.textContent = result.error; return; }
  if (result.warnings.length) alert(result.warnings.join('\n'));
  window.close();
});

(async function init() {
  const data = await window.api.getSetupData();
  secureStorage = data.secureStorage;
  baseEl.value = data.baseUrl;
  baseEl.disabled = data.baseManaged;
  document.getElementById('managed-note').style.display = data.baseManaged ? 'block' : 'none';
  document.getElementById('banner').style.display = secureStorage ? 'none' : 'block';
  tabs = data.tabs.map(t => ({ ...t, password: '' }));
  render();
})();
