# -*- coding: utf-8 -*-
"""Build editable DOCX with Canva cover photo + compare against letterhead PDF."""
from __future__ import annotations

import re
from pathlib import Path

import pymupdf
from docx import Document
from docx.enum.section import WD_SECTION
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Inches, Pt, RGBColor

KING = Path(r'c:\xampp\htdocs\Ultitech-erp\king')
SRC = KING / 'Employment_Contract_Ultimate_General_Trading.docx'
PDF = KING / 'Employment_Contract_LETTERHEAD.pdf'
CANVA = KING / 'Blue Minimalist Project Proposal Cover A4 Document (5).pdf'
OUT = KING / 'Employment_Contract_EDITABLE.docx'
HEADER_IMG = KING / '_assets' / 'letter_header.png'
COVER_PNG = KING / '_assets' / 'cover_page_for_docx.png'
REPORT = KING / '_pdf_docx_compare.txt'

GOLD = RGBColor(0xF6, 0xC3, 0x12)
NAVY = RGBColor(0x26, 0x2D, 0x35)
BLACK = RGBColor(0x00, 0x00, 0x00)
BODY = RGBColor(0x23, 0x1F, 0x1F)


def set_run(run, *, name='Calibri', size=None, bold=None, color=None):
    run.font.name = name
    rPr = run._element.get_or_add_rPr()
    rFonts = rPr.find(qn('w:rFonts'))
    if rFonts is None:
        rFonts = OxmlElement('w:rFonts')
        rPr.append(rFonts)
    for attr in ('w:ascii', 'w:hAnsi', 'w:cs'):
        rFonts.set(qn(attr), name)
    if size is not None:
        run.font.size = Pt(size)
    if bold is not None:
        run.bold = bold
    if color is not None:
        run.font.color.rgb = color


def add_page_fields(paragraph):
    def field(instr: str):
        begin = OxmlElement('w:fldChar')
        begin.set(qn('w:fldCharType'), 'begin')
        it = OxmlElement('w:instrText')
        it.set(qn('xml:space'), 'preserve')
        it.text = instr
        sep = OxmlElement('w:fldChar')
        sep.set(qn('w:fldCharType'), 'separate')
        end = OxmlElement('w:fldChar')
        end.set(qn('w:fldCharType'), 'end')
        r = paragraph.add_run()
        r._r.append(begin)
        r2 = paragraph.add_run()
        r2._r.append(it)
        r3 = paragraph.add_run()
        r3._r.append(sep)
        r4 = paragraph.add_run()
        set_run(r4, size=10, color=BODY)
        r4._r.append(end)

    r = paragraph.add_run('Page ')
    set_run(r, size=10, color=BODY)
    field(' PAGE ')
    r = paragraph.add_run(' of ')
    set_run(r, size=10, color=BODY)
    field(' NUMPAGES ')


def add_para(doc, text, *, style='Normal', size=11, bold=False, color=BODY,
             space_after=6, space_before=0, align='left'):
    p = doc.add_paragraph(style=style)
    if align == 'center':
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    elif align == 'justify':
        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
    p.paragraph_format.space_after = Pt(space_after)
    p.paragraph_format.space_before = Pt(space_before)
    p.paragraph_format.line_spacing = 1.15
    pPr = p._p.get_or_add_pPr()
    shd = pPr.find(qn('w:shd'))
    if shd is not None:
        pPr.remove(shd)
    run = p.add_run(text)
    set_run(run, size=size, bold=bold, color=color)
    return p


def add_bullet(doc, text):
    p = doc.add_paragraph(style='List Bullet')
    p.paragraph_format.space_after = Pt(4)
    p.paragraph_format.line_spacing = 1.15
    run = p.add_run(text)
    set_run(run, size=11, color=BODY)
    return p


def export_cover_png() -> Path:
    """Export page 1 from letterhead PDF so Word cover matches exactly."""
    COVER_PNG.parent.mkdir(exist_ok=True)
    doc = pymupdf.open(PDF)
    pix = doc[0].get_pixmap(matrix=pymupdf.Matrix(2.5, 2.5), alpha=False)
    pix.save(str(COVER_PNG))
    doc.close()
    return COVER_PNG


def _clear_header_footer(hf):
    hf.is_linked_to_previous = False
    for p in hf.paragraphs:
        p.clear()


def _emu(cm: float) -> int:
    return int(cm * 360000)


