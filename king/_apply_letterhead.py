# -*- coding: utf-8 -*-
"""Crop letter header/footer to content, then stamp onto contract pages 2+."""
from __future__ import annotations

from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
import pymupdf

KING = Path(r'c:\xampp\htdocs\Ultitech-erp\king')
LETTER_PDF = KING / 'letter-23-09-2026.pdf'
CONTRACT_PDF = KING / 'Employment_Contract_PREVIEW.pdf'
OUT_PDF = KING / 'Employment_Contract_LETTERHEAD.pdf'
ASSETS = KING / '_assets'
PREVIEW = KING / '_preview_pages_letterhead'

NAVY = (38, 45, 53)
YELLOW = (246, 195, 18)
WHITE = (255, 255, 255)
GOLD_PDF = (246 / 255, 195 / 255, 18 / 255)
LAVENDER_FILL = (0.929, 0.890, 0.961)
MINT_FILL = (0.890, 0.953, 0.918)
GREEN_ACCENT = (0.184, 0.620, 0.408)
SECTION_HEADING_COLOR = 4005468
TOC_GREEN = 2059848  # dots / TOC title


def _fill_close(a, b, tol: float = 0.05) -> bool:
    return all(abs(x - y) <= tol for x, y in zip(a[:3], b[:3]))


def _is_toc_leader(text: str) -> bool:
    t = text.strip()
    if not t:
        return False
    core = t.replace(' ', '')
    if not core:
        return False
    dots = sum(1 for c in core if c == '.')
    return dots >= 5 and dots / len(core) >= 0.7


def fix_heading_styles(page: pymupdf.Page) -> None:
    """Drop TOC/section highlights; TOC black (incl. dots); section titles gold."""
    for dr in page.get_drawings():
        fill = dr.get('fill')
        rect = dr.get('rect')
        if not fill or not rect:
            continue
        r = pymupdf.Rect(rect)
        if _fill_close(fill, LAVENDER_FILL) or _fill_close(fill, MINT_FILL):
            page.draw_rect(
                pymupdf.Rect(r.x0 - 1, r.y0 - 1, r.x1 + 1, r.y1 + 1),
                color=None,
                fill=(1, 1, 1),
                overlay=True,
            )
        elif _fill_close(fill, GREEN_ACCENT, 0.08) and r.width < 12 and r.height < 30:
            page.draw_rect(
                pymupdf.Rect(r.x0 - 1, r.y0 - 1, r.x1 + 1, r.y1 + 1),
                color=None,
                fill=(1, 1, 1),
                overlay=True,
            )

    for block in page.get_text('dict').get('blocks', []):
        if block.get('type') != 0:
            continue
        for line in block.get('lines', []):
            for span in line.get('spans', []):
                text = span.get('text') or ''
                stripped = text.strip()
                if not stripped:
                    continue
                bbox = pymupdf.Rect(span['bbox'])
                color = span.get('color')
                size = span.get('size', 0)

                if stripped.upper() == 'TABLE OF CONTENTS':
                    page.draw_rect(
                        pymupdf.Rect(bbox.x0 - 2, bbox.y0 - 2, bbox.x1 + 2, bbox.y1 + 2),
                        color=None,
                        fill=(1, 1, 1),
                        overlay=True,
                    )
                    page.insert_text(
                        pymupdf.Point(bbox.x0, bbox.y1 - 3),
                        stripped,
                        fontname='hebo',
                        fontsize=size,
                        color=(0, 0, 0),
                        overlay=True,
                    )
                elif color == TOC_GREEN and stripped.upper() in ('WHEREAS', 'AND'):
                    page.draw_rect(
                        pymupdf.Rect(bbox.x0 - 1, bbox.y0 - 1, bbox.x1 + 1, bbox.y1 + 1),
                        color=None,
                        fill=(1, 1, 1),
                        overlay=True,
                    )
                    page.insert_text(
                        pymupdf.Point(bbox.x0, bbox.y1 - 2.5),
                        span['text'],
                        fontname='hebo',
                        fontsize=size,
                        color=(0, 0, 0),
                        overlay=True,
                    )
                elif color == TOC_GREEN and _is_toc_leader(text):
                    page.draw_rect(
                        pymupdf.Rect(bbox.x0 - 1, bbox.y0 - 1, bbox.x1 + 1, bbox.y1 + 1),
                        color=None,
                        fill=(1, 1, 1),
                        overlay=True,
                    )
                    page.insert_text(
                        pymupdf.Point(bbox.x0, bbox.y1 - 2.5),
                        text,
                        fontname='helv',
                        fontsize=size,
                        color=(0, 0, 0),
                        overlay=True,
                    )
                elif color == SECTION_HEADING_COLOR and size >= 12:
                    page.draw_rect(
                        pymupdf.Rect(48.0, bbox.y0 - 2, 545.0, bbox.y1 + 2),
                        color=None,
                        fill=(1, 1, 1),
                        overlay=True,
                    )
                    page.insert_text(
                        pymupdf.Point(bbox.x0, bbox.y1 - 3),
                        span['text'],
                        fontname='hebo',
                        fontsize=size,
                        color=GOLD_PDF,
                        overlay=True,
                    )


