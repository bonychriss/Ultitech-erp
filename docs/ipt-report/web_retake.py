import asyncio
from playwright.async_api import async_playwright

KILL = '''()=>{const pats=['AI Agent','Shop stats unavailable','We use cookie'];document.querySelectorAll('body *').forEach(e=>{const cs=getComputedStyle(e);if(cs.position==='fixed'&&pats.some(p=>(e.innerText||'').includes(p)&&e.innerText.length<400)){e.remove();}});}'''


async def cookies(pg):
    for t in ['Accept All Cookies', 'Got it', 'Ok. I Understood']:
        try:
            el = pg.get_by_role('button', name=t)
            if await el.count():
                await el.first.click(timeout=1500)
                await pg.wait_for_timeout(500)
        except Exception:
            pass
    await pg.evaluate(KILL)
    await pg.wait_for_timeout(300)

OUT = 'docs/ipt-report/shots/'
SITE = 'https://ultimate.co.tz/'

SCROLL = '''async () => {
  const h = () => document.documentElement.scrollHeight;
  for (let y = 0; y < h(); y += 400) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 350)); }
  document.querySelectorAll('img[loading="lazy"]').forEach(i => i.loading = 'eager');
  document.querySelectorAll('img[data-src]').forEach(i => { if (!i.src || i.src.includes('placeholder')) i.src = i.dataset.src; });
  window.scrollTo(0, 0);
}'''
IMGS_DONE = '''() => [...document.images].filter(i => i.getBoundingClientRect().width > 30).every(i => i.complete && i.naturalWidth > 0)'''


async def load_all(pg):
    await pg.evaluate(SCROLL)
    for _ in range(20):
        if await pg.evaluate(IMGS_DONE):
            break
        await pg.wait_for_timeout(1000)
    await pg.wait_for_timeout(1500)


async def goto(pg, url):
    try:
        await pg.goto(url, wait_until='networkidle', timeout=60000)
    except Exception as e:
        print('timeout', url, e.__class__.__name__)
    await pg.wait_for_timeout(2000)
    await cookies(pg)


async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        ctx = await b.new_context(viewport={'width': 1440, 'height': 900})
        pg = await ctx.new_page()

        await goto(pg, SITE)
        await load_all(pg)
        await cookies(pg)
        await pg.screenshot(path=OUT + 'web_home_full.png', full_page=True)
        await pg.screenshot(path=OUT + 'web_home.png')
        links = await pg.eval_on_selector_all('a', 'els=>[...new Set(els.map(e=>e.href))]')
        prods = [l for l in links if '/product' in l][:4]
        print('products', prods)

        for name, path in [('web_categories', 'categories'), ('web_brands', 'brands')]:
            await goto(pg, SITE + path)
            await load_all(pg)
            await pg.screenshot(path=OUT + name + '.png')

        added = 0
        for i, l in enumerate(prods):
            await goto(pg, l)
            await load_all(pg)
            if i == 0:
                await pg.screenshot(path=OUT + 'web_product.png')
            btn = pg.get_by_role('button', name='Add to Enquiry')
            if not await btn.count():
                btn = pg.get_by_text('Add to Enquiry')
            if await btn.count():
                await btn.first.click(timeout=3000)
                await pg.wait_for_timeout(1200)
                added += 1
        print('added to enquiry', added, await pg.evaluate('localStorage.getItem("ultitechQuoteList")'))

        await goto(pg, SITE + 'quotation')
        await load_all(pg)
        await pg.screenshot(path=OUT + 'web_quotation.png')

        mob = await b.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True)
        mp = await mob.new_page()
        await goto(mp, SITE)
        await load_all(mp)
        await cookies(mp)
        await mp.screenshot(path=OUT + 'web_mobile.png')
        await b.close()

if __name__ == '__main__':
    asyncio.run(main())