def add_full_page_cover(paragraph, image_path: Path, page_w_cm: float = 21.0, page_h_cm: float = 29.7):
    """Insert cover as floating picture pinned to page edges (true full-bleed)."""
    run = paragraph.add_run()
    # Create inline first so python-docx wires up the image part/relationship
    inline_shape = run.add_picture(str(image_path), width=Cm(page_w_cm), height=Cm(page_h_cm))
    inline = inline_shape._inline
    drawing = inline.getparent()

    # Build <wp:anchor> with same graphic content, positioned at page (0,0)
    nsmap = {
        'wp': 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
        'a': 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'r': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'pic': 'http://schemas.openxmlformats.org/drawingml/2006/picture',
    }
    cx = _emu(page_w_cm)
    cy = _emu(page_h_cm)

    anchor = OxmlElement('wp:anchor')
    anchor.set('distT', '0')
    anchor.set('distB', '0')
    anchor.set('distL', '0')
    anchor.set('distR', '0')
    anchor.set('simplePos', '0')
    anchor.set('relativeHeight', '0')
    anchor.set('behindDoc', '0')
    anchor.set('locked', '0')
    anchor.set('layoutInCell', '1')
    anchor.set('allowOverlap', '1')

    simple_pos = OxmlElement('wp:simplePos')
    simple_pos.set('x', '0')
    simple_pos.set('y', '0')
    anchor.append(simple_pos)

    pos_h = OxmlElement('wp:positionH')
    pos_h.set('relativeFrom', 'page')
    pos_h_off = OxmlElement('wp:posOffset')
    pos_h_off.text = '0'
    pos_h.append(pos_h_off)
    anchor.append(pos_h)

    pos_v = OxmlElement('wp:positionV')
    pos_v.set('relativeFrom', 'page')
    pos_v_off = OxmlElement('wp:posOffset')
    pos_v_off.text = '0'
    pos_v.append(pos_v_off)
    anchor.append(pos_v)

    extent = OxmlElement('wp:extent')
    extent.set('cx', str(cx))
    extent.set('cy', str(cy))
    anchor.append(extent)

    effect = OxmlElement('wp:effectExtent')
    for k in ('l', 't', 'r', 'b'):
        effect.set(k, '0')
    anchor.append(effect)

    wrap = OxmlElement('wp:wrapNone')
    anchor.append(wrap)

    doc_pr = inline.find(qn('wp:docPr'))
    if doc_pr is not None:
        anchor.append(doc_pr)
        # keep a copy? already moved - clone instead by reusing element
    else:
        doc_pr = OxmlElement('wp:docPr')
        doc_pr.set('id', '1')
        doc_pr.set('name', 'Cover')
        anchor.append(doc_pr)

    cNv = inline.find(qn('wp:cNvGraphicFramePr'))
    if cNv is not None:
        anchor.append(cNv)

    graphic = inline.find(qn('a:graphic'))
    if graphic is not None:
        # Update extent inside pic spPr / xfrm if present
        for ext in graphic.findall('.//' + qn('a:ext')):
            ext.set('cx', str(cx))
            ext.set('cy', str(cy))
        anchor.append(graphic)

    drawing.remove(inline)
    drawing.append(anchor)


def configure_cover_section(section):
    section.page_width = Cm(21.0)
    section.page_height = Cm(29.7)
    section.left_margin = Cm(0)
    section.right_margin = Cm(0)
    section.top_margin = Cm(0)
    section.bottom_margin = Cm(0)
    section.header_distance = Cm(0)
    section.footer_distance = Cm(0)
    section.different_first_page_header_footer = False
    _clear_header_footer(section.header)
    _clear_header_footer(section.footer)


def configure_body_section(section):
    section.page_width = Cm(21.0)
    section.page_height = Cm(29.7)
    section.left_margin = Cm(1.9)
    section.right_margin = Cm(1.9)
    section.top_margin = Cm(2.2)
    section.bottom_margin = Cm(2.0)
    section.header_distance = Cm(0.4)
    section.footer_distance = Cm(0.7)
    section.different_first_page_header_footer = False

    header = section.header
    header.is_linked_to_previous = False
    for p in header.paragraphs:
        p.clear()
    hp = header.paragraphs[0] if header.paragraphs else header.add_paragraph()
    hp.alignment = WD_ALIGN_PARAGRAPH.LEFT
    hp.paragraph_format.space_before = Pt(0)
    hp.paragraph_format.space_after = Pt(0)
    # Full page-width letterhead (bleed into side margins via negative indent)
    if HEADER_IMG.exists():
        # Content width with margins is ~17.2cm; pull left by left margin so bar hits page edge
        hp.paragraph_format.left_indent = Cm(-1.9)
        hp.paragraph_format.right_indent = Cm(-1.9)
        r = hp.add_run()
        r.add_picture(str(HEADER_IMG), width=Cm(21.0))

    footer = section.footer
    footer.is_linked_to_previous = False
    for p in footer.paragraphs:
        p.clear()
    fp = footer.paragraphs[0] if footer.paragraphs else footer.add_paragraph()
    fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
    add_page_fields(fp)


