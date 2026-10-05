# -*- coding: utf-8 -*-
"""Rebuild letterhead PDF without Table of Contents page."""
from __future__ import annotations

from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
import pymupdf

KING = Path(r'c:\xampp\htdocs\Ultitech-erp\king')
PREVIEW = KING / 'Employment_Contract_PREVIEW.pdf'
OUT = KING / 'Employment_Contract_LETTERHEAD.pdf'
ASSETS = KING / '_assets'

GOLD = (246 / 255, 195 / 255, 18 / 255)
BLACK = (0.0, 0.0, 0.0)
WHITE = (1, 1, 1)
LAVENDER = (0.929, 0.890, 0.961)
MINT = (0.890, 0.953, 0.918)
GREEN_ACCENT = (0.184, 0.620, 0.408)
SECTION_COLOR = 4005468
TOC_GREEN = 2059848


def fill_close(a, b, tol: float = 0.05) -> bool:
    return all(abs(x - y) <= tol for x, y in zip(a[:3], b[:3]))


def cover(page: pymupdf.Page, rect: pymupdf.Rect, pad: float = 1.0) -> None:
    r = pymupdf.Rect(rect.x0 - pad, rect.y0 - pad, rect.x1 + pad, rect.y1 + pad)
    page.draw_rect(r, color=None, fill=WHITE, overlay=True)


def fix_page_styles(page: pymupdf.Page) -> None:
    for dr in page.get_drawings():
        fill = dr.get('fill')
        rect = dr.get('rect')
        if not fill or not rect:
            continue
        r = pymupdf.Rect(rect)
        if fill_close(fill, LAVENDER) or fill_close(fill, MINT):
            cover(page, r, pad=1.0)
        elif fill_close(fill, GREEN_ACCENT, 0.08) and r.width < 12 and r.height < 30:
            cover(page, r, pad=1.0)

    black_words = []
    section_spans = []
    for block in page.get_text('dict').get('blocks', []):
        if block.get('type') != 0:
            continue
        for line in block.get('lines', []):
            for span in line.get('spans', []):
                text = span.get('text') or ''
                stripped = text.strip()
                if not stripped:
                    continue
                color = span.get('color')
                size = span.get('size', 0)
                if color == TOC_GREEN and stripped.upper() in ('WHEREAS', 'AND'):
                    black_words.append(span)
                elif color == SECTION_COLOR and size >= 12:
                    section_spans.append(span)

    for span in black_words:
        bbox = pymupdf.Rect(span['bbox'])
        cover(page, bbox, pad=1.5)
        page.insert_text(
            pymupdf.Point(bbox.x0, bbox.y1 - 2.5),
            span['text'],
            fontname='hebo',
            fontsize=span['size'],
            color=BLACK,
            overlay=True,
        )

    for span in section_spans:
        bbox = pymupdf.Rect(span['bbox'])
        cover(page, pymupdf.Rect(48.0, bbox.y0 - 2, 545.0, bbox.y1 + 2), pad=0.5)
        page.insert_text(
            pymupdf.Point(bbox.x0, bbox.y1 - 3),
            span['text'],
            fontname='hebo',
            fontsize=span['size'],
            color=GOLD,
            overlay=True,
        )


