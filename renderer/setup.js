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

function field(labelText, cls, value, placeholder, onInput, type, full) {
  const wrap = document.createElement('div');
  wrap.className = 'field' + (full ? ' full' : '');
  const label = document.createElement('label');
  label.textContent = labelText;
  const el = document.createElement('input');
  el.className = cls;
  if (type) el.type = type;
  el.value = value || '';
  el.placeholder = placeholder;
  el.addEventListener('input', () => onInput(el.value));
  wrap.appendChild(label);
  wrap.appendChild(el);
  return { wrap, el };
}

function render() {
  rowsEl.innerHTML = '';
  tabs.forEach((tab, idx) => {
    const card = document.createElement('div');
    card.className = 'card';

    const head = document.createElement('div');
    head.className = 'head';
    const num = document.createElement('span');
    num.className = 'num';
    num.textContent = String(idx + 1);
    const title = document.createElement('span');
    title.className = 'grow';
    title.textContent = tab.label || `Tab ${idx + 1}`;
    head.appendChild(num);
    head.appendChild(title);
    if (tabs.length > 1) {
      const remove = document.createElement('button');
      remove.className = 'remove-btn';
      remove.textContent = '✕';
      remove.title = 'Remove this tab';
      remove.addEventListener('click', () => { tabs.splice(idx, 1); render(); });
      head.appendChild(remove);
    }
    card.appendChild(head);

    const grid = document.createElement('div');
    grid.className = 'grid';
    grid.appendChild(field('Tab name', 'label-input', tab.label, 'e.g. Reception', v => { tab.label = v; title.textContent = v || `Tab ${idx + 1}`; }).wrap);
    grid.appendChild(field('Page', 'url-input', tab.url, '/login or https://...', v => tab.url = v.trim()).wrap);
    grid.appendChild(field('Login email / username', 'email-input', tab.email, 'Optional', v => tab.email = v.trim()).wrap);
    const pass = field('Password', 'pass-input', '', tab.hasPassword ? 'Saved - leave blank to keep' : 'Optional', v => tab.password = v, 'password');
    pass.el.disabled = !secureStorage;
    grid.appendChild(pass.wrap);
    card.appendChild(grid);
    rowsEl.appendChild(card);
  });
}

document.getElementById('add-tab').addEventListener('click', () => {
  tabs.push({ id: freshId(), label: `Tab ${tabs.length + 1}`, url: '/', email: '', password: '' });
  render();
  document.querySelector('main').scrollTo({ top: document.querySelector('main').scrollHeight, behavior: 'smooth' });
});

document.getElementById('cancel').addEventListener('click', () => window.close());

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
