/**
 * Capture screenshots of all Accounting hub + related pages into docs/accounting-screenshots/
 *
 * Usage:
 *   node scripts/capture-accounting-screenshots.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const outDir = path.join(root, 'docs', 'accounting-screenshots');
const phpBin = 'C:\\xampp\\php\\php.exe';

fs.mkdirSync(outDir, { recursive: true });

function bootstrapSession() {
  const raw = execFileSync(phpBin, [path.join(root, 'scripts', '_bootstrap_screenshot_session.php')], {
    cwd: root,
    encoding: 'utf8',
  });
  const line = raw
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean)
    .find((l) => l.startsWith('{'));
  if (!line) throw new Error('Session bootstrap returned no JSON: ' + raw.slice(0, 400));
  return JSON.parse(line);
}

const pages = [
  { id: '00-accounting-hub', path: '/ultimate/accounting?module=accounting' },
  { id: '01-balances', path: '/ultimate/modules/balances/index?module=balances' },
  { id: '02-revenue', path: '/ultimate/revenue_entries?module=revenue' },
  { id: '03-expenses', path: '/ultimate/modules/expenses/index?module=expenses' },
  { id: '04-purchases', path: '/ultimate/modules/purchases/index?module=purchases' },
  { id: '05-journal-entries', path: '/ultimate/accounting/journal-entries?module=accounting' },
  { id: '06-vat-control', path: '/ultimate/accounting/vat-control?module=accounting' },
  { id: '07-accounting-settings', path: '/ultimate/accounting/settings?module=accounting' },
  { id: '08-journal-configuration', path: '/ultimate/accounting/journal-configuration?module=accounting' },
  { id: '09-trial-balance', path: '/ultimate/accounting/trial-balance?module=accounting' },
  { id: '10-reconciliation', path: '/ultimate/accounting/reconciliation?module=accounting' },
  { id: '11-profit-loss', path: '/ultimate/accounting/profit-loss?module=accounting' },
  { id: '12-balance-sheet', path: '/ultimate/accounting/balance-sheet?module=accounting' },
  { id: '13-cash-flow', path: '/ultimate/accounting/cash-flow?module=accounting' },
  { id: '14-chart-of-accounts', path: '/ultimate/accounting/chart-of-accounts?module=accounting' },
  { id: '15-ledger', path: '/ultimate/accounting/ledger?module=accounting' },
  { id: '16-posting-approvals', path: '/ultimate/accounting/posting-approvals?module=accounting' },
  { id: '17-stock-purchase-payment-desk', path: '/ultimate/modules/finance/stock-purchase-payment-desk.php?module=balances' },
  { id: '18-cash-book', path: '/ultimate/modules/petty-cash/index?module=petty_cash' },
  { id: '19-budgets', path: '/ultimate/modules/finance/budgets/index?module=finance' },
  { id: '20-balances-transactions', path: '/ultimate/modules/balances/transactions.php?module=balances' },
  { id: '21-balances-transfer', path: '/ultimate/modules/balances/transfer.php?module=balances' },
  { id: '22-balances-accounts', path: '/ultimate/modules/balances/accounts.php?module=balances' },
];

const session = bootstrapSession();
const base = 'http://localhost/public_html';
console.log('Session:', session.session_id, '?', outDir);

const browser = await chromium.launch({
  headless: true,
  channel: 'chrome',
});
const context = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  deviceScaleFactor: 1,
});
await context.addCookies([
  {
    name: session.cookie_name || 'PVSSESSID',
    value: session.session_id,
    domain: 'localhost',
    path: session.path || '/public_html/',
    httpOnly: true,
    sameSite: 'Lax',
  },
]);

const page = await context.newPage();
const index = [];

for (const item of pages) {
  const url = base + item.path;
  const file = `${item.id}.png`;
  const dest = path.join(outDir, file);
  process.stdout.write(`Capturing ${item.id}  `);
  try {
    const res = await page.goto(url, { waitUntil: 'networkidle', timeout: 90000 }).catch(() =>
      page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 })
    );
    // Wait out React desks / spinners
    await page.waitForTimeout(4500);
    try {
      await page.waitForFunction(
        () => {
          const t = (document.body && document.body.innerText) || '';
          return !/Loading (expenses|purchases|revenue|balances)/i.test(t);
        },
        { timeout: 15000 }
      );
    } catch {
      /* keep going with whatever rendered */
    }
    await page.waitForTimeout(800);
    const finalUrl = page.url();
    const title = await page.title();
    const status = res && typeof res.status === 'function' ? res.status() : 0;
    const loggedOut = /login/i.test(finalUrl) || /sign.?in/i.test(title);
    await page.screenshot({ path: dest, fullPage: true });
    console.log(loggedOut ? `LOGIN REDIRECT (${status})` : `OK (${status})`);
    index.push({
      id: item.id,
      file,
      url: item.path,
      finalUrl,
      status,
      title,
      loggedOut,
    });
  } catch (err) {
    console.log('FAIL', err.message);
    index.push({ id: item.id, file, url: item.path, error: String(err.message || err) });
  }
}

await browser.close();

const readme = [
  '# Accounting page screenshots',
  '',
  `Captured: ${new Date().toISOString()}`,
  `Base: ${base}`,
  `Company: ultimate (admin session)`,
  '',
  '| # | Page | File |',
  '|---|------|------|',
  ...index.map((row, i) => {
    const note = row.loggedOut ? ' *(login redirect)*' : row.error ? ` *(error)*` : '';
    return `| ${i + 1} | \`${row.url}\`${note} | [${row.file}](./${row.file}) |`;
  }),
  '',
].join('\n');

fs.writeFileSync(path.join(outDir, 'README.md'), readme, 'utf8');
fs.writeFileSync(path.join(outDir, 'index.json'), JSON.stringify(index, null, 2), 'utf8');
console.log(`\nDone. ${index.length} shots in ${outDir}`);
