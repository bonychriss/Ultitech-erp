import json
import os
from docx import Document
from docx.enum.section import WD_SECTION
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK, WD_LINE_SPACING
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor

T = os.environ['TEMP']
SHOTS = os.path.join(T, 'fr_shots')
SAMPLES = os.path.join(T, 'fr_samples')
OUT = os.path.join(T, 'fr_build', 'Baraka_Field_Report.docx')
IMG_W = float(os.environ.get('FR_IMG_W', '5.7'))
PAGES_JSON = os.path.join(T, 'fr_build', 'pages.json')
ENTRIES_JSON = os.path.join(T, 'fr_build', 'entries.json')
PAGES = json.load(open(PAGES_JSON)) if os.environ.get('FR_PASS') == '2' and os.path.exists(PAGES_JSON) else {}
TOC_ENTRIES = []   # (level, text, search_key)
FIG_ENTRIES = []   # (text, search_key)
FIG_NO = [0]

doc = Document()

# ---------- base styles ----------
st = doc.styles['Normal']
st.font.name = 'Times New Roman'
st.font.size = Pt(12)
st.element.rPr.rFonts.set(qn('w:eastAsia'), 'Times New Roman')
pf = st.paragraph_format
pf.line_spacing = 1.5
pf.space_after = Pt(6)

for name, size in (('Heading 1', 14), ('Heading 2', 12), ('Heading 3', 12)):
    h = doc.styles[name]
    h.font.name = 'Times New Roman'
    h.element.rPr.rFonts.set(qn('w:ascii'), 'Times New Roman')
    h.element.rPr.rFonts.set(qn('w:hAnsi'), 'Times New Roman')
    for attr in ('w:asciiTheme', 'w:hAnsiTheme', 'w:eastAsiaTheme', 'w:cstheme'):
        if h.element.rPr.rFonts.get(qn(attr)) is not None:
            del h.element.rPr.rFonts.attrib[qn(attr)]
    h.font.size = Pt(size)
    h.font.bold = True
    h.font.italic = False
    h.font.color.rgb = RGBColor(0, 0, 0)
    h.paragraph_format.space_before = Pt(12)
    h.paragraph_format.space_after = Pt(6)
    h.paragraph_format.line_spacing = 1.5
    h.paragraph_format.keep_with_next = True
doc.styles['Heading 1'].paragraph_format.alignment = WD_ALIGN_PARAGRAPH.CENTER

cap = doc.styles['Caption']
cap.font.name = 'Times New Roman'
cap.font.size = Pt(11)
cap.font.italic = True
cap.font.bold = False
cap.font.color.rgb = RGBColor(0, 0, 0)
cap.paragraph_format.alignment = WD_ALIGN_PARAGRAPH.CENTER
cap.paragraph_format.space_after = Pt(10)

sec = doc.sections[0]
sec.page_width, sec.page_height = Inches(8.27), Inches(11.69)
sec.left_margin = Inches(1.25)
sec.right_margin = Inches(1)
sec.top_margin = Inches(1)
sec.bottom_margin = Inches(1)


# ---------- helpers ----------
def add_field(paragraph, instr):
    r = paragraph.add_run()
    b = OxmlElement('w:fldChar'); b.set(qn('w:fldCharType'), 'begin')
    i = OxmlElement('w:instrText'); i.set(qn('xml:space'), 'preserve'); i.text = instr
    s = OxmlElement('w:fldChar'); s.set(qn('w:fldCharType'), 'separate')
    t = OxmlElement('w:t'); t.text = '1'
    e = OxmlElement('w:fldChar'); e.set(qn('w:fldCharType'), 'end')
    r._r.append(b); r._r.append(i); r._r.append(s); r._r.append(t); r._r.append(e)
    return r


def para(text='', bold=False, italic=False, align=None, size=None, space_after=None, style=None):
    p = doc.add_paragraph(style=style)
    if text:
        r = p.add_run(text)
        r.bold = bold
        r.italic = italic
        if size:
            r.font.size = Pt(size)
    if align is not None:
        p.alignment = align
    else:
        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
    if space_after is not None:
        p.paragraph_format.space_after = Pt(space_after)
    return p


def h1(text):
    TOC_ENTRIES.append((1, text, text))
    return doc.add_heading(text, level=1)


