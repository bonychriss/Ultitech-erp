/**
 * Headless checks for theme-toggle persistence + single-control API.
 * Run: node assets/js/theme-toggle.selftest.mjs
 */
import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(__dirname, 'theme-toggle.js'), 'utf8');

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

function createEnv(initialTheme, prefersDark = false) {
  const store = new Map();
  if (initialTheme) store.set('theme', initialTheme);
  const listeners = {};
  const btn = {
    attrs: {},
    classList: {
      _set: new Set(),
      add(c) { this._set.add(c); },
      remove(c) { this._set.delete(c); },
      toggle(c, on) { if (on) this._set.add(c); else this._set.delete(c); },
      contains(c) { return this._set.has(c); },
    },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; },
    addEventListener() {},
    querySelector() { return null; },
  };
  const resolved = initialTheme === 'system'
    ? (prefersDark ? 'dark' : 'light')
    : (initialTheme === 'dark' ? 'dark' : 'light');
  const documentElement = {
    attrs: {
      'data-theme': resolved,
      'data-theme-pref': initialTheme || 'light',
    },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    getAttribute(k) { return this.attrs[k] || null; },
  };
  const mediaListeners = [];
  const global = {
    window: null,
    matchMedia(query) {
      return {
        matches: prefersDark && String(query).includes('dark'),
        media: query,
        addEventListener(_type, fn) { mediaListeners.push(fn); },
        addListener(fn) { mediaListeners.push(fn); },
        removeEventListener() {},
        removeListener() {},
      };
    },
    document: {
      readyState: 'complete',
      documentElement,
      body: { appendChild() {} },
      querySelectorAll(sel) {
        if (sel === '[data-erp-theme-toggle]') return [btn];
        return [];
      },
      createElement() {
        return { style: {}, setAttribute() {}, textContent: '', parentNode: null };
      },
      addEventListener() {},
    },
    localStorage: {
      getItem: (k) => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => store.set(k, String(v)),
      removeItem: (k) => store.delete(k),
    },
    setTimeout: (fn) => { fn(); return 1; },
    CustomEvent: function CustomEvent(name, init) { this.type = name; this.detail = init && init.detail; },
    dispatchEvent(ev) {
      (listeners[ev.type] || []).forEach((fn) => fn(ev));
      return true;
    },
    addEventListener(type, fn) {
      listeners[type] = listeners[type] || [];
      listeners[type].push(fn);
    },
  };
  global.window = global;
  vm.runInNewContext(src, global, { filename: 'theme-toggle.js' });
  return { global, store, btn, documentElement, mediaListeners };
}

{
  const { global, store, btn, documentElement } = createEnv('light');
  assert(global.erpThemeToggle.currentTheme() === 'light', 'starts light');
  global.erpThemeToggle.setTheme('dark', { toast: false });
  assert(documentElement.getAttribute('data-theme') === 'dark', 'html data-theme dark');
  assert(store.get('theme') === 'dark', 'localStorage persists dark');
  assert(btn.getAttribute('aria-pressed') === 'true', 'aria-pressed true');
  assert(btn.getAttribute('data-theme-state') === 'dark', 'data-theme-state dark');
  assert(btn.classList.contains('is-dark'), 'is-dark class');
}

{
  const { global, store, documentElement } = createEnv('dark');
  assert(global.erpThemeToggle.currentTheme() === 'dark', 'restores dark from storage/attr');
  global.erpThemeToggle.setTheme('light', { toast: false });
  assert(documentElement.getAttribute('data-theme') === 'light', 'back to light');
  assert(store.get('theme') === 'light', 'localStorage persists light');
}

{
  const { global, store, documentElement } = createEnv('light', true);
  global.erpThemeToggle.setTheme('system', { toast: false });
  assert(store.get('theme') === 'system', 'localStorage persists system');
  assert(documentElement.getAttribute('data-theme-pref') === 'system', 'pref attr system');
  assert(documentElement.getAttribute('data-theme') === 'dark', 'system resolves to device dark');
  assert(global.erpThemeToggle.getPreference() === 'system', 'getPreference system');
}

{
  const { global, store, documentElement } = createEnv('system', false);
  assert(global.erpThemeToggle.getPreference() === 'system', 'keeps system preference');
  assert(documentElement.getAttribute('data-theme') === 'light', 'system resolves to device light');
  global.erpThemeToggle.setTheme('dark', { toast: false });
  assert(store.get('theme') === 'dark', 'explicit dark exits system');
}

console.log('theme-toggle.selftest: all checks passed');
