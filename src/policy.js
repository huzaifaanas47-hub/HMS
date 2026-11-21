'use strict';
const crypto = require('crypto');
const os = require('os');
const { net } = require('electron');

// Talks to the HMS admin panel (server/). The server answers every check-in with
//   { payload: "<JSON string>", sig: "<base64 Ed25519 signature of that exact string>" }
// The app only trusts a response that verifies against the public key in app-config.json,
// so a fake server / hosts-file trick cannot extend an expiry or push a different URL.

const DAY = 86400000;
const SPKI_ED25519_PREFIX = Buffer.from('302a300506032b6570032100', 'hex');

function makePublicKey(b64) {
  const raw = Buffer.from(String(b64), 'base64');
  if (raw.length !== 32) throw new Error('publicKey must be a base64 32-byte Ed25519 key');
  return crypto.createPublicKey({ key: Buffer.concat([SPKI_ED25519_PREFIX, raw]), format: 'der', type: 'spki' });
}

function verifyEnvelope(env, key) {
  if (!env || typeof env.payload !== 'string' || typeof env.sig !== 'string') return null;
  try {
    if (!crypto.verify(null, Buffer.from(env.payload, 'utf8'), key, Buffer.from(env.sig, 'base64'))) return null;
    return JSON.parse(env.payload);
  } catch (e) {
    return null;
  }
}

// true when version a (e.g. "2.3.0") is newer than b
function versionNewer(a, b) {
  const pa = String(a).split(/[.+-]/).map((x) => parseInt(x, 10) || 0);
  const pb = String(b).split(/[.+-]/).map((x) => parseInt(x, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] || 0) - (pb[i] || 0);
    if (d) return d > 0;
  }
  return false;
}

