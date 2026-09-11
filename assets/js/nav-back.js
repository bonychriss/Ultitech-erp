/**
 * One-step-back navigation (system-wide).
 * - Remembers previous in-app URL (+ optional list state) in sessionStorage
 * - Auto-pushes on same-origin link navigations
 * - Intercepts Back controls / links to restore that step
 * - Shows a compact Back control whenever a step is available
 */
(function (global) {
  'use strict';

  var KEY_STACK = 'erpNavBack.stack';
  var KEY_RESTORE = 'erpNavBack.restore';
  var KEY_SKIP_PUSH = 'erpNavBack.skipPushOnce';
  var MAX = 40;
  var CONTROL_ID = 'erp-nav-back-control';

  function sameOrigin(href) {
    try {
      var u = new URL(href, global.location.href);
      return u.origin === global.location.origin;
    } catch (e) {
      return false;
    }
  }

  function readStack() {
    try {
      var raw = sessionStorage.getItem(KEY_STACK);
      var arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr : [];
    } catch (e) {
      return [];
    }
  }

  function writeStack(arr) {
    try {
      sessionStorage.setItem(KEY_STACK, JSON.stringify(arr.slice(-MAX)));
    } catch (e) { /* quota / private mode */ }
  }

  function currentHref() {
    return global.location.pathname + global.location.search + global.location.hash;
  }

  function toAbsolute(href) {
    var abs = new URL(String(href || ''), global.location.href).href;
    return collapseDoubleBase(abs);
  }

  function collapseDoubleBase(href) {
    var cfg = global.__ERP_NAV_BACK_CFG__ || {};
    var base = String(cfg.appBasePath || '').replace(/\/+$/, '');
    if (!base || base === '/') return href;
    try {
      var u = new URL(href, global.location.href);
      var doubled = base + base;
      if (u.pathname.indexOf(doubled + '/') === 0 || u.pathname === doubled) {
        u.pathname = base + u.pathname.slice(doubled.length);
      }
      return u.href;
    } catch (e) {
      return href;
    }
  }

  function pathsCompatible(a, b) {
    function norm(p) {
      p = String(p || '').split('?')[0];
      p = p.replace(/\/+$/, '') || '/';
      p = p.replace(/\.php$/i, '');
      return p.toLowerCase();
    }
    return norm(a) === norm(b);
  }

  function shouldSkipPage() {
    var body = global.document && global.document.body;
    if (!body) return false;
    if (body.classList.contains('page-login') || body.classList.contains('page-register')) return true;
    if (body.getAttribute('data-erp-nav-back') === 'off') return true;
    return false;
  }

  function normalizeEntry(entry) {
    var href = (entry && entry.href) ? String(entry.href) : (global.location.origin + currentHref());
    try {
      href = toAbsolute(href);
    } catch (e) { /* keep raw */ }
    return {
      href: href,
      state: entry && entry.state && typeof entry.state === 'object' ? entry.state : null,
      title: entry && entry.title ? String(entry.title) : (global.document && global.document.title ? String(global.document.title) : ''),
    };
  }

  function push(entry) {
    if (shouldSkipPage()) return;
    try {
      if (sessionStorage.getItem(KEY_SKIP_PUSH) === '1') {
        sessionStorage.removeItem(KEY_SKIP_PUSH);
        return;
      }
    } catch (e) { /* ignore */ }

    var item = normalizeEntry(entry || {});
    if (!item.href || !sameOrigin(item.href)) return;
    var stack = readStack();
    var last = stack.length ? stack[stack.length - 1] : null;
    if (last) {
      try {
        var lastUrl = new URL(last.href, global.location.href);
        var nextUrl = new URL(item.href, global.location.href);
        if (pathsCompatible(lastUrl.pathname, nextUrl.pathname) && lastUrl.search === nextUrl.search) {
          // Upgrade/replace (e.g. auto-push then list push with search state).
          stack[stack.length - 1] = item;
          writeStack(stack);
          refreshControl();
          return;
        }
      } catch (e3) { /* fall through */ }
      if (last.href === item.href && JSON.stringify(last.state) === JSON.stringify(item.state)) {
        return;
      }
    }
    // Avoid no-op pushes of the bare current URL unless state/force is provided.
    try {
      if (toAbsolute(item.href) === toAbsolute(global.location.href) && !(entry && (entry.state || entry.force))) {
        return;
      }
    } catch (e2) { /* continue */ }

    stack.push(item);
    writeStack(stack);
    refreshControl();
  }

  function peek() {
    var stack = readStack();
    return stack.length ? stack[stack.length - 1] : null;
  }

  function pop() {
    var stack = readStack();
    if (!stack.length) return null;
    var item = stack.pop();
    writeStack(stack);
    refreshControl();
    return item;
  }

  function consumeRestore() {
    try {
      var raw = sessionStorage.getItem(KEY_RESTORE);
      if (!raw) return null;
      sessionStorage.removeItem(KEY_RESTORE);
      var data = JSON.parse(raw);
      if (!data || !data.href) return null;
      var target = new URL(data.href, global.location.href);
      if (!pathsCompatible(target.pathname, global.location.pathname)) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function go(fallbackHref) {
    var item = pop();
    if (item && item.href && sameOrigin(item.href)) {
      try {
        sessionStorage.setItem(KEY_RESTORE, JSON.stringify(item));
        sessionStorage.setItem(KEY_SKIP_PUSH, '1');
      } catch (e) { /* ignore */ }
      global.location.assign(toAbsolute(item.href));
      return true;
    }
    if (fallbackHref && sameOrigin(fallbackHref)) {
      try {
        sessionStorage.setItem(KEY_SKIP_PUSH, '1');
      } catch (e2) { /* ignore */ }
      global.location.assign(toAbsolute(fallbackHref));
      return true;
    }
    return false;
  }

  function isIgnorableHref(href) {
    if (!href) return true;
    var h = String(href).trim();
    if (h === '' || h === '#' || h.indexOf('javascript:') === 0 || h.indexOf('mailto:') === 0 || h.indexOf('tel:') === 0) {
      return true;
    }
    return false;
  }

  function isBackAnchor(el) {
    if (!el || el.tagName !== 'A') return false;
    if (el.classList.contains('erp-nav-back-link') || el.classList.contains('vv-breadcrumb-link')) return true;
    if (el.hasAttribute('data-erp-nav-back')) return true;
    var label = (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (label === 'back' || label === '← back' || label === 'go back') return true;
    return false;
  }

  function defaultFallback() {
    var cfg = global.__ERP_NAV_BACK_CFG__ || {};
    if (cfg.fallbackUrl) return String(cfg.fallbackUrl);
    // Prefer company select-module when present in path.
    try {
      var parts = global.location.pathname.split('/').filter(Boolean);
      if (parts.length && /^[a-z0-9-]+$/i.test(parts[0]) && parts[0].toLowerCase() !== 'employee' && parts[0].toLowerCase() !== 'admin' && parts[0].toLowerCase() !== 'modules') {
        return '/' + parts[0] + '/select-module';
      }
    } catch (e) { /* ignore */ }
    return '/select-module.php';
  }

  function ensureStyles() {
    if (global.document.getElementById('erp-nav-back-styles')) return;
    var style = global.document.createElement('style');
    style.id = 'erp-nav-back-styles';
    style.textContent = [
      '#' + CONTROL_ID + '{',
      'position:fixed;right:18px;bottom:18px;z-index:1040;',
      'display:none;align-items:center;gap:8px;',
      'height:40px;padding:0 14px 0 12px;border-radius:999px;',
      'border:1px solid #dbe3f0;background:#fff;color:#0f172a;',
      'box-shadow:0 8px 24px rgba(15,23,42,.14);',
      'font:600 13px/1 "DM Sans","Segoe UI",sans-serif;',
      'cursor:pointer;user-select:none;',
      '}',
      '#' + CONTROL_ID + ':hover{background:#f8fafc;border-color:#cbd5e1;}',
      '#' + CONTROL_ID + ' .erp-nav-back-ico{font-size:16px;line-height:1;}',
      '#' + CONTROL_ID + '.is-visible{display:inline-flex;}',
      'html[data-theme="dark"] #' + CONTROL_ID + '{',
      'background:#1e293b;color:#f8fafc;border-color:#334155;',
      '}',
      '@media print{#' + CONTROL_ID + '{display:none!important;}}',
    ].join('');
    global.document.head.appendChild(style);
  }

  function refreshControl() {
    if (!global.document || !global.document.body || shouldSkipPage()) {
      var existing = global.document && global.document.getElementById(CONTROL_ID);
      if (existing) existing.classList.remove('is-visible');
      return;
    }
    ensureStyles();
    var btn = global.document.getElementById(CONTROL_ID);
    if (!btn) {
      btn = global.document.createElement('button');
      btn.type = 'button';
      btn.id = CONTROL_ID;
      btn.setAttribute('aria-label', 'Go back one step');
      btn.innerHTML = '<span class="erp-nav-back-ico" aria-hidden="true">←</span><span>Back</span>';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        go(defaultFallback());
      });
      global.document.body.appendChild(btn);
    }
    if (readStack().length > 0) {
      btn.classList.add('is-visible');
    } else {
      btn.classList.remove('is-visible');
    }
  }

  function onDocumentClick(e) {
    if (shouldSkipPage()) return;
    if (e.defaultPrevented || e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    var backEl = e.target && e.target.closest
      ? e.target.closest('a.erp-nav-back-link, a.vv-breadcrumb-link, [data-erp-nav-back], a.erp-nav-back')
      : null;
    if (backEl) {
      var backHref = backEl.getAttribute('href') || backEl.getAttribute('data-erp-nav-back') || defaultFallback();
      e.preventDefault();
      go(backHref);
      return;
    }

    // Textual Back anchors without the class (common in older PHP pages)
    var maybeBack = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (maybeBack && isBackAnchor(maybeBack) && !maybeBack.classList.contains('erp-nav-back-ignore')) {
      e.preventDefault();
      go(maybeBack.getAttribute('href') || defaultFallback());
      return;
    }

    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
    if (a.classList.contains('erp-nav-back-ignore')) return;
    var href = a.getAttribute('href');
    if (isIgnorableHref(href)) return;
    if (!sameOrigin(href)) return;

    // Same-page hash only
    try {
      var next = new URL(href, global.location.href);
      if (next.pathname === global.location.pathname && next.search === global.location.search && next.hash !== global.location.hash) {
        return;
      }
      if (next.href === global.location.href) return;
    } catch (err) {
      return;
    }

    // Leaving this page → remember it (including optional list state hooks)
    var state = null;
    try {
      if (typeof global.__erpNavBackCollectState === 'function') {
        state = global.__erpNavBackCollectState();
      }
    } catch (err2) { /* ignore */ }
    push({ href: global.location.href, state: state, force: true });
  }

  global.erpNavBack = {
    push: push,
    peek: peek,
    pop: pop,
    go: go,
    consumeRestore: consumeRestore,
    currentHref: currentHref,
    refreshControl: refreshControl,
  };

  function boot() {
    if (!global.document) return;
    global.document.addEventListener('click', onDocumentClick, true);
    if (global.document.readyState === 'loading') {
      global.document.addEventListener('DOMContentLoaded', refreshControl);
    } else {
      refreshControl();
    }
    global.addEventListener('pageshow', refreshControl);
  }

  boot();
})(window);