def chapter(num, title):
    TOC_ENTRIES.append((1, f'CHAPTER {num}: {title}', f'CHAPTER {num}'))
    p = doc.add_heading(level=1)
    p.paragraph_format.page_break_before = num != 'ONE'
    p.add_run(f'CHAPTER {num}')
    p.add_run().add_break(WD_BREAK.LINE)
    p.add_run(title)
    return p


def h2(text):
    TOC_ENTRIES.append((2, text, text))
    return doc.add_heading(text, level=2)


def h3(text):
    TOC_ENTRIES.append((3, text, text))
    return doc.add_heading(text, level=3)


def bullets(items, numbered=False):
    letters = 'abcdefghijklmnopqrstuvwxyz'
    for i, it in enumerate(items):
        p = doc.add_paragraph()
        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
        p.paragraph_format.left_indent = Inches(0.4)
        p.paragraph_format.first_line_indent = Inches(-0.3)
        p.paragraph_format.space_after = Pt(2)
        lead = f'{letters[i]})  ' if numbered else '\u2022  '
        if isinstance(it, tuple):
            p.add_run(lead)
            r = p.add_run(it[0] + ': ')
            r.bold = True
            p.add_run(it[1])
        else:
            p.add_run(lead + it)


def figure(path, caption, width=None):
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.keep_with_next = True
    p.paragraph_format.space_after = Pt(2)
    p.paragraph_format.line_spacing = 1.0
    p.add_run().add_picture(path, width=Inches(width or IMG_W))
    add_caption(caption)


def add_caption(caption):
    FIG_NO[0] += 1
    label = f'Figure {FIG_NO[0]}: {caption}'
    FIG_ENTRIES.append((label, f'Figure {FIG_NO[0]}:'))
    c = doc.add_paragraph(style='Caption')
    c.add_run(label)


def figure_pair(p1, p2, caption, width=2.3):
    t = doc.add_table(rows=1, cols=2)
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for cell, path in zip(t.rows[0].cells, (p1, p2)):
        cp = cell.paragraphs[0]
        cp.alignment = WD_ALIGN_PARAGRAPH.CENTER
        cp.paragraph_format.line_spacing = 1.0
        cp.add_run().add_picture(path, width=Inches(width))
    add_caption(caption)


def shot(name):
    return os.path.join(SHOTS, name + '.jpg')


def set_page_numbers(section, fmt, start=1):
    sp = section._sectPr
    pg = sp.find(qn('w:pgNumType'))
    if pg is None:
        pg = OxmlElement('w:pgNumType'); sp.append(pg)
    pg.set(qn('w:fmt'), fmt)
    pg.set(qn('w:start'), str(start))


def footer_page_number(section):
    section.footer.is_linked_to_previous = False
    fp = section.footer.paragraphs[0]
    fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for r in list(fp.runs):
        r._r.getparent().remove(r._r)
    add_field(fp, ' PAGE ')


def page_border(section):
    sp = section._sectPr
    b = OxmlElement('w:pgBorders')
    b.set(qn('w:offsetFrom'), 'page')
    for side in ('top', 'left', 'bottom', 'right'):
        e = OxmlElement(f'w:{side}')
        e.set(qn('w:val'), 'thickThinSmallGap')
        e.set(qn('w:sz'), '24')
        e.set(qn('w:space'), '24')
        e.set(qn('w:color'), '000000')
        b.append(e)
    sp.append(b)


def new_section():
    s = doc.add_section(WD_SECTION.NEW_PAGE)
    pg = s._sectPr.find(qn('w:pgNumType'))
    if pg is not None and pg.get(qn('w:start')) is not None:
        del pg.attrib[qn('w:start')]
    return s


def page_break():
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)


TEXT_W = Inches(8.27 - 1.25 - 1)
SLOTS = {}


def toc_slot(kind):
    # Placeholder paragraph; filled with static entries once all headings are known.
    p = doc.add_paragraph()
    SLOTS[kind] = p
    return p


def fill_slot(kind, entries):
    anchor = SLOTS[kind]
    for level, text, key in entries:
        p = doc.add_paragraph()
        pf_ = p.paragraph_format
        pf_.line_spacing = 1.15
        pf_.space_after = Pt(3 if level == 1 else 1)
        pf_.left_indent = Inches(0.3 * (level - 1))
        pf_.tab_stops.add_tab_stop(TEXT_W, alignment=2, leader=1)
        r = p.add_run(text)
        if level == 1 and kind == 'toc':
            r.bold = True
        p.add_run('\t' + str(PAGES.get(key, '00')))
        anchor._p.addprevious(p._p)
    anchor._p.getparent().remove(anchor._p)


