/**
 * Sitewide dark palette: rewrites slate/navy neutrals inside dark-mode CSS rules
 * (selectors containing [data-theme="dark"]) to the Accounting desk palette
 * (#0b141a page, #111b21 surface, #202c33 raised, #2a3942 border, #e9edef text, #8696a0 muted).
 * Light-mode rules are never touched, so module stylesheets keep working unchanged.
 */
(function () {
  'use strict';
  if (window.__erpDarkPalette) return;
  window.__erpDarkPalette = true;

  var MAP = {
    '020617': '0b141a', '030712': '0b141a', '0b1120': '0b141a', '0f172a': '0b141a', '111827': '0b141a',
    '1e293b': '111b21', '1f2937': '111b21', '172033': '111b21', '18212f': '111b21',
    '273449': '202c33', '243049': '202c33', '283548': '202c33', '1c2a44': '202c33', '374151': '202c33',
    '334155': '2a3942',
    '475569': '3b4a54', '4b5563': '3b4a54',
    'f8fafc': 'e9edef', 'f1f5f9': 'e9edef', 'e2e8f0': 'e9edef', 'f3f4f6': 'e9edef', 'e5e7eb': 'e9edef',
    'cbd5e1': 'd1d7db', 'd1d5db': 'd1d7db',
    '94a3b8': '8696a0', '9ca3af': '8696a0',
    '64748b': '667781', '6b7280': '667781'
  };

  var RGB = {};
  Object.keys(MAP).forEach(function (k) {
    var t = MAP[k];
    RGB[parseInt(k.slice(0, 2), 16) + ',' + parseInt(k.slice(2, 4), 16) + ',' + parseInt(k.slice(4, 6), 16)] =
      [parseInt(t.slice(0, 2), 16), parseInt(t.slice(2, 4), 16), parseInt(t.slice(4, 6), 16)];
  });

  var HEX_RE = /#([0-9a-fA-F]{6})([0-9a-fA-F]{2})?\b/g;
  var RGB_RE = /rgba?\(\s*(\d{1,3})\s*,?\s*(\d{1,3})\s*,?\s*(\d{1,3})/g;

  function remapValue(v) {
    if (!v || (v.indexOf('#') === -1 && v.indexOf('rgb') === -1)) return v;
    return v
      .replace(HEX_RE, function (m, hex, alpha) {
        var t = MAP[hex.toLowerCase()];
        return t ? '#' + t + (alpha || '') : m;
      })
      .replace(RGB_RE, function (m, r, g, b) {
        var t = RGB[(+r) + ',' + (+g) + ',' + (+b)];
        if (!t) return m;
        return m.slice(0, m.indexOf('(') + 1) + t[0] + ', ' + t[1] + ', ' + t[2];
      });
  }

  function isDarkSelector(sel) {
    if (!sel || sel.indexOf('data-theme') === -1 || sel.indexOf('dark') === -1) return false;
    var parts = sel.split(',');
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i];
      if (/\[data-theme\s*=\s*["']?dark["']?\]/.test(p) && !/:not\(\s*\[data-theme\s*=\s*["']?dark/.test(p)) {
        return true;
      }
    }
    return false;
  }

  function remapStyle(style) {
    var props = [];
    for (var i = 0; i < style.length; i++) props.push(style[i]);
    for (var j = 0; j < props.length; j++) {
      var prop = props[j];
      var val = style.getPropertyValue(prop);
      var next = remapValue(val);
      if (next !== val) style.setProperty(prop, next, style.getPropertyPriority(prop));
    }
  }

  function walkRules(rules, inDark) {
    if (!rules) return;
    for (var i = 0; i < rules.length; i++) {
      var r = rules[i];
      var dark = inDark;
      if (r.selectorText !== undefined) {
        dark = inDark || isDarkSelector(r.selectorText);
        if (dark && r.style) remapStyle(r.style);
      }
      var children = null;
      try { children = r.cssRules; } catch (e) { children = null; }
      if (children && children.length) walkRules(children, dark);
    }
  }

  var done = typeof WeakSet === 'function' ? new WeakSet() : null;

  function processSheet(sheet) {
    if (!sheet || (done && done.has(sheet))) return;
    var rules;
    try { rules = sheet.cssRules; } catch (e) { return; }
    if (!rules) return;
    if (done) done.add(sheet);
    walkRules(rules, false);
  }

  function processAll() {
    var sheets = document.styleSheets;
    for (var i = 0; i < sheets.length; i++) processSheet(sheets[i]);
  }

  function watchNode(node) {
    if (!node || node.nodeType !== 1) return;
    var tag = node.tagName;
    if (tag === 'STYLE') {
      processSheet(node.sheet);
    } else if (tag === 'LINK' && /stylesheet/i.test(node.rel || '')) {
      if (node.sheet) processSheet(node.sheet);
      node.addEventListener('load', function () { processSheet(node.sheet); });
    }
  }

  processAll();

  if (typeof MutationObserver === 'function') {
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.target && m.target.tagName === 'STYLE') {
          if (m.target.sheet) {
            if (done) done.delete(m.target.sheet);
            processSheet(m.target.sheet);
          }
        } else if (m.type === 'childList') {
          for (var j = 0; j < m.addedNodes.length; j++) {
            var n = m.addedNodes[j];
            watchNode(n);
            if (n.nodeType === 1 && n.querySelectorAll && n.tagName !== 'STYLE' && n.tagName !== 'LINK') {
              var inner = n.querySelectorAll('style, link[rel~="stylesheet"]');
              for (var k = 0; k < inner.length; k++) watchNode(inner[k]);
            }
          }
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  document.addEventListener('DOMContentLoaded', processAll);
  window.addEventListener('load', processAll);
})();
