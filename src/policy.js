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

  // -> { state: 'ok'|'expired'|'blocked'|'stale', baseUrl, message, expiresAt, daysLeft }
  function evaluate() {
    const out = { state: 'ok', baseUrl: null, message: null, expiresAt: null, daysLeft: null };
    const c = loadCached();
    if (!c) return out;
    if (c.invalid) { out.state = 'stale'; return out; }
    const p = c.p;
    const t = now();
    out.baseUrl = p.baseUrl || null;
    out.message = p.message || null;
    out.expiresAt = p.expiresAt || null;
    if (p.expiresAt) out.daysLeft = Math.ceil((p.expiresAt - t) / DAY);
    if (p.status === 'blocked') out.state = 'blocked';
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
    try {
      const res = await net.fetch(config.policyUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          installId, nonce, appVersion,
          platform: process.platform,
          device: os.hostname(),
          osRelease: os.release()
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
      persist(true);
      lastError = null;
    } catch (e) {
      lastError = e.message;
      console.warn('[policy] check-in failed:', e.message);
    }
    return publish();
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
    evaluate,
    persist: () => persist(true),
    getLastError: () => lastError
  };
}

module.exports = { createPolicy };