def toc(instr, placeholder):
    toc_slot('toc' if 'TOC \\o' in instr else 'lof')


def front_title(text):
    if text != 'TABLE OF CONTENTS':
        TOC_ENTRIES.append((1, text, text))
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_after = Pt(12)
    r = p.add_run(text)
    r.bold = True
    r.font.size = Pt(14)


# =====================================================================
# COVER (follows the DIT assignment cover)
# =====================================================================
page_border(sec)
para('', space_after=30)
for line in ('DAR ES SALAAM INSTITUTE', 'OF', 'TECHNOLOGY'):
    para(line, bold=True, align=WD_ALIGN_PARAGRAPH.CENTER, size=20, space_after=0)
p = para(align=WD_ALIGN_PARAGRAPH.CENTER, space_after=6)
p.paragraph_format.space_before = Pt(18)
p.add_run().add_picture(os.path.join(SAMPLES, 'dit_logo_0.jpeg'), width=Inches(1.7))
para('COMPUTER STUDIES DEPARTMENT.', bold=True, align=WD_ALIGN_PARAGRAPH.CENTER, size=16, space_after=10)

p = para(align=WD_ALIGN_PARAGRAPH.CENTER, space_after=14)
pPr = p._p.get_or_add_pPr()
bdr = OxmlElement('w:pBdr')
for side in ('top', 'bottom'):
    e = OxmlElement(f'w:{side}'); e.set(qn('w:val'), 'thickThinSmallGap'); e.set(qn('w:sz'), '18'); e.set(qn('w:space'), '4'); e.set(qn('w:color'), '000000')
    bdr.append(e)
pPr.append(bdr)
r = p.add_run('REPORT FOR FIELD PRACTICAL TRAINING 2026')
r.bold = True
r.font.size = Pt(15)

fields = [
    ('PROGRAMME', 'ORDINARY DIPLOMA IN COMPUTER ENGINEERING'),
    ('CLASS', 'OD24COE-1'),
    ("STUDENT'S NAME", 'BARAKA JAPHARY ULOTU'),
    ('REGISTRATION NO', '240323415859'),
    ('ORGANIZATION NAME', 'ULTIMATE GENERAL TRADING (UGT)'),
    ('ORGANIZATION SUPERVISOR', '________________________________'),
    ('ACADEMIC SUPERVISOR', '________________________________'),
    ('TRAINING PERIOD', '31st AUGUST 2026 TO 10th NOVEMBER 2026'),
]
t = doc.add_table(rows=0, cols=2)
for k, v in fields:
    row = t.add_row().cells
    row[0].width, row[1].width = Inches(2.5), Inches(3.6)
    a = row[0].paragraphs[0]; a.paragraph_format.space_after = Pt(4)
    ra = a.add_run(k); ra.bold = True; ra.font.size = Pt(11.5)
    b = row[1].paragraphs[0]; b.paragraph_format.space_after = Pt(4)
    rb = b.add_run(': ' + v); rb.font.size = Pt(11.5)

# =====================================================================
# FRONT MATTER (roman numbers)
# =====================================================================
sec = new_section()
sec._sectPr.remove(sec._sectPr.find(qn('w:pgBorders'))) if sec._sectPr.find(qn('w:pgBorders')) is not None else None
set_page_numbers(sec, 'lowerRoman', 1)
footer_page_number(sec)

h1('ABSTRACT')
para('This report presents the work done, the knowledge gained and the skills developed during my Field '
     'Practical Training at Ultimate General Trading (UGT) from 31st August 2026 to 10th November 2026. '
     'The training was undertaken as part of the practical requirements of the Ordinary Diploma in Computer '
     'Engineering at Dar es Salaam Institute of Technology (DIT).')
para('UGT is a business-to-business supplier of Personal Protective Equipment (PPE) and industrial work-wear '
     'in Dar es Salaam. Its daily operations - payment approvals, accounting, sales, stock control and '
     'deliveries - run on a web-based Enterprise Resource Planning (ERP) system. During the training I '
     'worked as part of the team that develops and maintains this ERP system.')
