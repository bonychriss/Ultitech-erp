/**
 * Capture live UltiTech ERP pages for the marketing homepage.
 * Replaces real tenant records with random demo details + product images,
 * and hides company logos before each screenshot.
 *
 * Usage (from home-ui/tools):
 *   node capture-screens.mjs
 */

import { chromium } from 'playwright'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const OUT_DIR = path.resolve(__dirname, '../frontend/src/assets/screens')
const BASE = 'http://localhost/public_html'
const TENANT = `${BASE}/ultimate`

const PAGES = [
  {
    key: 'finance-dashboard',
    url: `${TENANT}/accounting/?module=accounting`,
    fallback: `${BASE}/modules/accounting/index.php?module=accounting`,
    wait: 2800,
  },
  {
    key: 'sales-invoices',
    url: `${TENANT}/sales/?module=sales`,
    fallback: `${BASE}/modules/sales/dashboard/index.php?module=sales`,
    wait: 2800,
  },
  {
    key: 'sales-payments',
    url: `${TENANT}/erp/outstanding-invoices/index?module=outstanding`,
    fallback: `${BASE}/erp/outstanding-invoices/index.php?module=outstanding`,
    wait: 2800,
  },
  {
    key: 'reports-dashboard',
    url: `${TENANT}/modules/analytics/index?module=analytics`,
    fallback: `${BASE}/modules/analytics/index.php?module=analytics`,
    wait: 2800,
  },
  {
    key: 'finance-entries',
    url: `${TENANT}/modules/finance/budgets/index?module=finance`,
    fallback: `${BASE}/modules/finance/budgets/index.php?module=finance`,
    wait: 2800,
  },
  {
    key: 'ops-credit-notes',
    url: `${TENANT}/admin/dashboard?module=voucher`,
    fallback: `${BASE}/admin/dashboard.php?module=voucher`,
    wait: 2800,
  },
  {
    key: 'inventory-stock',
    url: `${TENANT}/stock/?module=stock`,
    fallback: `${BASE}/stock/dashboard.php`,
    wait: 2800,
  },
  {
    key: 'hr-payroll',
    url: `${TENANT}/modules/payroll/index?module=payroll`,
    fallback: `${BASE}/modules/payroll/index.php?module=payroll`,
    wait: 2800,
  },
  {
    key: 'logistics-delivery',
    url: `${TENANT}/deliveries/index?module=deliveries&company_slug=ultimate`,
    fallback: `${BASE}/deliveries/index.php?module=deliveries`,
    wait: 2800,
  },
]

const HIDE_LOGOS_CSS = `
  img[src*="logo" i],
  img[alt*="logo" i],
  img[src*="company" i],
  .company-logo,
  .company_logo,
  .brand-logo,
  .sidebar-logo,
  .sidebar__logo,
  .nav-logo,
  .app-logo,
  .header-logo,
  .topbar-logo,
  [class*="company-logo" i],
  [class*="CompanyLogo"],
  [class*="brand-logo" i],
  [data-logo],
  a.logo img,
  .logo img,
  aside img,
  .sidebar img,
  #native-sidebar img,
  .login-logo,
  .tenant-logo,
  .brand img,
  .brand-image {
    visibility: hidden !important;
    opacity: 0 !important;
  }
`

async function gotoSafe(page, primary, fallback) {
  await page.goto(primary, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => null)
  let url = page.url()
  if (/login/i.test(url) && fallback) {
    await page.goto(fallback, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => null)
    url = page.url()
  }
  return url
}

