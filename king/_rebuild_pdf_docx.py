# -*- coding: utf-8 -*-
"""Rebuild clean letterhead PDF + image-faithful Word doc."""
from __future__ import annotations

from pathlib import Path
from PIL import Image
import pymupdf
from docx import Document
from docx.shared import Inches, Pt, Twips
from docx.enum.section import WD_ORIENT
import os

KING = Path(r'c:\xampp\htdocs\Ultitech-erp\king')
PREVIEW = KING / 'Employment_Contract_PREVIEW.pdf'
CANVA = KING / 'Blue Minimalist Project Proposal Cover A4 Document (5).pdf'
OUT_PDF = KING / 'Employment_Contract_LETTERHEAD.pdf'
OUT_DOCX = KING / 'Employment_Contract_LETTERHEAD.docx'
ASSETS = KING / '_assets'

GOLD = (246 / 255, 195 / 255, 18 / 255)
BLACK = (0, 0, 0)
WHITE = (1, 1, 1)
LAVENDER = (0.929, 0.890, 0.961)
MINT = (0.890, 0.953, 0.918)
GREEN_ACCENT = (0.184, 0.620, 0.408)
SECTION_COLOR = 4005468  # purple headings
TOC_GREEN = 2059848


def fill_close(a, b, tol=0.05):
    return all(abs(x - y) <= tol for x, y in zip(a[:3], b[:3]))


def fix_page(page: pymupdf.Page) -> None:
    """Remove highlights/green bars; rewrite headings gold, WHEREAS/AND black."""
    # Collect targets first (before drawing overlays)
    redacts = []
    redraw = []  # (bbox, text, size, color, fontname)

    for dr in page.get_drawings():
        fill = dr.get('fill')
        rect = dr.get('rect')
        if not fill or not rect:
            continue
        r = pymupdf.Rect(rect)
        if fill_close(fill, LAVENDER) or fill_close(fill, MINT):
            redacts.append(r)
        elif fill_close(fill, GREEN_ACCENT, 0.08) and r.width < 12 and r.height < 30:
            redacts.append(r)

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
                size = float(span.get('size') or 10)
                bbox = pymupdf.Rect(span['bbox'])

                if color == TOC_GREEN and stripped.upper() in ('WHEREAS', 'AND'):
                    redacts.append(bbox + (-1, -1, 1, 1))
                    redraw.append((bbox, text, size, BLACK, 'hebo'))
                elif color == SECTION_COLOR and size >= 12:
                    # full highlight strip + text
                    redacts.append(pymupdf.Rect(48, bbox.y0 - 2, 545, bbox.y1 + 2))
                    redraw.append((bbox, span['text'], size, GOLD, 'hebo'))
                elif color == SECTION_COLOR and size >= 15:
                    # title like EMPLOYMENT CONTRACT
                    redacts.append(bbox + (-2, -2, 2, 2))
                    redraw.append((bbox, stripped, size, GOLD, 'hebo'))

    for r in redacts:
        page.add_redact_annot(r, fill=(1, 1, 1))
    if redacts:
        page.apply_redactions(images=pymupdf.PDF_REDACT_IMAGE_NONE)

    for bbox, text, size, color, font in redraw:
        page.insert_text(
            pymupdf.Point(bbox.x0, bbox.y1 - 2.5),
            text,
            fontname=font,
            fontsize=size,
            color=color,
            overlay=True,
        )


