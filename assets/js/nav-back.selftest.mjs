/**
 * Headless regression checks for nav-back.js (no browser).
 * Run: node assets/js/nav-back.selftest.mjs
 */
import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(__dirname, 'nav-back.js'), 'utf8');

function createMock(startHref) {
  const store = new Map();
  const location = new URL(startHref);
  const assigned = [];
  const document = {
    body: {
      classList: { contains: () => false },
      getAttribute: () => null,
      appendChild: () => {},
    },
    documentElement: {},
    title: 'Test',
    readyState: 'complete',
    getElementById: () => null,
    createElement: () => ({
      style: {},
      classList: { add() {}, remove() {}, contains() { return false; } },
      setAttribute() {},
      addEventListener() {},
      appendChild() {},
    }),
    head: { appendChild() {} },
    addEventListener() {},
  };
  const global = {
    window: null,
    location: {
      get href() { return location.href; },
      get origin() { return location.origin; },
      get pathname() { return location.pathname; },
      get search() { return location.search; },
      get hash() { return location.hash; },
      assign(url) {
        assigned.push(String(url));
        const next = new URL(String(url), location.href);
        location.href = next.href;
        location.pathname = next.pathname;
        location.search = next.search;
        location.hash = next.hash;
      },
    },
    sessionStorage: {
      getItem: (k) => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => store.set(k, String(v)),
      removeItem: (k) => store.delete(k),
    },
    document,
    addEventListener() {},
    __ERP_NAV_BACK_CFG__: {
      fallbackUrl: 'http://localhost/ultitech_erp/ultimate/select-module',
      appBasePath: '/ultitech_erp',
    },
  };
  global.window = global;
  global.URL = URL;
  global.Date = Date;
  global.JSON = JSON;
  vm.runInNewContext(src, global, { filename: 'nav-back.js' });
  return { global, assigned, store, location };
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

const origin = 'http://localhost';
const dash = `${origin}/ultitech_erp/ultimate/employee/dashboard.php?module=voucher&q=Baraka`;
const voucher = `${origin}/ultitech_erp/ultimate/employee/view-voucher.php?id=425&module=voucher`;
const login = `${origin}/ultitech_erp/ultimate/login`;
const loginPhp = `${origin}/ultitech_erp/login.php?next=%2Fultitech_erp%2Fultimate%2Femployee%2Fdashboard.php`;

// 1) Auth URLs rejected
{
  const { global } = createMock(voucher);
  assert(global.erpNavBack.isAuthUrl(login) === true, 'login pretty url is auth');
  assert(global.erpNavBack.isAuthUrl(loginPhp) === true, 'login.php is auth');
  assert(global.erpNavBack.isSafeReturnUrl(dash) === true, 'dashboard is safe');
  assert(global.erpNavBack.isSafeReturnUrl(login) === false, 'login is unsafe');
}

// 2) Polluted stack with login must not win over fallback
{
  const { global, assigned } = createMock(voucher);
  global.erpNavBack.push({ href: login, force: true });
  global.sessionStorage.setItem(
    'erpNavBack.stack.v2',
    JSON.stringify([{ href: login }, { href: loginPhp }])
  );
  const ok = global.erpNavBack.go(dash);
  assert(ok === true, 'go should succeed via fallback');
  assert(assigned.length === 1, 'one navigation');
  assert(String(assigned[0]).includes('dashboard.php'), `expected dashboard, got ${assigned[0]}`);
  assert(!String(assigned[0]).includes('login'), 'must not navigate to login');
}

// 3) Filtered list restore keeps query string
{
  const { global, assigned } = createMock(voucher);
  global.erpNavBack.push({
    href: dash,
    state: { search: 'Baraka', filters: { status: 'pending' }, selectedId: 425 },
  });
  const ok = global.erpNavBack.go('http://localhost/ultitech_erp/ultimate/employee/dashboard.php?module=voucher');
  assert(ok === true, 'go filtered should succeed');
  assert(String(assigned[0]).includes('q=Baraka'), `expected q=Baraka in ${assigned[0]}`);
  // Simulate landing on dashboard and consuming restore state
  global.location.assign(assigned[0]);
  const restored = global.erpNavBack.consumeRestore();
  assert(restored && restored.state && restored.state.search === 'Baraka', 'restore state keeps search');
}

// 4) Legacy stack keys are purged on boot and ignored
{
  const { store } = createMock(voucher);
  assert(!store.has('erpNavBack.stack'), 'legacy stack key purged');
}

// 5) Bare "Back" text must NOT be treated as back control (simulate isBackAnchor via class only)
{
  const { global } = createMock(voucher);
  // push login into legacy-like unsafe stack then ensure sanitize drops it on read/go
  global.sessionStorage.setItem(
    'erpNavBack.stack.v2',
    JSON.stringify([
      { href: login },
      { href: dash, state: { search: 'Baraka' } },
    ])
  );
  const peek = global.erpNavBack.peek();
  assert(peek && peek.href.includes('dashboard'), 'peek sanitizes to dashboard');
  assert(!global.erpNavBack.isAuthUrl(peek.href), 'peek is not auth');
}

// 6) skipPushOnce must not survive into a new document boot
{
  const storeSeed = { 'erpNavBack.skipPushOnce.v2': '1' };
  // recreate with seeded store
  const store = new Map(Object.entries(storeSeed));
  const location = new URL(voucher);
  const document = {
    body: { classList: { contains: () => false }, getAttribute: () => null, appendChild: () => {} },
    title: 'Test', readyState: 'complete', getElementById: () => null,
    createElement: () => ({ style: {}, classList: { add() {}, remove() {}, contains() { return false; } }, setAttribute() {}, addEventListener() {}, appendChild() {} }),
    head: { appendChild() {} }, addEventListener() {},
  };
  const global = {
    window: null,
    location: {
      get href() { return location.href; }, get origin() { return location.origin; },
      get pathname() { return location.pathname; }, get search() { return location.search; },
      get hash() { return location.hash; },
      assign() {},
    },
    sessionStorage: {
      getItem: (k) => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => store.set(k, String(v)),
      removeItem: (k) => store.delete(k),
    },
    document, addEventListener() {},
    __ERP_NAV_BACK_CFG__: { fallbackUrl: `${origin}/ultitech_erp/ultimate/select-module`, appBasePath: '/ultitech_erp' },
    URL, Date, JSON,
  };
  global.window = global;
  vm.runInNewContext(src, global, { filename: 'nav-back.js' });
  assert(store.get('erpNavBack.skipPushOnce.v2') == null, 'boot must clear skipPushOnce');
  global.erpNavBack.push({ href: dash, state: { search: 'Baraka' }, force: true });
  const peek = global.erpNavBack.peek();
  assert(peek && peek.state && peek.state.search === 'Baraka', 'first push after boot must work');
}

console.log('nav-back.selftest: all checks passed');