async function anonymizePage(page) {
  await page.addStyleTag({ content: HIDE_LOGOS_CSS }).catch(() => {})
  await page.evaluate(() => {
    const customers = [
      'Northwind Traders',
      'Blue Harbor Supplies',
      'Cedar Peak Retail',
      'Orbit Industrial Co.',
      'Summit Pack Solutions',
      'Atlas Field Services',
      'Brightline Commerce',
      'Harbor & Co. Wholesale',
      'Pinecrest Distributors',
      'Lakeview Merchants',
    ]
    const people = [
      'Alex Morgan',
      'Jordan Lee',
      'Sam Rivera',
      'Taylor Brooks',
      'Casey Quinn',
      'Riley Paterson',
      'Jamie Chen',
      'Avery Collins',
      'Morgan Blake',
      'Drew Sullivan',
    ]
    const products = [
      'Safety Gloves Pack',
      'Industrial Hard Hat',
      'LED Work Light Kit',
      'Cable Tie Assortment',
      'Premium Work Boots',
      'Reflective Safety Vest',
      'Power Drill Set',
      'Warehouse Hand Truck',
      'Insulated Spanner Kit',
      'Protective Goggles',
      'Steel Toe Shoes',
      'Tool Belt Pro',
    ]

    const uiKeep = new Set([
      'all modules', 'ai assistant', 'dashboard', 'my sales', 'pricelist', 'customers',
      'customer statement', 'quotation', 'sales orders', 'invoices', 'sales settings',
      'set targets', 'reassign sales', 'personalization', 'notifications', 'main', 'quick',
      'account', 'profile settings', 'appearance', 'dark / light', 'logout', 'home',
      'balances', 'revenues', 'expenses', 'petty cash', 'journal', 'reconciliation',
      'catalogue', 'products', 'suppliers', 'shipments', 'purchases', 'replenishment',
      'stock control', 'store management', 'warehouses', 'stock transfers', 'reports',
      'soon', 'receivables', 'payables', 'back', 'view all', 'add product', 'new purchase',
      'monthly sales', 'pending orders', 'overdue invoices', 'total monthly sales',
      'revenue growth', 'recent activity', 'most outgoing products', 'leaderboard',
      'stock health', 'needs restock', 'recent purchases', 'healthy stock', 'products',
      'in stock', 'low', 'out', 'day', 'weekly', 'monthly', 'admin', 'demo admin',
      'sales dashboard', 'stock', 'accounting', 'outstanding invoices', 'payables (expenses)',
      'target reached!', 'action required', 'above reorder level', 'ready to order from',
      'all sales this month', 'from last month', 'new today',
    ])

    const banned = [
      /ultimate\s+general\s+trading/gi,
      /roadmaster/gi,
      /oldfort(?:\s+limited)?/gi,
      /mapinga(?:\s+premium\s+foods(?:\s+limited)?)?/gi,
      /hesu\s+investment/gi,
      /shenzhen(?:\s+lumifu(?:\s+electronics(?:\s+limited)?)?)?/gi,
      /lumifu/gi,
      /zanzibar/gi,
      /tanzania/gi,
      /khadija(?:\s+nuru)?/gi,
      /mariane(?:\s+shedafa(?:\s+martini)?)?/gi,
      /diana\s+dotto/gi,
      /safety equipments?\s*&\s*ppe suppliers?/gi,
    ]

    const money = () => {
      const n = Math.floor(12000 + Math.random() * 4800000)
      return `TZS ${n.toLocaleString('en-US')}`
    }
    const pick = (arr, i) => arr[i % arr.length]
    let cIdx = 0
    let pIdx = 0
    let prodIdx = 0

    const inChrome = (el) => !!(
      el.closest('aside') ||
      el.closest('#native-sidebar') ||
      el.closest('.sidebar') ||
      el.closest('nav') ||
      el.closest('header .nav') ||
      el.closest('[class*="side-nav"]')
    )

    const looksLikePerson = (t) =>
      /^[A-Z][A-Za-z.'-]+(?:\s+[A-Z][A-Za-z.'-]+){1,3}$/.test(t) &&
      t.length > 5 &&
      t.length < 48

    const looksLikeCompany = (t) =>
      /(limited|ltd|llc|inc\.?|company|co\.|traders|investment|electronics|supplies|foods)/i.test(t) ||
      (t === t.toUpperCase() && t.length > 10 && /[A-Z\s&-]{10,}/.test(t) && !/TZS|SO-|INV-|PAY-|OUT|LOW|SOON|KPI/.test(t))

    const looksLikeProduct = (t) =>
      /(spanner|gloves|joggers|helmet|cable|boot|vest|drill|goggle|hairnet|insulated|electrical|open end|box spanner|ac insulated)/i.test(t) ||
      (t === t.toUpperCase() && t.length > 18 && /[A-Z0-9\s\-&/]{18,}/.test(t) && !/TZS|DASHBOARD|SETTINGS/.test(t))

    const walk = (node) => {
      if (node.nodeType === Node.TEXT_NODE) {
        const parent = node.parentElement
        if (!parent || inChrome(parent)) return
        if (parent.closest('button, label, th, .kicker, h1, h2')) {
          // still scrub banned company strings in headings, but don't invent names for UI titles
        }

        let text = node.nodeValue || ''
        const trimmed = text.trim()
        if (!trimmed) return
        if (uiKeep.has(trimmed.toLowerCase())) return

        let next = text
        banned.forEach((re) => {
          next = next.replace(re, '')
        })

        if (/TZS\s*[\d,]+(?:\.\d+)?/i.test(next)) {
          next = next.replace(/TZS\s*[\d,]+(?:\.\d+)?/gi, () => money())
        }
        if (/\bSO-\d{4}-\d+\b/i.test(next)) {
          next = next.replace(/\bSO-\d{4}-\d+\b/gi, () => `SO-2026-${String(1000 + (prodIdx++ % 800)).padStart(4, '0')}`)
        }
        if (/\bINV-\d{4}-\d+\b/i.test(next)) {
          next = next.replace(/\bINV-\d{4}-\d+\b/gi, () => `INV-2026-${String(2000 + (cIdx++ % 700)).padStart(4, '0')}`)
        }

        const bare = next.trim()
        const isUiTitle = !!(parent.closest('h1, h2, h3, button, label, th, nav, aside, .sidebar'))
        if (!isUiTitle) {
          if (looksLikeCompany(bare)) {
            next = next.replace(bare, pick(customers, cIdx++))
          } else if (looksLikeProduct(bare)) {
            next = next.replace(bare, pick(products, prodIdx++))
          } else if (looksLikePerson(bare) && !/^system administrator$/i.test(bare) && !/^admin$/i.test(bare)) {
            next = next.replace(bare, pick(people, pIdx++))
          } else if (/^\d+\s+units?$/i.test(bare)) {
            next = `${2 + (prodIdx % 18)} units`
          }
        }

        if (/zanzibar|dar es salaam|tanzania/i.test(next)) {
          next = next.replace(/zanzibar|dar es salaam|tanzania/gi, 'Demo City')
        }

        if (next !== text) node.nodeValue = next
        return
      }

      if (node.nodeType === Node.ELEMENT_NODE) {
        const tag = node.tagName
        if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') return
        node.childNodes.forEach(walk)
      }
    }
    walk(document.body)

    document.querySelectorAll('*').forEach((el) => {
      if (el.children.length) return
      const t = (el.textContent || '').trim()
      if (/^system administrator$/i.test(t)) el.textContent = 'Demo Admin'
    })

    const productSeeds = [
      'Safety Gloves', 'Hard Hat', 'Work Boots', 'LED Light', 'Cable Ties', 'Safety Vest',
      'Drill Set', 'Goggles', 'Tool Belt', 'Spanner Kit', 'Hand Truck', 'Steel Toe',
    ]
    const colors = ['#2563eb', '#ea580c', '#0f766e', '#7c3aed', '#0891b2', '#ca8a04', '#dc2626', '#4f46e5', '#15803d', '#9333ea', '#0369a1', '#b45309']
    const productDataUrl = (label, i) => {
      const c = colors[i % colors.length]
      const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240" viewBox="0 0 320 240">
        <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="${c}"/><stop offset="1" stop-color="#0f172a"/></linearGradient></defs>
        <rect width="320" height="240" rx="18" fill="url(#g)"/>
        <circle cx="250" cy="40" r="48" fill="rgba(255,255,255,0.12)"/>
        <rect x="36" y="54" width="120" height="90" rx="14" fill="rgba(255,255,255,0.18)"/>
        <text x="28" y="190" fill="#fff" font-family="Segoe UI, Arial" font-size="22" font-weight="700">${label}</text>
        <text x="28" y="214" fill="rgba(255,255,255,0.8)" font-family="Segoe UI, Arial" font-size="13">Demo product</text>
      </svg>`
      return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
    }

    let imgIdx = 0
    document.querySelectorAll('img').forEach((img) => {
      const s = `${img.getAttribute('src') || ''} ${img.getAttribute('alt') || ''}`.toLowerCase()
      const chrome = inChrome(img)
      if (chrome || s.includes('logo') || s.includes('icon') || s.includes('avatar') || (img.width > 0 && img.width < 28)) {
        if (s.includes('logo') || chrome) {
          img.style.visibility = 'hidden'
          img.style.opacity = '0'
        }
        return
      }
      const label = productSeeds[imgIdx % productSeeds.length]
      img.src = productDataUrl(label, imgIdx)
      img.alt = label
      imgIdx += 1
      img.style.visibility = 'visible'
      img.style.opacity = '1'
      img.style.objectFit = 'cover'
    })

    document.querySelectorAll('[class*="product"], [class*="outgoing"], [class*="purchase"], [class*="gallery"], [class*="stack"]').forEach((el, i) => {
      const r = el.getBoundingClientRect()
      if (r.width < 80 || r.height < 60) return
      if (!el.querySelector('img')) {
        const label = productSeeds[i % productSeeds.length]
        el.insertAdjacentHTML(
          'afterbegin',
          `<img src="${productDataUrl(label, i)}" alt="${label}" style="width:88px;height:88px;object-fit:cover;border-radius:12px;margin:4px;" />`,
        )
      }
    })
  }).catch((err) => {
    console.warn('anonymize failed', err)
  })

  await page.waitForTimeout(600)
}

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true })

  const browser = await chromium.launch({ headless: true })
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
  })
  const page = await context.newPage()

  const boot = await page.goto(`${BASE}/capture-bootstrap.php`, {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  })
  const bootText = await page.locator('body').innerText()
  console.log('bootstrap:', boot?.status(), bootText.slice(0, 200))
  if (!boot || boot.status() !== 200 || !bootText.includes('"ok":true')) {
    throw new Error('Failed to bootstrap capture session')
  }

  for (const item of PAGES) {
    console.log('capturing', item.key, '...')
    const finalUrl = await gotoSafe(page, item.url, item.fallback)
    console.log('  at', finalUrl)
    if (/login/i.test(finalUrl)) {
      console.warn('  skipped (still on login)')
      continue
    }
    await page.waitForTimeout(item.wait)
    await anonymizePage(page)
    await page.waitForTimeout(500)

    const outPath = path.join(OUT_DIR, `${item.key}.jpg`)
    await page.screenshot({
      path: outPath,
      type: 'jpeg',
      quality: 82,
      fullPage: false,
    })
    console.log('  saved', outPath)
  }

  await browser.close()
  console.log('done')
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
