/**
 * One-step-back navigation (system-wide).
 * - Remembers previous in-app URL (+ optional list state) in sessionStorage
 * - Auto-pushes on same-origin link navigations
 * - Intercepts explicit Back controls to restore that step (not plain breadcrumbs)
 * - Shows a compact Back control whenever a step is available
 * - Never stores or restores auth pages (login/register/logout)
 */
(function (global) {
  'use strict';

  // v2 invalidates stacks polluted by the first RC (auth URLs / bare-"Back" hijacks).
  var KEY_STACK = 'erpNavBack.stack.v2';
  var KEY_RESTORE = 'erpNavBack.restore.v2';
  var KEY_SKIP_PUSH = 'erpNavBack.skipPushOnce.v2';
  var LEGACY_KEYS = [
    'erpNavBack.stack',
    'erpNavBack.restore',
    'erpNavBack.skipPushOnce',
  ];
  var MAX = 40;
  var CONTROL_ID = 'erp-nav-back-control';

  function purgeLegacyKeys() {
    try {
      for (var i = 0; i < LEGACY_KEYS.length; i++) {
        sessionStorage.removeItem(LEGACY_KEYS[i]);
      }
    } catch (e) { /* ignore */ }
  }

  function sameOrigin(href) {
    try {
      var u = new URL(href, global.location.href);
      return u.origin === global.location.origin;
    } catch (e) {
      return false;
    }
  }

  function isAuthUrl(href) {
    try {
      var u = new URL(String(href || ''), global.location.href);
      var path = u.pathname.toLowerCase().replace(/\/+$/, '');
      if (/(^|\/)login(\.php)?$/.test(path)) return true;
      if (/(^|\/)register(\.php)?$/.test(path)) return true;
      if (/(^|\/)register-(employee|admin)(\.php)?$/.test(path)) return true;
      if (/(^|\/)logout(\.php)?$/.test(path)) return true;
      if (/(^|\/)trial-welcome(\.php)?$/.test(path)) return true;
      if (/(^|\/)trial-expired(\.php)?$/.test(path)) return true;
      if (/(^|\/)page-expired(\.php)?$/.test(path)) return true;
      if (/(^|\/)free-trial(\.php)?$/.test(path)) return true;
      if (/(^|\/)reset-password(\.php)?$/.test(path)) return true;
      return false;
    } catch (e) {
      return false;
    }
  }

  function isSafeReturnUrl(href) {
    if (!href || !sameOrigin(href) || isAuthUrl(href)) return false;
    try {
      var u = new URL(String(href), global.location.href);
      // Reject empty / root-only returns that often bounce into the auth gate.
      var path = u.pathname.replace(/\/+$/, '') || '/';
      if (path === '/' || path === '') return false;
      return true;
    } catch (e) {
      return false;
    }
  }

  function readStackRaw() {
    try {
      var raw = sessionStorage.getItem(KEY_STACK);
      var arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr : [];
    } catch (e) {
      return [];
    }
  }

  function sanitizeStack(arr) {
    var out = [];
    for (var i = 0; i < arr.length; i++) {
      var item = arr[i];
      if (!item || !item.href) continue;
      if (!isSafeReturnUrl(item.href)) continue;
      out.push(item);
    }
    return out;
  }

  function readStack() {
    var raw = readStackRaw();
    var cleaned = sanitizeStack(raw);
    if (cleaned.length !== raw.length) {
      writeStack(cleaned);
    }
    return cleaned;
  }

  function writeStack(arr) {
    try {
      sessionStorage.setItem(KEY_STACK, JSON.stringify(sanitizeStack(arr).slice(-MAX)));
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
    if (isAuthUrl(global.location.href)) return true;
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
      ts: Date.now(),
    };
  }

  function push(entry) {
    if (shouldSkipPage()) return;
    try {
      // Same-document only: go() sets this so a trailing click handler on the
      // leaving page does not push again. Cleared on every new page boot.
      if (sessionStorage.getItem(KEY_SKIP_PUSH) === '1') {
        sessionStorage.removeItem(KEY_SKIP_PUSH);
        return;
      }
    } catch (e) { /* ignore */ }

    var item = normalizeEntry(entry || {});
    if (!item.href || !isSafeReturnUrl(item.href)) return;
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
      if (!data || !data.href || !isSafeReturnUrl(data.href)) return null;
      var target = new URL(data.href, global.location.href);
      if (!pathsCompatible(target.pathname, global.location.pathname)) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function go(fallbackHref) {
    var item = null;
    // Skip unsafe entries left in an old or corrupted stack.
    while (true) {
      item = pop();
      if (!item) break;
      if (item.href && isSafeReturnUrl(item.href)) {
        // Never "return" to the page we are already on.
        try {
          if (pathsCompatible(new URL(item.href, global.location.href).pathname, global.location.pathname)
            && new URL(item.href, global.location.href).search === global.location.search) {
            item = null;
            continue;
          }
        } catch (eSame) { /* keep item */ }
        break;
      }
      item = null;
    }
    if (item && item.href) {
      try {
        sessionStorage.setItem(KEY_RESTORE, JSON.stringify(item));
        sessionStorage.setItem(KEY_SKIP_PUSH, '1');
      } catch (e) { /* ignore */ }
      global.location.assign(toAbsolute(item.href));
      return true;
    }
    if (fallbackHref && isSafeReturnUrl(fallbackHref)) {
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
    // Only explicit markers — never match bare "Back" text (that stole voucher Actions → Back).
    // Do not treat vv-breadcrumb-link as Back (Home/Vouchers must keep their href).
    if (el.classList.contains('erp-nav-back-link')) return true;
    if (el.classList.contains('erp-nav-back')) return true;
    if (el.hasAttribute('data-erp-nav-back') && el.getAttribute('data-erp-nav-back') !== 'off') return true;
    return false;
  }

  function defaultFallback() {
    var cfg = global.__ERP_NAV_BACK_CFG__ || {};
    if (cfg.fallbackUrl && isSafeReturnUrl(cfg.fallbackUrl)) return String(cfg.fallbackUrl);
    try {
      var parts = global.location.pathname.split('/').filter(Boolean);
      var base = String(cfg.appBasePath || '').replace(/^\/+|\/+$/g, '').toLowerCase();
      if (base && parts.length && parts[0].toLowerCase() === base) {
        parts = parts.slice(1);
      }
      var slug = parts[0] || '';
      if (slug && /^[a-z0-9-]+$/i.test(slug)
        && !['employee', 'admin', 'modules', 'assets', 'api', 'includes'].includes(slug.toLowerCase())) {
        return '/' + (base ? base + '/' : '') + slug + '/select-module';
      }
    } catch (e) { /* ignore */ }
    return (cfg.appBasePath || '') + '/select-module.php';
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
      ? e.target.closest('a.erp-nav-back-link, a.erp-nav-back, a[data-erp-nav-back], button[data-erp-nav-back]')
      : null;
    if (backEl) {
      if (backEl.classList.contains('erp-nav-back-ignore')) return;
      var rawAttr = backEl.getAttribute('data-erp-nav-back');
      if (rawAttr === 'off') return;
      var backHref = backEl.getAttribute('href') || (rawAttr && rawAttr !== '1' && rawAttr !== 'true' ? rawAttr : '') || defaultFallback();
      e.preventDefault();
      go(backHref);
      return;
    }

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
    // Leaving toward an auth page should not keep chaining return history into auth.
    if (isAuthUrl(href)) return;

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
    isAuthUrl: isAuthUrl,
    isSafeReturnUrl: isSafeReturnUrl,
  };

  function boot() {
    if (global.__erpNavBackBooted) return;
    global.__erpNavBackBooted = true;
    purgeLegacyKeys();
    try {
      // go() may have set skipPushOnce on the previous page; never let it
      // survive into this document or it swallows the next list→detail push.
      sessionStorage.removeItem(KEY_SKIP_PUSH);
    } catch (eBoot) { /* ignore */ }
    if (!global.document) return;
    // Drop any unsafe entries immediately (including after login).
    readStack();
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
