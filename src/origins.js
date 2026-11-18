'use strict';
// URL / origin helpers. Tab URLs are stored either as an absolute http(s) URL or as a
// path starting with "/" that is resolved against the site's base URL, so changing the
// base URL (installer, setup screen or admin panel) moves every "relative" tab with it.

function parse(value, base) {
  try { return new URL(value, base); } catch (e) { return null; }
}

function isHttp(u) {
  return !!u && (u.protocol === 'http:' || u.protocol === 'https:') && !!u.hostname;
}

// Returns a clean absolute http(s) URL string, or null. "example.com" becomes "https://example.com/".
function normalizeBaseUrl(input) {
  if (typeof input !== 'string') return null;
  let s = input.trim();
  if (!s || s.length > 2048) return null;
  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) s = 'https://' + s;
  const u = parse(s);
  if (!isHttp(u)) return null;
  u.username = '';
  u.password = '';
  return u.href;
}

// Returns the form to store for a tab: "/path" (follows the base URL) or an absolute URL.
function normalizeTabUrl(input, baseUrl) {
  if (typeof input !== 'string') return null;
  let s = input.trim();
  if (!s) s = '/';
  if (s.length > 2048) return null;
  if (s.startsWith('/') && !s.startsWith('//')) {
    return parse(s, baseUrl) ? s : null;
  }
  const abs = normalizeBaseUrl(s);
  if (!abs) return null;
  const u = new URL(abs);
  const b = parse(baseUrl);
  if (b && u.origin === b.origin) return (u.pathname + u.search + u.hash) || '/';
  return abs;
}

function resolveTabUrl(tabUrl, baseUrl) {
  const u = parse(tabUrl || '/', baseUrl);
  return u ? u.href : baseUrl;
}

function isLocalHost(h) {
  return h === 'localhost' || h === '[::1]' || /^127\./.test(h) || /^10\./.test(h) ||
    /^192\.168\./.test(h) || /^172\.(1[6-9]|2\d|3[01])\./.test(h) || h.endsWith('.local');
}

// Credentials are only ever auto-filled over https, or http on localhost / private LAN addresses.
function isTrustedTransport(u) {
  return !!u && (u.protocol === 'https:' || (u.protocol === 'http:' && isLocalHost(u.hostname)));
}

module.exports = { parse, isHttp, normalizeBaseUrl, normalizeTabUrl, resolveTabUrl, isTrustedTransport };
