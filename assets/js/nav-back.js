/**
 * One-step-back navigation helper.
 * Stores the previous in-app URL + optional list state (search/filters)
 * in sessionStorage. Does not replace existing back URLs when the stack is empty.
 */
(function (global) {
  'use strict';

  var KEY_STACK = 'erpNavBack.stack';
  var KEY_RESTORE = 'erpNavBack.restore';
  var MAX = 20;

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
    return new URL(String(href || ''), global.location.href).href;
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

  function normalizeEntry(entry) {
    var href = (entry && entry.href) ? String(entry.href) : (global.location.origin + currentHref());
    try {
      href = toAbsolute(href);
    } catch (e) { /* keep raw */ }
    return {
      href: href,
      state: entry && entry.state && typeof entry.state === 'object' ? entry.state : null,
    };
  }

  function push(entry) {
    var item = normalizeEntry(entry || {});
    if (!item.href || !sameOrigin(item.href)) return;
    var stack = readStack();
    var last = stack.length ? stack[stack.length - 1] : null;
    if (last && last.href === item.href && JSON.stringify(last.state) === JSON.stringify(item.state)) {
      return;
    }
    stack.push(item);
    writeStack(stack);
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
      } catch (e) { /* ignore */ }
      global.location.assign(toAbsolute(item.href));
      return true;
    }
    if (fallbackHref && sameOrigin(fallbackHref)) {
      global.location.assign(toAbsolute(fallbackHref));
      return true;
    }
    return false;
  }

  global.erpNavBack = {
    push: push,
    peek: peek,
    pop: pop,
    go: go,
    consumeRestore: consumeRestore,
    currentHref: currentHref,
  };

  if (global.document) {
    global.document.addEventListener('click', function (e) {
      var a = e.target && e.target.closest
        ? e.target.closest('a.vv-breadcrumb-link, a.erp-nav-back-link')
        : null;
      if (!a || e.defaultPrevented || e.button !== 0) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      if (!global.erpNavBack) return;
      e.preventDefault();
      go(a.getAttribute('href'));
    }, true);
  }
})(window);
