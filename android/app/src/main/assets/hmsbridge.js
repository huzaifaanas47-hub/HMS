(function () {
  // Makes printing and file saving work inside the app's WebView (it has no print dialog and cannot download blob: files by itself).
  if (window.__hmsBridge || !window.HmsApp) return;
  window.__hmsBridge = true;
  var B = window.HmsApp;
  window.print = function () { try { B.print(); } catch (e) {} };

  function saveUrl(url, name, mime) {
    fetch(url).then(function (r) { return r.blob(); }).then(function (b) {
      var fr = new FileReader();
      fr.onloadend = function () {
        var s = String(fr.result || ''), i = s.indexOf(',');
        try { B.saveFile(name || 'download', mime || b.type || 'application/octet-stream', i >= 0 ? s.substring(i + 1) : ''); } catch (e) {}
      };
      fr.readAsDataURL(b);
    }).catch(function () {});
  }
  window.__hmsSaveUrl = saveUrl;

  function local(u) { return /^(blob|data):/i.test(u || ''); }

  // a.click() / dispatchEvent used by "save file" helpers (FileSaver, jsPDF ...)
  var oc = HTMLAnchorElement.prototype.click;
  HTMLAnchorElement.prototype.click = function () {
    if (local(this.href) && this.hasAttribute('download')) { saveUrl(this.href, this.getAttribute('download') || '', this.type || ''); return; }
    return oc.apply(this, arguments);
  };
  var od = EventTarget.prototype.dispatchEvent;
  EventTarget.prototype.dispatchEvent = function (ev) {
    if (ev && ev.type === 'click' && this instanceof HTMLAnchorElement && local(this.href) && this.hasAttribute('download')) {
      saveUrl(this.href, this.getAttribute('download') || '', this.type || '');
      return true;
    }
    return od.apply(this, arguments);
  };
  // real clicks on <a download href="blob:...">
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[download]') : null;
    if (a && local(a.href)) { e.preventDefault(); saveUrl(a.href, a.getAttribute('download') || '', a.type || ''); }
  }, true);
  // window.open(blobUrl) for a generated PDF
  var ow = window.open;
  window.open = function (u) {
    if (typeof u === 'string' && local(u)) { saveUrl(u, '', ''); return null; }
    return ow.apply(window, arguments);
  };
})();
