'use strict';
const { safeStorage } = require('electron');

// Saved passwords are only ever written encrypted with the OS keystore (DPAPI on Windows,
// Keychain on macOS, libsecret/kwallet on Linux). If that is not available the password is
// NOT saved - there is deliberately no plaintext fallback.

function isSecure() {
  try {
    if (!safeStorage.isEncryptionAvailable()) return false;
    if (process.platform === 'linux' && typeof safeStorage.getSelectedStorageBackend === 'function') {
      return safeStorage.getSelectedStorageBackend() !== 'basic_text';
    }
    return true;
  } catch (e) {
    return false;
  }
}

function createCredentials(store) {
  const key = (tabId) => `creds.${tabId}`;

  // save() -> { passwordSaved: boolean|null }   (null = no new password was supplied)
  function save(tabId, email, password) {
    const existing = store.get(key(tabId), null);
    const record = { email: email || '' };
    let passwordSaved = null;
    if (password) {
      if (isSecure()) {
        record.password = safeStorage.encryptString(password).toString('base64');
        record.encrypted = true;
        passwordSaved = true;
      } else {
        passwordSaved = false; // refuse to store it
      }
    }
    if (!record.password && existing && existing.encrypted === true && existing.password) {
      record.password = existing.password;
      record.encrypted = true;
    }
    store.set(key(tabId), record);
    return { passwordSaved };
  }

  function get(tabId) {
    const record = store.get(key(tabId), null);
    if (!record) return { email: '', password: '' };
    let password = '';
    // Only encrypted records are ever read back; legacy plaintext is handled by migrateLegacy().
    if (record.password && record.encrypted === true && isSecure()) {
      try { password = safeStorage.decryptString(Buffer.from(record.password, 'base64')); } catch (e) { password = ''; }
    }
    return { email: record.email || '', password };
  }

  function hasPassword(tabId) {
    const r = store.get(key(tabId), null);
    return !!(r && r.password && r.encrypted === true);
  }

  function clear(tabId) {
    store.delete(key(tabId));
  }

  // Older versions stored the password in plain text when encryption was unavailable.
  // Re-encrypt those where possible, otherwise delete them. Returns how many were deleted.
  function migrateLegacy() {
    const all = store.get('creds', {}) || {};
    let removed = 0;
    for (const [tabId, rec] of Object.entries(all)) {
      if (!rec || !rec.password || rec.encrypted === true) continue;
      if (isSecure()) {
        store.set(key(tabId), { email: rec.email || '', password: safeStorage.encryptString(String(rec.password)).toString('base64'), encrypted: true });
      } else {
        store.set(key(tabId), { email: rec.email || '' });
        removed++;
      }
    }
    return removed;
  }

  return { save, get, hasPassword, clear, migrateLegacy, isSecure };
}

module.exports = { createCredentials, isSecure };
