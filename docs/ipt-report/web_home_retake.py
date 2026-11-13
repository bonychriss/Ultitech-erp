import asyncio
from playwright.async_api import async_playwright
from web_retake import cookies, load_all, goto

async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        pg = await (await b.new_context(viewport={'width': 1440, 'height': 900})).new_page()
        await goto(pg, 'https://ultimate.co.tz/')
        await load_all(pg)
        await cookies(pg)
        await pg.add_style_tag(content='*,*::before,*::after{animation-play-state:paused!important;transition:none!important}')
        await pg.wait_for_timeout(500)
        await pg.screenshot(path='docs/ipt-report/shots/web_home.png')
        await b.close()

asyncio.run(main())