para('The main tasks performed were: improving the Payment Voucher module (attachments, approval '
     'signatures, payee records and notifications); building and refining accounting desks such as Revenue, '
     'Expenses, Purchases, Journal Entries, VAT Control and the Liquidity Dashboard; improving sales '
     'documents (quotations, sales orders, invoices, PDF exports and a Customer Performance dashboard); '
     'adding a stock replenishment trace; creating a Cover Page module and business reports; making the '
     'system usable on mobile phones; and fixing and deploying bugs reported by users through a local-first '
     'testing and Git version-control workflow.')
para('The training strengthened my skills in web programming (PHP, JavaScript/React, HTML and CSS), '
     'relational databases (MySQL), debugging, version control and teamwork. The main challenges were '
     'working on a large shared code base, character-encoding errors and changes from other developers '
     'affecting my work; these were solved through careful testing, code review and communication. The '
     'report ends with conclusions and recommendations to DIT and to UGT.')

new_section()
h1('ACKNOWLEDGEMENT')
para('First, I thank Almighty God for the good health, strength and guidance He gave me throughout the period '
     'of my field practical training.')
para('I sincerely thank Dar es Salaam Institute of Technology (DIT), and especially the Computer Studies '
     'Department, for giving me the knowledge and the opportunity to undertake this training as part of my '
     'Ordinary Diploma in Computer Engineering.')
para('My special appreciation goes to the management and staff of Ultimate General Trading for accepting me, '
     'for their warm reception and for allowing me to work on the real systems that the company uses every '
     'day. I am grateful to my organization supervisor for the close guidance, encouragement and constructive '
     'advice that helped me understand how professional software is built, tested and delivered.')
para('I also thank my academic supervisor for the academic guidance and advice given during the training '
     'and during the preparation of this report.')
para('Finally, I thank my fellow developers and all UGT staff from the finance, sales and stores departments '
     'who reported problems, tested new features and shared their knowledge with me. To everyone who '
     'contributed directly or indirectly to the success of this training, thank you very much.')

new_section()
front_title('TABLE OF CONTENTS')
toc(' TOC \\o "1-2" \\h \\z \\u ', 'Right-click and choose Update Field to build the table of contents.')

new_section()
front_title('LIST OF ABBREVIATIONS')
abbr = [
    ('API', 'Application Programming Interface'),
    ('CSS', 'Cascading Style Sheets'),
    ('CRM', 'Customer Relationship Management'),
    ('DB', 'Database'),
    ('DIT', 'Dar es Salaam Institute of Technology'),
    ('ERP', 'Enterprise Resource Planning'),
    ('FTPS', 'File Transfer Protocol Secure'),
    ('HTML', 'HyperText Markup Language'),
    ('JS', 'JavaScript'),
    ('KPI', 'Key Performance Indicator'),
    ('LPO', 'Local Purchase Order'),
    ('OHS', 'Occupational Health and Safety'),
    ('PDF', 'Portable Document Format'),
    ('PHP', 'PHP: Hypertext Preprocessor'),
    ('PO', 'Purchase Order'),
    ('PPE', 'Personal Protective Equipment'),
    ('PV', 'Payment Voucher'),
    ('SQL', 'Structured Query Language'),
    ('UGT', 'Ultimate General Trading'),
    ('UI', 'User Interface'),
    ('UTF-8', 'Unicode Transformation Format (8-bit)'),
    ('VAT', 'Value Added Tax'),
]
t = doc.add_table(rows=0, cols=2)
t.style = 'Table Grid'
for k, v in abbr:
    row = t.add_row().cells
    row[0].width, row[1].width = Inches(1.3), Inches(4.6)
    row[0].paragraphs[0].add_run(k).bold = True
    row[1].paragraphs[0].add_run(v)
    for c in row:
        c.paragraphs[0].paragraph_format.space_after = Pt(0)
        c.paragraphs[0].paragraph_format.line_spacing = 1.15

new_section()
front_title('LIST OF FIGURES')
toc(' TOC \\h \\z \\c "Figure" ', 'Right-click and choose Update Field to build the list of figures.')

# =====================================================================
# CHAPTER ONE (arabic numbers start at 1)
# =====================================================================
sec = new_section()
set_page_numbers(sec, 'decimal', 1)
footer_page_number(sec)