def edit_cover_image(src_pdf: Path, dest_png: Path) -> Path:
    """Remove cover blue bar + phone; keep website and address on cream."""
    doc = pymupdf.open(src_pdf)
    page = doc[0]
    xref = page.get_image_info(xrefs=True)[0]['xref']
    pix = pymupdf.Pixmap(doc, xref)
    if pix.n >= 5:
        pix = pymupdf.Pixmap(pymupdf.csRGB, pix)
    raw = ASSETS / 'cover_hq.png'
    pix.save(str(raw))
    doc.close()

    im = Image.open(raw).convert('RGB')
    w, h = im.size
    bar_top = 2147
    cream = (222, 212, 177)
    navy = (28, 45, 80)

    sample = im.crop((0, bar_top - 40, w, bar_top - 5))
    mid = sample.resize((w, h - bar_top), Image.Resampling.BILINEAR)
    im.paste(mid, (0, bar_top))
    overlay = Image.new('RGBA', (w, h - bar_top), (*cream, 180))
    base = im.crop((0, bar_top, w, h)).convert('RGBA')
    im.paste(Image.alpha_composite(base, overlay).convert('RGB'), (0, bar_top))

    px = im.load()

    def is_blue(p):
        r, g, b = p
        return b > 70 and b > r + 25 and b > g + 15 and r < 100

    for y in range(bar_top - 5, h):
        for x in range(w):
            if is_blue(px[x, y]):
                px[x, y] = cream

    orig = Image.open(raw).convert('RGB')
    obar = orig.crop((0, bar_top, w, h))
    opx = obar.load()
    bw, bh = obar.size
    visited = set()
    icons = []

    def is_white(p):
        return p[0] > 200 and p[1] > 200 and p[2] > 200

    for y in range(bh):
        for x in range(bw):
            if (x, y) in visited or not is_white(opx[x, y]):
                continue
            stack = [(x, y)]
            visited.add((x, y))
            minx = maxx = x
            miny = maxy = y
            count = 0
            while stack:
                cx, cy = stack.pop()
                count += 1
                minx = min(minx, cx)
                maxx = max(maxx, cx)
                miny = min(miny, cy)
                maxy = max(maxy, cy)
                for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1)):
                    nx, ny = cx + dx, cy + dy
                    if 0 <= nx < bw and 0 <= ny < bh and (nx, ny) not in visited and is_white(opx[nx, ny]):
                        visited.add((nx, ny))
                        stack.append((nx, ny))
            iw, ih = maxx - minx + 1, maxy - miny + 1
            if 20 <= iw <= 80 and 20 <= ih <= 80 and count > 80:
                icons.append((minx, miny, maxx + 1, maxy + 1))
    icons.sort(key=lambda b: b[0])

    def icon_to_navy(box):
        tile = obar.crop(box).convert('RGBA')
        data = []
        for r, g, b, a in tile.getdata():
            if r > 180 and g > 180 and b > 180:
                data.append((*navy, 255))
            else:
                data.append((0, 0, 0, 0))
        tile.putdata(data)
        return tile

    ICON = 42
    globe = pin = None
    if len(icons) >= 3:
        globe = icon_to_navy(icons[1]).resize((ICON, ICON), Image.Resampling.LANCZOS)
        pin = icon_to_navy(icons[2]).resize((ICON, ICON), Image.Resampling.LANCZOS)

    draw = ImageDraw.Draw(im)

    def load_font(size, bold=False):
        names = ['arialbd.ttf', 'segoeuib.ttf'] if bold else ['arial.ttf', 'segoeui.ttf']
        for n in names:
            try:
                return ImageFont.truetype(rf'C:\Windows\Fonts\{n}', size)
            except Exception:
                pass
        return ImageFont.load_default()

    font_main = load_font(36, True)
    font_addr = load_font(28, False)
    web = 'www.ultimate.co.tz'
    addr1 = 'House No.03, Manyara street, Mikocheni B.'
    addr2 = 'P.O. Box 78004, Dar es salaam'

    def text_size(text, font):
        b = draw.textbbox((0, 0), text, font=font)
        return b[2] - b[0], b[3] - b[1]

    ww, wh = text_size(web, font_main)
    a1w, a1h = text_size(addr1, font_addr)
    a2w, a2h = text_size(addr2, font_addr)
    addr_block_w = max(a1w, a2w)
    addr_block_h = a1h + 6 + a2h
    pad_icon = 14
    gap_sections = 80
    web_block_w = (ICON + pad_icon if globe else 0) + ww
    addr_total_w = (ICON + pad_icon if pin else 0) + addr_block_w
    total_w = web_block_w + gap_sections + addr_total_w
    start_x = (w - total_w) // 2
    band_h = h - bar_top
    content_h = max(ICON, wh, addr_block_h)
    top_y = bar_top + (band_h - content_h) // 2

    x = start_x
    if globe:
        im.paste(globe, (x, top_y + (content_h - ICON) // 2), globe)
        x += ICON + pad_icon
    draw.text((x, top_y + (content_h - wh) // 2 - 1), web, fill=navy, font=font_main)
    x = start_x + web_block_w + gap_sections
    if pin:
        im.paste(pin, (x, top_y + (content_h - ICON) // 2), pin)
        ax = x + ICON + pad_icon
    else:
        ax = x
    ay = top_y + (content_h - addr_block_h) // 2
    draw.text((ax, ay), addr1, fill=navy, font=font_addr)
    draw.text((ax, ay + a1h + 6), addr2, fill=navy, font=font_addr)

    dest_png.parent.mkdir(exist_ok=True)
    im.save(dest_png, optimize=True)
    return dest_png


def main() -> None:
    cover_png = edit_cover_image(PREVIEW, ASSETS / 'cover_edited.png')

    src = pymupdf.open(PREVIEW)
    # Drop Table of Contents page (index 1)
    toc_idx = None
    for i in range(src.page_count):
        if 'TABLE OF CONTENTS' in src[i].get_text().upper():
            toc_idx = i
            break
    if toc_idx is not None:
        src.delete_page(toc_idx)
        print(f'Removed TOC page (was index {toc_idx})')

    for i in range(1, src.page_count):
        fix_page_styles(src[i])

    header = ASSETS / 'letter_header.png'
    hw, hh = Image.open(header).size
    sample = src[1] if src.page_count > 1 else src[0]
    page_w = float(sample.rect.width)
    page_h = float(sample.rect.height)

    content_top = 70.0
    for b in sample.get_text('blocks'):
        t = (b[4] or '').upper()
        if 'EMPLOYMENT CONTRACT' in t:
            content_top = float(b[1])
            break

    old_footer_top = 800.0
    for info in sample.get_image_info(xrefs=True):
        bbox = pymupdf.Rect(info['bbox'])
        if bbox.y1 > page_h - 5 and bbox.height < 80:
            old_footer_top = float(bbox.y0)

    header_h = min(page_w * (hh / hw), max(40.0, content_top - 8.0))
    header_cover = max(header_h, 50.0)
    footer_cover = page_h - old_footer_top + 2
    total = src.page_count

    for i in range(1, total):
        page = src[i]
        w, h = page.rect.width, page.rect.height
        for b in page.get_text('blocks'):
            text = (b[4] or '').strip().replace('\n', ' ')
            if text.lower().startswith('page ') and ' of ' in text.lower():
                page.add_redact_annot(
                    pymupdf.Rect(b[0] - 4, b[1] - 3, b[2] + 4, b[3] + 3),
                    fill=(1, 1, 1),
                )
        page.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_NONE)
        page.draw_rect(pymupdf.Rect(0, 0, w, header_cover), color=None, fill=WHITE, overlay=True)
        page.draw_rect(pymupdf.Rect(0, h - footer_cover, w, h), color=None, fill=WHITE, overlay=True)
        page.insert_image(
            pymupdf.Rect(0, 0, w, header_h),
            filename=str(header),
            keep_proportion=False,
            overlay=True,
        )
        label = f'Page {i + 1} of {total}'
        baseline = h - 28
        tw = pymupdf.get_text_length(label, fontname='helv', fontsize=10)
        page.draw_rect(pymupdf.Rect(0, baseline - 14, w, h), color=None, fill=WHITE, overlay=True)
        page.insert_text(
            pymupdf.Point((w - tw) / 2, baseline),
            label,
            fontname='helv',
            fontsize=10,
            color=(0.12, 0.12, 0.14),
            overlay=True,
        )

    # Assemble final: edited cover + content pages
    out = pymupdf.open()
    rect = src[0].rect
    out.new_page(width=rect.width, height=rect.height)
    out[0].insert_image(rect, filename=str(cover_png), keep_proportion=False)
    if src.page_count > 1:
        out.insert_pdf(src, from_page=1, to_page=src.page_count - 1)

    target = OUT
    if target.exists():
        try:
            target.unlink()
        except PermissionError:
            target = KING / 'Employment_Contract_LETTERHEAD_v2.pdf'
    out.save(target, garbage=4, deflate=True)
    print('Wrote', target, 'pages=', out.page_count)
    out.close()
    src.close()


if __name__ == '__main__':
    main()
