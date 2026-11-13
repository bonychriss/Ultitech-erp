import asyncio
from playwright.async_api import async_playwright
B='http://localhost/public_html/ultimate'
ERP=[
 ('erp_login','http://localhost/public_html/ultimate/login.php',False),
 ('erp_landing','http://localhost/public_html/',False),
 ('erp_select', B+'/select-module.php',True),
 ('erp_voucher', B+'/admin/dashboard.php?module=voucher',True),
 ('erp_sales', B+'/sales',True),
 ('erp_stock', B+'/stock',True),
 ('erp_accounting', B+'/accounting',True),
 ('erp_payroll', B+'/modules/payroll/index?module=payroll',True),
 ('erp_attendance', B+'/attendance',True),
 ('erp_crm', B+'/modules/crm/my-clients/index?module=crm',True),
 ('erp_crm_market', B+'/modules/crm/market/index.php?module=crm',True),
 ('erp_letter', B+'/modules/letter/index?module=letter',True),
 ('erp_letter_compose', B+'/modules/letter/compose.php?module=letter&id=d6bde4fe-8297-4e82-afdd-46147c1801b3',True),
 ('erp_inbox', B+'/modules/letter/inbox?module=letter',True),
 ('erp_notifications', B+'/notifications.php',True),
 ('erp_website', B+'/website/dashboard',True),
 ('erp_analytics', B+'/modules/analytics/index.php?module=analytics',True),
 ('erp_cashbook', B+'/modules/petty-cash/index?module=petty_cash',True),
]
WEB=[
 ('web_home','https://ultimate.co.tz/'),
 ('web_categories','https://ultimate.co.tz/categories'),
 ('web_brands','https://ultimate.co.tz/brands'),
 ('web_quotation','https://ultimate.co.tz/quotation'),
 ('web_contact','https://ultimate.co.tz/contact-us'),
 ('web_track','https://ultimate.co.tz/track-your-order'),
]
KILL='''()=>{const pats=['AI Agent','Shop stats unavailable','We use cookie','You have 7 new'];document.querySelectorAll('body *').forEach(e=>{const cs=getComputedStyle(e);if((cs.position==='fixed'||cs.position==='sticky'&&false)&&pats.some(p=>(e.innerText||'').startsWith(p)||(e.innerText||'').includes(p)&&e.innerText.length<400)){e.remove();}});}'''
async def cookies(pg):
    for t in ['Accept All Cookies','Got it','Ok. I Understood']:
        try:
            el = pg.get_by_role('button', name=t)
            if await el.count(): await el.first.click(timeout=1500); await pg.wait_for_timeout(500)
        except Exception: pass
    await pg.evaluate(KILL); await pg.wait_for_timeout(300)
async def shot(ctx, name, url, full=False):
    pg = await ctx.new_page()
    try:
        r = await pg.goto(url, wait_until='networkidle', timeout=60000)
    except Exception as e:
        print('timeout', name, e.__class__.__name__)
    await pg.wait_for_timeout(2500); await cookies(pg)
    print(name, pg.url, await pg.title())
    await pg.screenshot(path=f'docs/ipt-report/shots/{name}.png', full_page=full)
    return pg
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        anon = await b.new_context(viewport={'width':1440,'height':900})
        auth = await b.new_context(viewport={'width':1440,'height':900}, storage_state='docs/ipt-report/state.json')
        for n,u,a in ERP:
            pg = await shot(auth if a else anon, n, u); await pg.close()
        for n,u in WEB:
            pg = await shot(anon, n, u); await pg.close()
        pg = await shot(anon,'web_home_full','https://ultimate.co.tz/',True); 
        links = await pg.eval_on_selector_all('a','els=>els.map(e=>e.href)')
        prod=[l for l in links if '/product' in l][:3]; print('products',prod); await pg.close()
        for i,l in enumerate(prod[:1]):
            pg = await shot(anon,f'web_product',l); await pg.close()
        mob = await b.new_context(viewport={'width':390,'height':844}, device_scale_factor=2, is_mobile=True, has_touch=True)
        pg = await shot(mob,'web_mobile','https://ultimate.co.tz/'); await pg.close()
        await b.close()
asyncio.run(main())