chapter('ONE', 'INTRODUCTION')
h2('1.1  Background of the Organization')
para('Ultimate General Trading (UGT) is a supplier and distributor of Personal Protective Equipment (PPE) and '
     'industrial work-wear in Tanzania. The company operates on a business-to-business (B2B) model and serves '
     'corporate customers in high-risk sectors such as mining, oil and gas, manufacturing, construction and '
     'corporate security. It deals in both wholesale and retail distribution of safety gear such as helmets, '
     'safety boots, gloves, goggles, reflective vests and ear protection.')
para('The company is located at House No. 14, Atisoko Street, bordering Manyara Street, in Mikocheni B, '
     'Kinondoni, Dar es Salaam. Mikocheni is an established commercial and light-industrial area, which keeps '
     'the company close to its corporate customers and makes distribution easier.')
para('To manage its growing operations, UGT uses a web-based ERP system called Ultitech ERP. The system '
     'covers payment vouchers, accounting, sales, stock and warehouses, deliveries, payroll, attendance and '
     'reports. My field training was carried out in the team that develops and supports this system.')
figure(os.path.join(SAMPLES, 'mtei_image2.jpeg'), 'Some of the PPE products supplied by UGT', width=3.6)

h2('1.2  Vision')
para('To become the premier and most trusted provider of comprehensive occupational health and safety '
     'solutions across East Africa.')
h2('1.3  Mission')
para('To deliver internationally certified PPE and tailored work-wear that guarantee maximum workplace safety, '
     'bridging the gap between global manufacturers and local industries through efficient B2B procurement, '
     'competitive pricing and reliable delivery, while giving customers the technical safety advice they need '
     'to meet local compliance regulations.')

h2('1.4  Objectives of the Organization')
bullets([
    ('Promote workplace safety', 'supply high-quality certified PPE to reduce industrial accidents.'),
    ('Expand B2B market presence', 'build long-term partnerships with institutional clients in high-risk sectors.'),
    ('Optimize supply chain logistics', 'deliver bulk corporate orders reliably, on time and at low cost.'),
    ('Deliver compliance expertise', 'guide clients to satisfy Occupational Health and Safety (OHS) regulations.'),
    ('Foster corporate growth', 'maintain a stable, profitable business that creates skilled local jobs.'),
], numbered=True)

h2('1.5  Activities Undertaken by the Organization')
bullets([
    'Technical sourcing and procurement of protective gear from certified global manufacturers.',
    'Inventory and warehouse management, quality control and stock tracking at the Mikocheni B hub.',
    'Processing and fulfilling corporate contracts and Local Purchase Orders (LPOs).',
    'B2B marketing, quotations and sales consultations with corporate safety officers.',
    'Financial tracking, payment approval, invoicing, tax (VAT) compliance and after-sales support.',
    'Developing and maintaining the company ERP system that supports all the activities above.',
], numbered=True)

h2('1.6  Organization Structure')
para('UGT uses a centralized functional structure. The Executive Director is responsible for strategy and '
     'governance, and the Operations Manager coordinates the daily work of the departments. All departments '
     'record their work in the shared ERP system, which is why changes to the system affect the whole company.')
figure(os.path.join(T, 'fr_build', 'orgchart.png'), 'Organization structure of UGT', width=5.6)

# =====================================================================
# CHAPTER TWO
# =====================================================================
chapter('TWO', 'WORK DONE DURING THE TRAINING')
h2('2.1  Introduction')
para('During the training I worked on Ultitech ERP, the multi-company web application used by UGT. The '
     'back end is written in PHP with MySQL databases, and newer pages are built with React and the Vite build '
     'tool. Every module is opened from a module picker after login (Figure 3). My work involved adding '
     'features requested by staff, fixing reported bugs, improving the look of pages on computers and phones, '
     'and delivering the changes to the live server. In total I made more than one hundred recorded code '
     'changes (commits) during the training period.')
figure(shot('f01_modules'), 'ERP module picker showing the modules used by UGT')

h2('2.2  Development Environment and Workflow')
para('The following tools and procedures were used for every task:')
bullets([
    ('XAMPP (Apache, PHP, MySQL)', 'a local copy of the system and its databases where every change was built and tested first.'),
    ('Visual Studio Code / Cursor', 'the code editor used to read and change PHP, JavaScript, CSS and SQL code.'),
    ('Git', 'version control; every change was committed with a clear message, merged with the work of other developers and pushed to the shared repository.'),
    ('Node.js and Vite', 'used to build the React front-end pages into optimized files for the server.'),
    ('FTPS', 'secure file transfer used to deploy only the changed files to the live server after approval.'),
    ('Browser developer tools', 'used to inspect pages, network requests and errors while debugging.'),
])
para('The workflow was local-first: reproduce a problem on the local server, fix it, test it, commit it, '
     'and only deploy to the live system after the change was confirmed and approved. This protected the live '
     'company data from untested changes.')