def extract_raw() -> tuple[Path, Path]:
    ASSETS.mkdir(exist_ok=True)
    doc = pymupdf.open(LETTER_PDF)
    page = doc[0]
    header_raw = ASSETS / 'letter_header_raw.png'
    footer_raw = ASSETS / 'letter_footer_raw.png'
    for info in page.get_image_info(xrefs=True):
        bbox = pymupdf.Rect(info['bbox'])
        xref = info['xref']
        pix = pymupdf.Pixmap(doc, xref)
        if pix.n >= 5:
            pix = pymupdf.Pixmap(pymupdf.csRGB, pix)
        if bbox.y0 < 20 and bbox.height > 40:
            pix.save(str(header_raw))
        elif bbox.y1 > page.rect.height - 20 and bbox.height > 40:
            pix.save(str(footer_raw))
    doc.close()
    return header_raw, footer_raw


def crop_nonwhite(src: Path, dest: Path, pad: int = 4, threshold: int = 248) -> tuple[int, int]:
    im = Image.open(src).convert('RGB')
    w, h = im.size
    px = im.load()
    top, bottom = h, 0
    for y in range(h):
        for x in range(w):
            r, g, b = px[x, y]
            if r < threshold or g < threshold or b < threshold:
                top = min(top, y)
                bottom = max(bottom, y)
    if bottom <= top:
        im.save(dest)
        return w, h
    top = max(0, top - pad)
    bottom = min(h - 1, bottom + pad)
    cropped = im.crop((0, top, w, bottom + 1))
    cropped.save(dest, optimize=True)
    return cropped.size


def find_page_label(page: pymupdf.Page) -> tuple[str | None, pymupdf.Rect | None]:
    for b in page.get_text('blocks'):
        text = (b[4] or '').strip().replace('\n', ' ')
        if text.lower().startswith('page ') and ' of ' in text.lower():
            return text, pymupdf.Rect(b[0], b[1], b[2], b[3])
    return None, None


def _load_font(size: int, bold: bool = False) -> ImageFont.ImageFont:
    names = (
        ('arialbd.ttf', 'segoeuib.ttf', 'calibrib.ttf')
        if bold
        else ('arial.ttf', 'segoeui.ttf', 'calibri.ttf')
    )
    for name in names:
        try:
            return ImageFont.truetype(rf'C:\Windows\Fonts\{name}', size)
        except Exception:
            continue
    return ImageFont.load_default()


def _extract_footer_icons(base_path: Path) -> tuple[Image.Image, Image.Image, Image.Image]:
    """Crop phone / globe / pin icon tiles from the letter footer artwork."""
    base = Image.open(base_path).convert('RGB')
    bw, bh = base.size
    px = base.load()

    def is_navy(p: tuple[int, int, int]) -> bool:
        return abs(p[0] - NAVY[0]) <= 25 and abs(p[1] - NAVY[1]) <= 25 and abs(p[2] - NAVY[2]) <= 25

    def is_yellow(p: tuple[int, int, int]) -> bool:
        return p[0] > 200 and p[1] > 150 and p[2] < 100

    def find_squares(match_fn, min_s: int = 28, max_s: int = 70):
        visited: set[tuple[int, int]] = set()
        boxes: list[tuple[int, int, int, int]] = []
        for y in range(bh):
            for x in range(bw):
                if (x, y) in visited or not match_fn(px[x, y]):
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
                        if (
                            0 <= nx < bw
                            and 0 <= ny < bh
                            and (nx, ny) not in visited
                            and match_fn(px[nx, ny])
                        ):
                            visited.add((nx, ny))
                            stack.append((nx, ny))
                sw, sh = maxx - minx + 1, maxy - miny + 1
                if min_s <= sw <= max_s and min_s <= sh <= max_s and count > 200:
                    boxes.append((minx, miny, maxx + 1, maxy + 1))
        boxes.sort(key=lambda b: b[0])
        out: list[tuple[int, int, int, int]] = []
        for b in boxes:
            if not out or b[0] - out[-1][0] > 40:
                out.append(b)
        return out

    yellow_sq = find_squares(is_yellow)
    navy_sq = [b for b in find_squares(is_navy) if b[0] > 400]
    phone_box = next(b for b in yellow_sq if b[0] < 500)
    globe_box, pin_box = navy_sq[0], navy_sq[1]
    return base.crop(phone_box), base.crop(globe_box), base.crop(pin_box)