def build_pdf() -> Path:
    header = ASSETS / 'letter_header.png'
    src = pymupdf.open(PREVIEW)

    # Drop TOC
    for i in range(src.page_count):
        if 'TABLE OF CONTENTS' in src[i].get_text().upper():
            src.delete_page(i)
            print('Removed TOC')
            break

    # Style fixes on content pages
    for i in range(1, src.page_count):
        fix_page(src[i])

    # Letterhead header, wipe old footer chrome, page numbers
    from PIL import Image as PILImage
    hw, hh = PILImage.open(header).size
    sample = src[1]
    page_w, page_h = float(sample.rect.width), float(sample.rect.height)
    content_top = 70.0
    for b in sample.get_text('blocks'):
        if 'EMPLOYMENT CONTRACT' in (b[4] or '').upper():
            content_top = float(b[1])
            break
    old_footer_top = 800.0
    for info in sample.get_image_info(xrefs=True):
        bbox = pymupdf.Rect(info['bbox'])
        if bbox.y1 > page_h - 5 and bbox.height < 80:
            old_footer_top = float(bbox.y0)

    header_h = min(page_w * (hh / hw), max(40.0, content_top - 8.0))
    header_cover = max(header_h, 50.0)
    footer_cover = page_h - old_footer_top + 4
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

    # Assemble with Canva cover
    canva = pymupdf.open(CANVA)
    out = pymupdf.open()
    r = src[0].rect
    out.new_page(width=r.width, height=r.height)
    out[0].show_pdf_page(out[0].rect, canva, 0)
    if src.page_count > 1:
        out.insert_pdf(src, from_page=1, to_page=src.page_count - 1)

    tmp = KING / 'Employment_Contract_LETTERHEAD_tmp.pdf'
    out.save(tmp, garbage=4, deflate=True)
    out.close()
    src.close()
    canva.close()

    target = OUT_PDF
    try:
        if target.exists():
            target.unlink()
        os.replace(tmp, target)
    except PermissionError:
        target = KING / 'Employment_Contract_LETTERHEAD_v2.pdf'
        try:
            if target.exists():
                target.unlink()
        except PermissionError:
            pass
        os.replace(tmp, target)
    print('PDF', target)
    return target


def build_docx_from_images(pdf_path: Path) -> Path:
    """Word file where each page is a faithful full-page image of the PDF."""
    doc_pdf = pymupdf.open(pdf_path)
    # A4 size in inches
    page_w_in, page_h_in = 8.27, 11.69

    document = Document()
    section = document.sections[0]
    section.page_width = Inches(page_w_in)
    section.page_height = Inches(page_h_in)
    section.left_margin = Inches(0)
    section.right_margin = Inches(0)
    section.top_margin = Inches(0)
    section.bottom_margin = Inches(0)

    img_dir = KING / '_preview_pages_letterhead' / 'docx_pages'
    img_dir.mkdir(parents=True, exist_ok=True)

    for i in range(doc_pdf.page_count):
        page = doc_pdf[i]
        # High-res render for print-quality Word
        pix = page.get_pixmap(matrix=pymupdf.Matrix(2.5, 2.5), alpha=False)
        img_path = img_dir / f'page_{i + 1:02d}.png'
        pix.save(str(img_path))

        if i > 0:
            document.add_page_break()
        document.add_picture(str(img_path), width=Inches(page_w_in))

    doc_pdf.close()

    tmp = KING / 'Employment_Contract_LETTERHEAD_tmp.docx'
    document.save(str(tmp))
    target = OUT_DOCX
    try:
        if target.exists():
            target.unlink()
        os.replace(tmp, target)
    except PermissionError:
        target = KING / 'Employment_Contract_LETTERHEAD_v2.docx'
        try:
            if target.exists():
                target.unlink()
        except PermissionError:
            pass
        os.replace(tmp, target)
    print('DOCX', target)
    return target


if __name__ == '__main__':
    pdf = build_pdf()
    # quick verify
    d = pymupdf.open(pdf)
    p = d[1]
    print('p2 WHEREAS/AND colors:')
    for b in p.get_text('dict')['blocks']:
        if b.get('type') != 0:
            continue
        for line in b.get('lines', []):
            for s in line.get('spans', []):
                t = (s.get('text') or '').strip().upper()
                if t in ('WHEREAS', 'AND'):
                    c = s['color']
                    print(' ', t, ((c >> 16) & 255, (c >> 8) & 255, c & 255))
    lav = sum(
        1
        for i in range(1, d.page_count)
        for dr in d[i].get_drawings()
        if dr.get('fill') and fill_close(dr['fill'], LAVENDER)
    )
    print('remaining lavender drawing fills:', lav)
    d.close()
    build_docx_from_images(pdf)
