const rowsEl = document.getElementById('rows');
const saveBtn = document.getElementById('save');

function defaultTabs() {
  const arr = [];
  for (let i = 1; i <= 7; i++) {
    arr.push({ id: `role${i}`, label: `Role ${i}`, url: '' });
  }
  return arr;
}

let tabs = [];

async function init() {
  const existing = await window.api.getTabsForSetup();
  tabs = (existing && existing.length) ? existing : defaultTabs();
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
    labelInput.placeholder = 'Tab name';
    labelInput.addEventListener('input', () => tabs[idx].label = labelInput.value);

    const urlInput = document.createElement('input');
    urlInput.value = tab.url;
    urlInput.placeholder = 'https://yoursite.com/login';
    urlInput.addEventListener('input', () => tabs[idx].url = urlInput.value.trim());

    row.appendChild(labelInput);
    row.appendChild(urlInput);
    rowsEl.appendChild(row);
  });
}

saveBtn.addEventListener('click', () => {
  // basic normalize: add https:// if missing and not empty
  tabs.forEach(t => {
    if (t.url && !/^https?:\/\//i.test(t.url)) {
      t.url = 'https://' + t.url;
    }
  });
  window.api.saveTabs(tabs);
});

init();