def redesign_footer(footer_path: Path, base_path: Path) -> tuple[int, int]:
    """Flat equal-height 3-section footer: phone | website | address."""
    # Preserve source art for icon extraction (raised original is fine as icon source)
    base_save = ASSETS / 'letter_footer_base.png'
    if base_path.exists() and base_path.resolve() != base_save.resolve():
        Image.open(base_path).convert('RGB').save(base_save)

    phone_raw, globe_raw, pin_raw = _extract_footer_icons(base_save if base_save.exists() else base_path)

    W, H = 1588, 128
    SEC = W // 3
    PAD_X = 32
    GAP = 16
    ICON = 46

    phone_icon = phone_raw.resize((ICON, ICON), Image.Resampling.LANCZOS)
    globe_icon = globe_raw.resize((ICON, ICON), Image.Resampling.LANCZOS)
    pin_icon = pin_raw.resize((ICON, ICON), Image.Resampling.LANCZOS)
    phone_icon.save(ASSETS / 'icon_phone.png')
    globe_icon.save(ASSETS / 'icon_globe.png')
    pin_icon.save(ASSETS / 'icon_pin.png')

    img = Image.new('RGB', (W, H), YELLOW)
    draw = ImageDraw.Draw(img)
    draw.rectangle([0, 0, SEC - 1, H - 1], fill=NAVY)
    draw.line([(SEC, 0), (SEC, H - 1)], fill=NAVY, width=1)
    draw.line([(SEC * 2, 0), (SEC * 2, H - 1)], fill=(220, 175, 25), width=1)

    font_phone = _load_font(28, bold=True)
    font_web = _load_font(28, bold=True)
    font_addr = _load_font(19, bold=False)

    def text_size(text: str, font: ImageFont.ImageFont) -> tuple[int, int]:
        b = draw.textbbox((0, 0), text, font=font)
        return b[2] - b[0], b[3] - b[1]

    def place(
        icon: Image.Image,
        lines: list[str],
        font: ImageFont.ImageFont,
        fill: tuple[int, int, int],
        section_x: int,
        bold: bool,
    ) -> None:
        line_gap = 3 if len(lines) > 1 else 0
        f = font
        max_text_w = section_x + SEC - PAD_X - (section_x + PAD_X + ICON + GAP)
        while True:
            widths = [text_size(t, f)[0] for t in lines]
            if max(widths) <= max_text_w or getattr(f, 'size', 16) <= 15:
                break
            f = _load_font(int(getattr(f, 'size', 19)) - 1, bold=bold)
        heights = [text_size(t, f)[1] for t in lines]
        th = sum(heights) + line_gap * (len(lines) - 1)
        content_h = max(ICON, th)
        top = (H - content_h) // 2
        ix = section_x + PAD_X
        iy = top + (content_h - ICON) // 2
        img.paste(icon, (ix, iy))
        tx = ix + ICON + GAP
        y = top + (content_h - th) // 2 - 1
        for line in lines:
            draw.text((tx, y), line, fill=fill, font=f)
            y += text_size(line, f)[1] + line_gap

    place(phone_icon, ['+255 755 282 861'], font_phone, WHITE, 0, True)
    place(globe_icon, ['www.ultimate.co.tz'], font_web, NAVY, SEC, True)
    place(
        pin_icon,
        [
            'House No.03, Manyara Street,',
            'Mikocheni B.',
            'P.O. Box 78004, Dar Es Salaam, TZ',
        ],
        font_addr,
        NAVY,
        SEC * 2,
        False,
    )

    img.save(footer_path, optimize=True)
    return img.size