h2('2.3  Payment Voucher Module')
para('A payment voucher (PV) is the document used to request, approve and record every payment made by the '
     'company. The PV dashboard lists all vouchers with summary cards for pending, approved, rejected and paid '
     'vouchers (Figure 4). My tasks in this module were:')
bullets([
    'Counting only the vouchers waiting on the logged-in person and showing per-user task notifications on the module picker.',
    'Showing approver signatures on the voucher, and allowing the Applicant and Department Manager to share one signature when they are the same person.',
    'Restoring search, filters and the last-opened voucher when the user presses Back.',
    'Linking payees with stock suppliers in one database so new suppliers appear in the voucher payee selector.',
    'Fixing session cookies so users stay logged in when moving between modules.',
])
figure(shot('f02_pv_dashboard'), 'Payment Voucher dashboard with summary cards and voucher list')
para('Each voucher is shown as an official document with its approval flow (Figure 5). Supporting documents '
     'such as receipts, invoices and the linked purchase order are shown as cards below the voucher '
     '(Figure 6), where they can be previewed or downloaded.')
figure(shot('f03_pv_view'), 'Voucher preview showing the expense voucher and its approval flow')
figure(shot('f04_pv_attachments'), 'Supporting documents of a voucher, including the linked purchase order')
para('One reported bug was that users received the message "Invalid attachment ID" when removing an '
     'attachment while editing a voucher. Investigation showed that some files existed only in the voucher '
     'folder on the server and had no database record, so the delete request had no valid ID. I added a safe '
     'delete function that checks the user permission and voucher status, deletes the file by name, and records '
     'the action in the voucher history. The edit page in Figure 7 now removes such attachments correctly.')
figure(shot('f05_pv_edit'), 'Editing a voucher and managing its supporting files')

h2('2.4  Accounting Module')
para('The accounting module groups the finance desks of the company (Figure 8). I worked on most of these desks.')
figure(shot('f06_accounting'), 'Accounting module landing page')
h3('2.4.1  Revenue Desk')
para('The revenue desk records money received from customers. I made the summary cards (total, VAT, '
     'outstanding and overdue amounts) and the export follow the filters applied to the list, fixed the monthly '
     'numbering of revenue vouchers, kept filters when returning from an invoice, and set the default order so '
     'that "All data" runs from the first entry to the latest (Figure 9).')
figure(shot('f07_revenue'), 'Revenue desk with filtered summary cards, trend chart and status breakdown')
h3('2.4.2  Expenses and Purchases Desks')
para('Payment vouchers are separated by purpose: General Payment vouchers belong to the Expenses desk, while '
     'Stock Purchase vouchers belong to the Purchases desk. I added the routing so each desk shows only its own '
     'vouchers, made the Purchases desk reuse the Expenses design (Figures 10 and 11), and set both desks to '
     'open on the current month with compact summary cards.')
figure(shot('f08_expenses'), 'Expenses desk showing general payment vouchers')
figure(shot('f09_purchases'), 'Purchases desk showing stock purchase vouchers only')
h3('2.4.3  Journal Entries')
para('I redesigned the journal entries list, details and edit pages, fixed the toolbar and scrolling, and '
     'fixed live pages that were loading an old database configuration (Figure 12).')
figure(shot('f10_journal'), 'Redesigned Journal Entries list')
h3('2.4.4  VAT Control and Liquidity Dashboard')
para('I added the VAT Control module, which summarises output VAT from sales and input VAT from purchases '
     'and calculates the VAT payable for each period (Figure 13). I also added drill-down and export options '
     'to the Liquidity Dashboard, which shows cash and bank balances and recent transactions (Figure 14).')
figure(shot('f11_vat'), 'VAT Control showing output VAT, input VAT and VAT period history')
figure(shot('f12_balances'), 'Liquidity Dashboard with balances and transactions')