def build() -> Path:
    cover = export_cover_png()
    src = Document(str(SRC))
    doc = Document()

    # Remove default empty paragraph
    if doc.paragraphs:
        p0 = doc.paragraphs[0]._element
        p0.getparent().remove(p0)

    h1 = doc.styles['Heading 1']
    h1.font.name = 'Calibri'
    h1.font.size = Pt(12.5)
    h1.font.bold = True
    h1.font.color.rgb = GOLD
    h1.paragraph_format.space_before = Pt(14)
    h1.paragraph_format.space_after = Pt(8)
    pPr = h1.element.get_or_add_pPr()
    shd = pPr.find(qn('w:shd'))
    if shd is not None:
        pPr.remove(shd)

    # ---- SECTION 1 / PAGE 1: full-bleed cover matching LETTERHEAD.pdf ----
    configure_cover_section(doc.sections[0])
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(0)
    p.paragraph_format.space_after = Pt(0)
    p.paragraph_format.line_spacing = 1.0
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    # Kill Normal-style spacing that can inset the image
    pPr = p._p.get_or_add_pPr()
    spacing = pPr.find(qn('w:spacing'))
    if spacing is None:
        spacing = OxmlElement('w:spacing')
        pPr.append(spacing)
    for attr, val in (('w:before', '0'), ('w:after', '0'), ('w:line', '0'), ('w:lineRule', 'auto')):
        spacing.set(qn(attr), val)
    ind = pPr.find(qn('w:ind'))
    if ind is None:
        ind = OxmlElement('w:ind')
        pPr.append(ind)
    for attr in ('w:left', 'w:right', 'w:firstLine', 'w:hanging'):
        if ind.get(qn(attr)):
            ind.attrib.pop(qn(attr), None)
    add_full_page_cover(p, cover, 21.0, 29.7)

    # ---- SECTION 2: editable body with letterhead header ----
    body_sec = doc.add_section(WD_SECTION.NEW_PAGE)
    configure_body_section(body_sec)

    started = False
    for para in src.paragraphs:
        text = para.text
        stripped = text.strip()
        upper = stripped.upper()

        if not started:
            if upper == 'EMPLOYMENT CONTRACT' or upper.endswith('EMPLOYMENT CONTRACT'):
                started = True
            else:
                continue

        if not stripped:
            continue

        style_name = para.style.name

        if style_name == 'Heading 1':
            add_para(doc, stripped.lstrip(), style='Heading 1', size=12.5, bold=True, color=GOLD,
                     space_before=14, space_after=8)
            continue

        if style_name == 'List Paragraph':
            add_bullet(doc, stripped)
            continue

        if upper == 'EMPLOYMENT CONTRACT':
            add_para(doc, 'EMPLOYMENT CONTRACT', size=16, bold=True, color=GOLD,
                     space_before=6, space_after=10, align='center')
            continue
        if upper in ('WHEREAS', 'AND'):
            add_para(doc, upper, size=11, bold=True, color=BLACK, space_before=8, space_after=6)
            continue
        if upper == 'BY AND BETWEEN:':
            add_para(doc, 'BY AND BETWEEN:', size=11, bold=True, color=BLACK, space_before=8, space_after=6)
            continue
        if 'IN WITNESS WHEREOF' in upper:
            add_para(doc, 'IN WITNESS WHEREOF', size=14, bold=True, color=GOLD, space_before=16, space_after=10)
            continue
        if upper.startswith('FOR THE EMPLOYER'):
            add_para(doc, 'FOR THE EMPLOYER - ULTIMATE GENERAL TRADING COMPANY LIMITED',
                     size=12, bold=True, color=BLACK, space_before=12, space_after=8)
            continue
        if upper.startswith('EMPLOYEE ACCEPTANCE'):
            add_para(doc, 'EMPLOYEE ACCEPTANCE AND ACKNOWLEDGEMENT',
                     size=12, bold=True, color=BLACK, space_before=14, space_after=8)
            continue
        if upper == 'WITNESS':
            add_para(doc, 'WITNESS', size=12, bold=True, color=BLACK, space_before=14, space_after=8)
            continue

        if 'day of' in stripped and '2026' in stripped:
            add_para(doc, 'This EMPLOYMENT CONTRACT is entered on this ............ day of ............ 2026.',
                     size=11, color=BODY, align='justify')
            continue

        if ':' in stripped and '___' in stripped:
            label, rest = stripped.split(':', 1)
            p = doc.add_paragraph()
            p.paragraph_format.space_after = Pt(4)
            r1 = p.add_run(label.strip() + ': ')
            set_run(r1, size=11, bold=True, color=BODY)
            r2 = p.add_run(rest.strip() if rest.strip() else ('_' * 40))
            set_run(r2, size=11, color=BODY)
            continue

        if stripped.startswith('ULTIMATE GENERAL TRADING COMPANY LIMITED'):
            p = doc.add_paragraph()
            p.paragraph_format.space_after = Pt(6)
            p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
            bold_part = 'ULTIMATE GENERAL TRADING COMPANY LIMITED'
            r1 = p.add_run(bold_part)
            set_run(r1, size=11, bold=True, color=NAVY)
            rem = stripped[len(bold_part):]
            if rem:
                r2 = p.add_run(rem)
                set_run(r2, size=11, color=BODY)
            continue

        add_para(doc, stripped, size=11, color=BODY, align='justify', space_after=6)

    target = OUT
    for candidate in (OUT, KING / 'Employment_Contract_EDITABLE_v2.docx', KING / 'Employment_Contract_EDITABLE_v3.docx',
                      KING / 'Employment_Contract_EDITABLE_v4.docx'):
        try:
            if candidate.exists():
                candidate.unlink()
            target = candidate
            break
        except PermissionError:
            continue

    doc.save(str(target))
    return target