function createPolicy({ store, config, appVersion, onChange }) {
  let key = null;
  if (config.policyUrl && config.publicKey) {
    try { key = makePublicKey(config.publicKey); } catch (e) { console.error('[policy]', e.message); }
  }
  const active = !!key;

  let installId = store.get('installId');
  if (!installId) {
    installId = crypto.randomUUID();
    store.set('installId', installId);
  }

  // Latest time we have ever seen (local clock or server). Used so that setting the PC clock
  // backwards does not bring an expired install back to life.
  let lastSeen = Math.max(store.get('lastSeen', 0), Date.now());
  let persistedAt = 0;
  let lastError = null;
  let lastSig = null;
  const timers = [];

  function now() {
    lastSeen = Math.max(lastSeen, Date.now());
    return lastSeen;
  }

  function persist(force) {
    if (force || lastSeen - persistedAt > 5 * 60 * 1000) {
      store.set('lastSeen', lastSeen);
      persistedAt = lastSeen;
    }
  }

  function loadCached() {
    if (!active) return null;
    const c = store.get('policy', null);
    // A cache that was deleted (after one verified check-in) or edited by hand counts as invalid,
    // so it cannot be used to dodge an expiry: the app stays locked until it verifies again.
    if (!c) return store.get('verifiedOnce', false) ? { invalid: true } : null;
    const p = verifyEnvelope(c.env, key);
    if (!p || p.installId !== installId) return { invalid: true };
    return { p };
  }

  // -> { state: 'ok'|'expired'|'blocked'|'stale'|'enroll'|'unverified', baseUrl, message, expiresAt, daysLeft }
  function evaluate() {
    const out = { state: 'ok', baseUrl: null, message: null, notice: null, expiresAt: null, daysLeft: null, update: null, support: null };
    const c = loadCached();
    if (!c) { if (active) out.state = 'unverified'; return out; } // first start: nothing is allowed until the server has answered
    if (c.invalid) { out.state = 'stale'; return out; }
    const p = c.p;
    const t = now();
    out.baseUrl = p.baseUrl || null;
    out.message = p.message || null;
    out.notice = (typeof p.notice === 'string' && p.notice) ? p.notice.slice(0, 500) : null; // admin notice for the clinic (e.g. maintenance)
    out.expiresAt = p.expiresAt || null;
    // Help contact of the clinic (numbers only: they are used for a call / WhatsApp link)
    const sp = p.support;
    if (sp && typeof sp === 'object') {
      const ok = (v) => (typeof v === 'string' && /^[0-9+ ()-]{3,30}$/.test(v)) ? v : null;
      const phone = ok(sp.phone), whatsapp = ok(sp.whatsapp);
      if (phone || whatsapp) out.support = { phone, whatsapp };
    }
    // The signed answer may announce a newer build the admin has uploaded. Checked here as well, so a cached answer stops showing it once updated.
    const u = p.update;
    if (u && typeof u.version === 'string' && typeof u.url === 'string' && /^https?:\/\//i.test(u.url) && versionNewer(u.version, appVersion)) {
      out.update = {
        version: u.version, url: u.url, notes: typeof u.notes === 'string' ? u.notes : null,
        // direct file + SHA-256 for the in-app download (the whole answer is signed, so the hash can be trusted)
        file: (typeof u.file === 'string' && /^https:\/\//i.test(u.file)) ? u.file : null,
        sha256: (typeof u.sha256 === 'string' && /^[0-9a-f]{64}$/i.test(u.sha256)) ? u.sha256.toLowerCase() : null
      };
    }
    if (p.expiresAt) out.daysLeft = Math.ceil((p.expiresAt - t) / DAY);
    if (p.enrollRequired) out.state = 'enroll'; // the admin server wants an access code first
    else if (p.status === 'blocked') out.state = 'blocked';
    else if (p.expiresAt && t >= p.expiresAt) out.state = 'expired';
    else if (p.offlineGraceDays > 0 && t - p.serverTime > p.offlineGraceDays * DAY) out.state = 'stale'; // serverTime is signed, so it cannot be edited locally
    return out;
  }

  function publish() {
    const e = evaluate();
    const sig = JSON.stringify(e);
    if (sig !== lastSig) {
      lastSig = sig;
      if (onChange) onChange(e);
    }
    return e;
  }

  async function check() {
    if (!active) return evaluate();
    const nonce = crypto.randomBytes(16).toString('hex');
    // Problems the app hit since the last check-in (short technical messages; see queueError in main.js). At most 3 per check-in.
    const pending = (store.get('pendingErrors', []) || []).filter((e) => e && typeof e.m === 'string').slice(0, 3);
    try {
      const res = await net.fetch(config.policyUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          installId, nonce, appVersion,
          platform: process.platform, arch: process.arch,
          device: os.hostname(),
          osRelease: os.release(),
          ...(pending.length ? { errors: pending } : {})
        }),
        signal: AbortSignal.timeout(10000)
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const env = await res.json();
      const p = verifyEnvelope(env, key);
      if (!p || p.installId !== installId || p.nonce !== nonce) throw new Error('response failed signature check');
      lastSeen = Math.max(lastSeen, Number(p.serverTime) || 0, Date.now());
      store.set('policy', { env: { payload: env.payload, sig: env.sig } });
      store.set('verifiedOnce', true);
      if (pending.length) store.set('pendingErrors', (store.get('pendingErrors', []) || []).slice(pending.length)); // delivered
      persist(true);
      lastError = null;
    } catch (e) {
      lastError = e.message;
      console.warn('[policy] check-in failed:', e.message);
    }
    return publish();
  }

  function enrollUrl() {
    return config.policyUrl.replace(/checkin\.php(\?.*)?$/, 'enroll.php');
  }

  // Joins a clinic with the access code the administrator gave out. -> { ok, clinic } | { ok:false, error }
  async function enroll(code) {
    if (!active) return { ok: false, error: 'This app is not connected to an admin server.' };
    const nonce = crypto.randomBytes(16).toString('hex');
    try {
      const res = await net.fetch(enrollUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          installId, nonce, code: String(code || ''), appVersion,
          platform: process.platform, device: os.hostname(), osRelease: os.release()
        }),
        signal: AbortSignal.timeout(15000)
      });
      let body = null;
      try { body = await res.json(); } catch (e) { /* not JSON */ }
      if (!res.ok) return { ok: false, error: (body && body.error) || 'The server answered with an error (' + res.status + ').' };
      const p = verifyEnvelope(body, key);
      if (!p || p.installId !== installId || p.nonce !== nonce || p.enrolled !== true) {
        return { ok: false, error: 'The answer could not be verified. Check that you are using the correct server.' };
      }
      await check();
      return { ok: true, clinic: p.clinic };
    } catch (e) {
      return { ok: false, error: 'Cannot reach the server. Check your internet connection and try again.' };
    }
  }

  function start() {
    if (!active) return;
    check();
    timers.push(setInterval(check, 10 * 60 * 1000));
    timers.push(setInterval(() => { persist(false); publish(); }, 60 * 1000)); // catches expiry while running offline
  }

  return {
    active,
    installId,
    start,
    check,
    enroll,
    evaluate,
    getUpdate: () => evaluate().update,
    persist: () => persist(true),
    getLastError: () => lastError
  };
}

module.exports = { createPolicy };