h2('2.5  Sales Module')
para('The sales module handles quotations, sales orders, invoices and customers. My work included:')
bullets([
    'Numbering, highlighting and deleting quotations from the list, and restyling all sales lists to match the quotations layout (Figure 15).',
    'Making the summary cards on sales lists follow the filtered table rows (Figure 16).',
    'Fixing PDF exports so table rows are not cut between pages and the totals block stays together on one page.',
    'Restoring the search bar and "Create quotation" toolbar after a style change hid them on some screen sizes.',
    'Building a Customer Performance dashboard that ranks customers from real sales invoices by period (Figure 17).',
])
figure(shot('f13_quotations'), 'Quotations list with search bar, summary cards and Create button')
figure(shot('f15_invoices'), 'Sales invoices list with summary cards that follow the filters')
figure(shot('f16_customer_perf'), 'Customer Performance dashboard and customer ranking')

h2('2.6  Stock Replenishment')
para('The replenishment report shows products whose stock has fallen below the required level (Figure 18). '
     'I added a product trace window that shows the sales history, the purchase orders and the internal '
     'purchases for a product, with exact matching of products by name or SKU so that wrong purchase orders '
     'are not shown. I also fixed broken characters (mojibake) in the stock pages.')
figure(shot('f17_replenishment'), 'Stock replenishment list')

h2('2.7  Outstanding Invoices and Payables')
para('This module tracks money owed to the company (receivables) and money the company owes (payables). I '
     'allowed past dates when recording outstanding invoices, integrated payments recorded elsewhere in the '
     'system, and added a manual payables action with better mobile layout (Figure 19).')
figure(shot('f20_outstanding'), 'Outstanding invoices showing receivables and payables')

h2('2.8  Reports and Cover Page Module')
para('I improved business reports: an Operations report was added, quarterly reports can choose their '
     'quarter, and every report now uses a shared cover with separate pages in the editor and on export '
     '(Figure 20). I also created a new Cover Page module that prepares official cover pages for company '
     'files, with a preview, a colour for each file type and an approval signature section (Figure 21).')
figure(shot('f19_reports'), 'Overview dashboard and business reports')
figure(shot('f18_cover_page'), 'Cover Page module for preparing file covers')

h2('2.9  Mobile Design and User Experience')
para('Many staff use the system on their phones. I changed the PV dashboard to show each voucher as a card '
     'on small screens, added a collapsible search on sales lists (Figure 22), a system-wide one-step Back '
     'button, a light/dark mode toggle, and smaller support buttons that open help and calls without leaving '
     'the page.')
figure_pair(shot('f21_mobile_pv'), shot('f22_mobile_quotes'), 'PV dashboard and quotations list on a mobile phone')

h2('2.10  Bug Fixing, Version Control and Deployment')
para('Besides new features, a large part of my work was solving problems reported by users. Examples include:')
bullets([
    'Blank accounting pages caused by an invisible UTF-8 byte-order mark (BOM) at the start of a PHP file, which blocked login redirects.',
    'Broken separator characters (shown as \u201c\ufffd\u201d) caused by files saved in the wrong text encoding; they were converted to UTF-8.',
    'Page-not-found (404) and server (500) errors caused by wrong paths when files were deployed to the company folders.',
    'A quotations toolbar that disappeared on wide screens after a shared style file was changed.',
])
para('Each fix was tested locally, committed to Git with a clear description, merged with the latest work of '
     'the team and deployed to the live server by FTPS only after approval. Uploading only the changed files '
     'avoided overwriting the work of other developers.')

# =====================================================================
# CHAPTER THREE
# =====================================================================
chapter('THREE', 'LESSONS LEARNT, CHALLENGES AND SOLUTIONS')
h2('3.1  Relation Between Classroom Theory and Practice')
bullets([
    ('Databases', 'classroom lessons on tables, keys and SQL queries were applied directly when linking payees and suppliers, filtering vouchers by purpose and calculating revenue and VAT totals.'),
    ('Data structures and algorithms', 'arrays, sorting and searching were used daily, for example sorting revenue entries by voucher number and matching products by exact name or SKU.'),
    ('Web programming', 'HTML, CSS and JavaScript knowledge was extended to PHP and React, responsive layouts and PDF generation.'),
    ('Computer networks and security', 'secure file transfer (FTPS), sessions and cookies, and permission checks before deleting files showed how security works in a real system.'),
], numbered=True)

