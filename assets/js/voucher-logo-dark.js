/* Recolors the voucher logo for dark theme: white background becomes transparent, near-black becomes light, brand colors are kept. */
(function () {
  if (window.__vvLogoDark) return;
  window.__vvLogoDark = true;
  var SEL = '.voucher-paper .pv-header img[alt="Logo"], .voucher-logo-wrap img, .sidebar-container .sidebar-logo img, #native-sidebar .logo-container img, #native-sidebar .sidebar-header img, #native-sidebar img[alt="Logo"], #native-sidebar img[alt="Company Logo"]';
  var cache = {};

  function isDark() {
    return document.documentElement.getAttribute('data-theme') === 'dark';
  }

  function siblingDark(src) {
    var clean = src.split('#')[0].split('?')[0];
    if (!/\.(png|jpe?g)$/i.test(clean) || /-dark\.png$/i.test(clean)) return '';
    return clean.replace(/\.(png|jpe?g)$/i, '-dark.png');
  }

  function convert(img) {
    var src = img.getAttribute('data-logo-orig') || img.currentSrc || img.src;
    if (!src || img.getAttribute('data-logo-state') === 'dark') return;
    if (cache[src]) { apply(img, src, cache[src]); return; }
    var dark = siblingDark(src);
    if (dark && cache[src] !== false) {
      var pre = new Image();
      pre.onload = function () { cache[src] = dark; apply(img, src, dark); };
      pre.onerror = function () { cache[src] = false; if (isDark()) canvasConvert(img, src); };
      pre.src = dark;
      return;
    }
    canvasConvert(img, src);
  }

  function canvasConvert(img, src) {
    if (img.getAttribute('data-logo-state') === 'dark') return;
    var probe = new Image();
    probe.crossOrigin = 'anonymous';
    probe.onload = function () {
      try {
        var w = probe.naturalWidth, h = probe.naturalHeight;
        if (!w || !h) return;
        var c = document.createElement('canvas');
        c.width = w; c.height = h;
        var ctx = c.getContext('2d');
        ctx.drawImage(probe, 0, 0);
        var d = ctx.getImageData(0, 0, w, h), p = d.data;
        for (var i = 0; i < p.length; i += 4) {
          var r = p[i], g = p[i + 1], b = p[i + 2];
          var max = Math.max(r, g, b), min = Math.min(r, g, b);
          var sat = max === 0 ? 0 : (max - min) / max;
          var lum = 0.299 * r + 0.587 * g + 0.114 * b;
          if (sat < 0.18 && lum > 215) {
            p[i + 3] = Math.max(0, Math.round((255 - lum) * 6));
            p[i] = 233; p[i + 1] = 237; p[i + 2] = 239;
          } else if ((sat < 0.3 && lum < 150) || lum < 70) {
            var t = 1 - lum / (lum < 70 && sat >= 0.3 ? 70 : 150);
            p[i] = Math.round(r + (233 - r) * t);
            p[i + 1] = Math.round(g + (237 - g) * t);
            p[i + 2] = Math.round(b + (239 - b) * t);
          }
        }
        ctx.putImageData(d, 0, 0);
        cache[src] = c.toDataURL('image/png');
        apply(img, src, cache[src]);
      } catch (e) { /* tainted canvas: keep the white chip fallback */ }
    };
    probe.src = src;
  }

  function apply(img, orig, dataUrl) {
    img.setAttribute('data-logo-orig', orig);
    img.setAttribute('data-logo-state', 'dark');
    img.src = dataUrl;
    img.removeAttribute('srcset');
    img.classList.add('vv-logo-recolored');
  }

  function restore(img) {
    var orig = img.getAttribute('data-logo-orig');
    if (orig && img.getAttribute('data-logo-state') === 'dark') {
      img.src = orig;
      img.setAttribute('data-logo-state', 'light');
      img.classList.remove('vv-logo-recolored');
    }
  }

  function run() {
    var imgs = document.querySelectorAll(SEL);
    Array.prototype.forEach.call(imgs, function (img) {
      if (isDark()) {
        if (img.getAttribute('data-logo-state') === 'light') img.removeAttribute('data-logo-state');
        if (img.complete && img.naturalWidth) convert(img);
        else img.addEventListener('load', function () { if (isDark()) convert(img); }, { once: true });
      } else {
        restore(img);
      }
    });
  }

  new MutationObserver(run).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  new MutationObserver(function () { if (isDark()) run(); }).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('beforeprint', function () {
    Array.prototype.forEach.call(document.querySelectorAll(SEL), restore);
  });
  window.addEventListener('afterprint', run);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