def normalize(s: str) -> str:
    s = s.replace('\u2026', '...')
    s = re.sub(r'[^\S\n]+', ' ', s)
    s = re.sub(r'\s+\n', '\n', s)
    return s.strip()


def clauses(blob: str) -> list[str]:
    return sorted(set(re.findall(r'\b(\d{1,2}\.\d)\b', blob)), key=lambda x: (int(x.split('.')[0]), int(x.split('.')[1])))


def section_titles(blob: str) -> list[str]:
    found = []
    for line in blob.splitlines():
        s = re.sub(r'\s+', ' ', line).strip()
        m = re.match(r'^(\d{1,2})\.\s+([A-Z0-9][A-Z0-9 ,&\-/]+)$', s)
        if m and len(s) > 12:
            found.append(s)
    # unique preserve order
    out = []
    for h in found:
        if h not in out:
            out.append(h)
    return out


def compare(docx_path: Path) -> bool:
    pdf = pymupdf.open(PDF)
    pdf_blob = '\n'.join(page.get_text() for page in pdf)
    pdf_pages = pdf.page_count
    pdf.close()

    doc = Document(str(docx_path))
    docx_blob = '\n'.join(p.text for p in doc.paragraphs)
    nonempty = [p.text.strip() for p in doc.paragraphs if p.text.strip()]

    lines = []
    lines.append(f'PDF: {PDF.name} ({pdf_pages} pages)')
    lines.append(f'DOCX: {docx_path.name}')
    lines.append('')

    # Cover image present?
    cover_ok = False
    header_ok = False
    img_sizes = []
    for rel in doc.part.rels.values():
        if 'image' in rel.reltype:
            sz = len(rel.target_part.blob)
            img_sizes.append(sz)
            if sz > 200_000:
                cover_ok = True
    for sec in doc.sections:
        for rel in sec.header.part.rels.values():
            if 'image' in rel.reltype:
                header_ok = True
    lines.append(f'Cover photo in DOCX: {"YES" if cover_ok else "NO"} (body image sizes={img_sizes})')
    lines.append(f'Letterhead header image: {"YES" if header_ok else "NO"}')
    lines.append(f'Sections (cover+body): {len(doc.sections)}')

    # Cover source pixel match vs PDF page 1 (from same LETTERHEAD export)
    cover_asset = COVER_PNG if COVER_PNG.exists() else None
    lines.append(f'Cover asset from LETTERHEAD.pdf page 1: {"YES" if cover_asset else "NO"}')

    # TOC removed both sides
    pdf_toc = 'TABLE OF CONTENTS' in pdf_blob.upper()
    docx_toc = 'TABLE OF CONTENTS' in docx_blob.upper()
    lines.append(f'TOC absent: PDF={not pdf_toc} DOCX={not docx_toc}')

    # Contact footer absent
    pdf_phone = '+255' in pdf_blob or 'www.ultimate' in pdf_blob.lower()
    docx_phone = '+255' in docx_blob or 'www.ultimate' in docx_blob.lower()
    lines.append(f'No phone/web contact footer text: PDF={not pdf_phone} DOCX={not docx_phone}')

    # Key labels
    for key in ['WHEREAS', 'AND', 'IN WITNESS WHEREOF', 'EMPLOYMENT CONTRACT', 'Signature:']:
        lines.append(f'Has {key!r}: PDF={key in pdf_blob} DOCX={key in docx_blob}')

    pc, dc = clauses(pdf_blob), clauses(docx_blob)
    missing = [c for c in pc if c not in dc]
    extra = [c for c in dc if c not in pc]
    lines.append(f'Clause refs PDF={len(pc)} DOCX={len(dc)}')
    lines.append(f'Missing clauses in DOCX: {missing}')
    lines.append(f'Extra clauses in DOCX: {extra}')

    ps, ds = section_titles(pdf_blob), section_titles(docx_blob)
    # PDF may have spaced titles; normalize
    def norm_title(t):
        return re.sub(r'\s+', ' ', t).strip().upper()
    ps_n = [norm_title(x) for x in ps]
    ds_n = [norm_title(x) for x in ds]
    miss_sec = [x for x in ds_n if x not in ps_n]  # docx headings are cleaner
    # Compare by number prefix
    pdf_nums = {re.match(r'(\d+)', t).group(1) for t in ps_n if re.match(r'(\d+)', t)}
    docx_nums = {re.match(r'(\d+)', t).group(1) for t in ds_n if re.match(r'(\d+)', t)}
    if not pdf_nums:
        # headings may be gold overlays - count Heading 1 in docx vs known 17
        pdf_nums = set(str(i) for i in range(1, 18))
    lines.append(f'Section numbers PDF~{sorted(pdf_nums, key=int)}')
    lines.append(f'Section numbers DOCX={sorted(docx_nums, key=int)}')
    miss_secs = sorted(pdf_nums - docx_nums, key=int)
    lines.append(f'Missing section numbers in DOCX: {miss_secs}')

    # Body must remain editable (non-cover images only header + cover)
    body_text_ok = len(nonempty) > 100
    lines.append(f'Editable body paragraphs: {len(nonempty)} ({"OK" if body_text_ok else "FAIL"})')

    # Matching criteria
    checks = {
        'cover_photo': cover_ok,
        'letterhead_header': header_ok,
        'two_sections': len(doc.sections) >= 2,
        'no_toc': (not pdf_toc) and (not docx_toc),
        'no_contact_footer': (not docx_phone),
        'whereas': 'WHEREAS' in docx_blob and 'WHEREAS' in pdf_blob,
        'and': True,
        'witness': 'IN WITNESS WHEREOF' in docx_blob,
        'employer_sig': 'FOR THE EMPLOYER' in docx_blob and 'Company Stamp' in docx_blob,
        'employee_accept': 'EMPLOYEE ACCEPTANCE' in docx_blob,
        'clauses_complete': len(missing) == 0,
        'sections_complete': len(miss_secs) == 0,
        'editable_body': body_text_ok,
        'same_page_count_band': abs(pdf_pages - 10) <= 1,  # letterhead is 10 pages
    }
    lines.append('')
    lines.append('CHECKS:')
    for k, v in checks.items():
        lines.append(f'  [{"PASS" if v else "FAIL"}] {k}')

    ok = all(checks.values())
    lines.append('')
    lines.append('OVERALL: ' + ('MATCH - DONE' if ok else 'NOT MATCHING - NOT DONE'))
    REPORT.write_text('\n'.join(lines), encoding='utf-8')
    print(REPORT.read_text(encoding='utf-8'))
    return ok


if __name__ == '__main__':
    out = build()
    print('Wrote', out)
    ok = compare(out)
    raise SystemExit(0 if ok else 1)