h2('3.2  Lessons Learnt')
bullets([
    'Real users find problems that tests miss, so clear communication with them is part of software engineering.',
    'A small change in a shared file can affect many pages; changes must be tested on all pages that use the file.',
    'Version control (Git) makes teamwork possible and allows mistakes to be traced and reversed.',
    'Always test locally first and deploy only approved, changed files to protect live company data.',
    'Text encoding (UTF-8) and file formats matter; invisible characters can break a whole page.',
    'Good design for phones is as important as for computers because many staff work on the move.',
])

h2('3.3  Challenges Encountered')
bullets([
    ('Large code base', 'the ERP has many modules and companies, and understanding how files connect took time at the beginning.'),
    ('Shared work', 'automatic commits and builds from other developers sometimes brought unfinished changes into my work.'),
    ('Encoding problems', 'files saved in the wrong encoding produced broken characters and blank pages.'),
    ('Differences between local and live servers', 'some features worked locally but failed on the live server because of different folder paths.'),
], numbered=True)

h2('3.4  Solutions Implemented')
bullets([
    'Studying the code structure, reading existing modules and asking the supervisor before large changes.',
    'Reviewing differences (diffs) before every build and deploy, and uploading only my own changed files.',
    'Converting files to UTF-8 and using escape codes for special characters.',
    'Checking every fix on the live server immediately after deployment and correcting path mappings in the deployment script.',
])

# =====================================================================
# CHAPTER FOUR
# =====================================================================
chapter('FOUR', 'CONCLUSION AND RECOMMENDATIONS')
h2('4.1  Conclusion')
para('The field practical training at Ultimate General Trading gave me real experience of how software '
     'supports a business. By working on the company ERP system, I took part in the full software cycle: '
     'understanding user needs, designing and coding features, testing, fixing bugs, using version control '
     'and deploying to a live server. The modules I worked on, including payment vouchers, accounting, sales, '
     'stock and reports, are used every day by the company staff.')
para('The training connected the theory learnt at DIT with practice, improved my technical, communication '
     'and teamwork skills, and is a strong foundation for my future career in computer engineering.')

h2('4.2  Recommendations')
h3('4.2.1  To Dar es Salaam Institute of Technology')
bullets([
    'Include more practical web development using modern frameworks (such as React and Laravel) and real projects.',
    'Teach version control (Git) and team workflows early in the programme.',
    'Increase practical sessions on databases, debugging and software deployment.',
    'Strengthen partnerships with companies so that more students get relevant placements.',
], numbered=True)
h3('4.2.2  To Ultimate General Trading')
bullets([
    'Keep a written guide of the ERP structure and deployment steps to help new developers and students.',
    'Use a testing (staging) server between local and live to catch path and configuration differences.',
    'Disable automatic deployment of unfinished changes and review changes before they reach the live system.',
    'Continue involving field students in real tasks under supervision, as this gives the most valuable experience.',
], numbered=True)

# =====================================================================
# REFERENCES
# =====================================================================
h1('REFERENCES').paragraph_format.page_break_before = True
refs = [
    'Dar es Salaam Institute of Technology. (2024). Field Practical Training Guidelines. Dar es Salaam: DIT.',
    'Elmasri, R. and Navathe, S. B. (2016). Fundamentals of Database Systems (7th ed.). Pearson.',
    'Chacon, S. and Straub, B. (2014). Pro Git (2nd ed.). Apress. Available at: https://git-scm.com/book',
    'The PHP Group. (2026). PHP Manual. Available at: https://www.php.net/manual/',
    'Meta Platforms. (2026). React Documentation. Available at: https://react.dev/',
    'Mozilla. (2026). MDN Web Docs: HTML, CSS and JavaScript. Available at: https://developer.mozilla.org/',
    'Ultimate General Trading. (2026). Ultitech ERP System and Company Profile. Dar es Salaam: UGT.',
]
for r in refs:
    p = para(r)
    p.paragraph_format.left_indent = Inches(0.4)
    p.paragraph_format.first_line_indent = Inches(-0.4)

toc_list = [(min(l, 3), t, k) for l, t, k in TOC_ENTRIES]
fill_slot('toc', toc_list)
fill_slot('lof', [(1, t, k) for t, k in FIG_ENTRIES])
json.dump({'toc': toc_list, 'figs': FIG_ENTRIES}, open(ENTRIES_JSON, 'w'))

doc.save(OUT)
print(OUT)

