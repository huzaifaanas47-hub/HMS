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

// Folder part of the site address: "https://x.com/hims2/index.php" -> "/hims2/"; "https://x.com" -> "/".
function baseDir(baseUrl) {
  const b = typeof baseUrl === 'string' ? parse(baseUrl) : baseUrl;
  if (!b) return '/';
  const p = b.pathname || '/';
  return p.endsWith('/') ? p : p.slice(0, p.lastIndexOf('/') + 1);
}

// Returns the form to store for a tab: "/path" (follows the base URL) or an absolute URL.
// "/" means "the site address exactly as entered" (including a sub-folder and index.php).
// "/login.php" means "login.php inside the site address's folder".
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
  if (b && u.origin === b.origin) {
    if (u.pathname === b.pathname && u.search === b.search) return u.hash ? '/' + u.hash : '/';
    const dir = baseDir(b);
    if (u.pathname.startsWith(dir)) return '/' + u.pathname.slice(dir.length) + u.search + u.hash;
    return abs; // same server but outside the site folder: keep it absolute
  }
  return abs;
}

function resolveTabUrl(tabUrl, baseUrl) {
  const b = parse(baseUrl);
  if (!b) return baseUrl;
  const t = (typeof tabUrl === 'string' ? tabUrl.trim() : '') || '/';
  if (/^https?:\/\//i.test(t)) { const a = parse(t); return a ? a.href : b.href; }
  if (t === '/') return b.href;                       // the site address itself
  if (t.startsWith('/') && !t.startsWith('//')) {     // inside the site address's folder
    const u = parse(baseDir(b) + t.slice(1), b.origin);
    return u ? u.href : b.href;
  }
  const u = parse(t, b.href);
  return u ? u.href : b.href;
}

function isLocalHost(h) {
  return h === 'localhost' || h === '[::1]' || /^127\./.test(h) || /^10\./.test(h) ||
    /^192\.168\./.test(h) || /^172\.(1[6-9]|2\d|3[01])\./.test(h) || h.endsWith('.local');
}

// Credentials are only ever auto-filled over https, or http on localhost / private LAN addresses.
function isTrustedTransport(u) {
  return !!u && (u.protocol === 'https:' || (u.protocol === 'http:' && isLocalHost(u.hostname)));
}

module.exports = { parse, isHttp, baseDir, normalizeBaseUrl, normalizeTabUrl, resolveTabUrl, isTrustedTransport };
