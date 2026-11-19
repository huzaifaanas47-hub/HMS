(function () {
  try {
    var ORIGIN = __ORIGIN__, EMAIL = __EMAIL__, PASS = __PASS__;
    if (location.origin !== ORIGIN) return; // the login only ever goes to this role's own site
    var done = new WeakSet();

    function visible(el) {
      var r = el.getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0) return false;
      var cs = getComputedStyle(el);
      return cs.visibility !== 'hidden' && cs.display !== 'none';
    }
    function setVal(el, v) {
      if (!el || !v) return;
      var d = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value');
      (d && d.set ? d.set : function (x) { el.value = x; }).call(el, v);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function userField(pw) {
      var scope = pw.form || document;
      var all = Array.prototype.slice.call(scope.querySelectorAll('input')).filter(function (i) {
        var t = (i.getAttribute('type') || 'text').toLowerCase();
        return (t === 'text' || t === 'email' || t === 'tel') && !i.disabled && !i.readOnly && visible(i);
      });
      var before = all.filter(function (i) { return !!(i.compareDocumentPosition(pw) & Node.DOCUMENT_POSITION_FOLLOWING); });
      return before.length ? before[before.length - 1] : null;
    }
    function visiblePasswords(scope) {
      return Array.prototype.slice.call(scope.querySelectorAll('input[type=password]')).filter(visible);
    }

    // Fills each login form once. It re-runs when the page changes, so after the site signs you out
    // (redirect to the login page, or the form being drawn again) the login is filled again.
    function run() {
      var pws = visiblePasswords(document);
      for (var i = 0; i < pws.length; i++) {
        var pw = pws[i];
        if (done.has(pw) || pw.disabled || pw.readOnly || pw.value) continue;
        if ((pw.getAttribute('autocomplete') || '').toLowerCase() === 'new-password') continue;
        if (visiblePasswords(pw.form || document).length !== 1) continue; // sign-up / change-password forms are left alone
        done.add(pw);
        var u = userField(pw);
        if (u && !u.value) setVal(u, EMAIL);
        setVal(pw, PASS);
      }
    }

    run();
    if (window.__hmsFillObserver) window.__hmsFillObserver.disconnect();
    var timer = null;
    window.__hmsFillObserver = new MutationObserver(function () { clearTimeout(timer); timer = setTimeout(run, 150); });
    window.__hmsFillObserver.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
  } catch (e) { /* silent */ }
})();