def build() -> Path:
    header_raw, footer_raw = extract_raw()
    header = ASSETS / 'letter_header.png'
    hw, hh = crop_nonwhite(header_raw, header, pad=6)
    # Footer art is extracted but not stamped - document uses header only.
    crop_nonwhite(footer_raw, ASSETS / 'letter_footer_base.png', pad=4)
    print(f'Cropped header {hw}x{hh} (footer omitted)')

    src = pymupdf.open(CONTRACT_PDF)
    # Remove Table of Contents page entirely
    for i in range(src.page_count):
        if 'TABLE OF CONTENTS' in src[i].get_text().upper():
            src.delete_page(i)
            print(f'Removed Table of Contents (was page {i + 1})')
            break

    out = pymupdf.open()

    sample = src[1] if src.page_count > 1 else src[0]
    content_top = 70.0
    for b in sample.get_text('blocks'):
        if 'EMPLOYMENT CONTRACT' in (b[4] or '').upper():
            content_top = float(b[1])
            break

    _label, label_rect = find_page_label(sample)
    page_w = float(sample.rect.width)
    page_h = float(sample.rect.height)

    # Old contract footer image sits around y=800 to bottom; page label ends ~797.
    old_footer_top = 800.0
    for info in sample.get_image_info(xrefs=True):
        bbox = pymupdf.Rect(info['bbox'])
        if bbox.y1 > page_h - 5 and bbox.height < 80:
            old_footer_top = float(bbox.y0)

    header_nat = page_w * (hh / hw)
    header_max = max(40.0, content_top - 8.0)
    header_h = min(header_nat, header_max)
    header_cover = max(header_h, 50.0)
    footer_cover = page_h - old_footer_top + 2

    print(
        f'Place header={header_h:.1f}pt, footer removed '
        f'(cover bottom={footer_cover:.1f}pt)'
    )

    total = src.page_count
    for i in range(total):
        out.insert_pdf(src, from_page=i, to_page=i)
        page = out[-1]
        if i == 0:
            continue

        w, h = page.rect.width, page.rect.height
        fix_heading_styles(page)

        # Remove original page-number text before stamping letterhead.
        for b in page.get_text('blocks'):
            text = (b[4] or '').strip().replace('\n', ' ')
            if text.lower().startswith('page ') and ' of ' in text.lower():
                page.add_redact_annot(
                    pymupdf.Rect(b[0] - 4, b[1] - 3, b[2] + 4, b[3] + 3),
                    fill=(1, 1, 1),
                )
        page.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_NONE)

        # White-out old header / footer chrome
        page.draw_rect(
            pymupdf.Rect(0, 0, w, header_cover),
            color=None,
            fill=(1, 1, 1),
            overlay=True,
        )
        page.draw_rect(
            pymupdf.Rect(0, h - footer_cover, w, h),
            color=None,
            fill=(1, 1, 1),
            overlay=True,
        )

        page.insert_image(
            pymupdf.Rect(0, 0, w, header_h),
            filename=str(header),
            keep_proportion=False,
            overlay=True,
        )

        # Page number near bottom (no footer bar)
        page_label = f'Page {i + 1} of {total}'
        fontsize = 10
        baseline = h - 28
        tw = pymupdf.get_text_length(page_label, fontname='helv', fontsize=fontsize)
        x = (w - tw) / 2.0
        page.draw_rect(
            pymupdf.Rect(0, baseline - fontsize - 4, w, h),
            color=None,
            fill=(1, 1, 1),
            overlay=True,
        )
        page.insert_text(
            pymupdf.Point(x, baseline),
            page_label,
            fontname='helv',
            fontsize=fontsize,
            color=(0.12, 0.12, 0.14),
            overlay=True,
        )
        print(f'  p{i+1}: "{page_label}" baseline={baseline:.1f}')

    target = OUT_PDF
    if target.exists():
        try:
            target.unlink()
        except PermissionError:
            target = KING / 'Employment_Contract_LETTERHEAD_v2.pdf'
            print('Original PDF locked; writing', target.name)

    out.save(target, garbage=4, deflate=True)
    out.close()
    src.close()
    print('Wrote', target)
    return target


def render_previews(pdf_path: Path) -> None:
    PREVIEW.mkdir(exist_ok=True)
    doc = pymupdf.open(pdf_path)
    for i in sorted({1, 2, 3, doc.page_count - 1}):
        if i >= doc.page_count:
            continue
        pix = doc[i].get_pixmap(matrix=pymupdf.Matrix(1.8, 1.8), alpha=False)
        dest = PREVIEW / f'page_{i + 1:02d}.png'
        pix.save(str(dest))
        print('preview', dest.name)
    doc.close()


if __name__ == '__main__':
    pdf = build()
    render_previews(pdf)
