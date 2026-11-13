import os
from docx import Document
from docx.enum.section import WD_SECTION
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, Inches, RGBColor, Cm

HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = os.path.join(HERE, "shots")
DRAW = os.path.join(HERE, "drawings")
LOG = os.path.join(HERE, "logbook")
OFFICE = os.path.join(HERE, "office")
OUT = os.path.join(HERE, "IPT_Report_Boniface_Chrispin_Magali.docx")

STUDENT = "BONIFACE CHRISPIN MAGALI"
INSTITUTE = "DAR ES SALAAM INSTITUTE OF TECHNOLOGY (DIT)"
COMPANY = "ULTIMATE GENERAL TRADING COMPANY LIMITED"
SUPERVISOR = "Khadija Nuru"
PERIOD = "24th August 2026 \u2013 30th October 2026"

doc = Document()

# ---------- base styles ----------
st = doc.styles["Normal"]
st.font.name = "Times New Roman"
st.element.rPr.rFonts.set(qn("w:eastAsia"), "Times New Roman")
st.font.size = Pt(12)
st.paragraph_format.line_spacing = 1.5
st.paragraph_format.space_after = Pt(6)

for name, size, color in [("Heading 1", 16, "000000"), ("Heading 2", 14, "1F3864"), ("Heading 3", 12, "1F3864")]:
    h = doc.styles[name]
    h.font.name = "Times New Roman"
    h.element.rPr.rFonts.set(qn("w:eastAsia"), "Times New Roman")
    h.element.rPr.rFonts.set(qn("w:ascii"), "Times New Roman")
    h.element.rPr.rFonts.set(qn("w:hAnsi"), "Times New Roman")
    for a in ("w:asciiTheme", "w:hAnsiTheme", "w:eastAsiaTheme", "w:cstheme"):
        h.element.rPr.rFonts.attrib.pop(qn(a), None)
    h.font.size = Pt(size)
    h.font.bold = True
    h.font.color.rgb = RGBColor.from_string(color)
    h.paragraph_format.space_before = Pt(12)
    h.paragraph_format.space_after = Pt(6)
doc.styles["Heading 1"].paragraph_format.alignment = WD_ALIGN_PARAGRAPH.CENTER

cap = doc.styles["Caption"]
cap.font.name = "Times New Roman"
cap.font.size = Pt(10)
cap.font.italic = True
cap.font.bold = False
cap.font.color.rgb = RGBColor(0x1F, 0x38, 0x64)
cap.paragraph_format.space_after = Pt(12)

sec = doc.sections[0]
sec.page_height = Cm(29.7)
sec.page_width = Cm(21.0)
sec.left_margin = Cm(3.0)
sec.right_margin = Cm(2.5)
sec.top_margin = Cm(2.5)
sec.bottom_margin = Cm(2.5)
CONTENT_W = 15.5  # cm


# ---------- helpers ----------
def field(paragraph, instr, placeholder=""):
    r = paragraph.add_run()
    b = OxmlElement("w:fldChar"); b.set(qn("w:fldCharType"), "begin"); r._r.append(b)
    r2 = paragraph.add_run()
    t = OxmlElement("w:instrText"); t.set(qn("xml:space"), "preserve"); t.text = instr; r2._r.append(t)
    r3 = paragraph.add_run()
    s = OxmlElement("w:fldChar"); s.set(qn("w:fldCharType"), "separate"); r3._r.append(s)
    paragraph.add_run(placeholder)
    r4 = paragraph.add_run()
    e = OxmlElement("w:fldChar"); e.set(qn("w:fldCharType"), "end"); r4._r.append(e)


def para(text, bold=False, italic=False, align=WD_ALIGN_PARAGRAPH.JUSTIFY, size=None, space_after=6):
    p = doc.add_paragraph()
    p.alignment = align
    p.paragraph_format.space_after = Pt(space_after)
    r = p.add_run(text)
    r.bold = bold
    r.italic = italic
    if size:
        r.font.size = Pt(size)
    return p


def rich(parts, align=WD_ALIGN_PARAGRAPH.JUSTIFY):
    p = doc.add_paragraph()
    p.alignment = align
    for txt, b in parts:
        r = p.add_run(txt)
        r.bold = b
    return p


def bullets(items, style="List Bullet"):
    for it in items:
        p = doc.add_paragraph(style=style)
        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
        p.paragraph_format.space_after = Pt(2)
        if isinstance(it, tuple):
            r = p.add_run(it[0]); r.bold = True
            p.add_run(it[1])
        else:
            p.add_run(it)


def caption(text, kind="Figure"):
    p = doc.add_paragraph(style="Caption")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.add_run(f"{kind} ")
    field(p, f" SEQ {kind} \\* ARABIC ", "#")
    p.add_run(f": {text}")
    return p


CACHE = os.path.join(HERE, "_jpg_cache")
os.makedirs(CACHE, exist_ok=True)


def small(path, maxdim=1600):
    if os.path.dirname(path) == DRAW:
        return path
    from PIL import Image
    out = os.path.join(CACHE, os.path.splitext(os.path.basename(path))[0] + ".jpg")
    if not os.path.exists(out):
        im = Image.open(path).convert("RGB")
        if im.height > 4000:
            im.thumbnail((1100, 100000))
        else:
            im.thumbnail((maxdim, maxdim))
        im.save(out, quality=85, optimize=True)
    return out


def figure(path, text, width=CONTENT_W):
    path = small(path)
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.keep_with_next = True
    p.paragraph_format.space_after = Pt(0)
    p.add_run().add_picture(path, width=Cm(width))
    caption(text)


def figure_pair(p1, p2, text, w=7.6):
    t = doc.add_table(rows=1, cols=2)
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for cell, pth in zip(t.rows[0].cells, (p1, p2)):
        cp = cell.paragraphs[0]
        cp.alignment = WD_ALIGN_PARAGRAPH.CENTER
        cp.paragraph_format.keep_with_next = True
        cp.add_run().add_picture(small(pth), width=Cm(w))
    caption(text)


def shade(cell, hex_color):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear"); shd.set(qn("w:color"), "auto"); shd.set(qn("w:fill"), hex_color)
    tcPr.append(shd)


def table(headers, rows, widths=None, cap_text=None, font=10):
    if cap_text:
        p = doc.add_paragraph(style="Caption")
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p.paragraph_format.keep_with_next = True
        p.add_run("Table ")
        field(p, " SEQ Table \\* ARABIC ", "#")
        p.add_run(f": {cap_text}")
    t = doc.add_table(rows=1, cols=len(headers))
    t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for i, h in enumerate(headers):
        c = t.rows[0].cells[i]
        c.text = ""
        r = c.paragraphs[0].add_run(h); r.bold = True; r.font.size = Pt(font)
    for ri, row in enumerate(rows):
        cells = t.add_row().cells
        for i, v in enumerate(row):
            cells[i].text = ""
            pp = cells[i].paragraphs[0]
            pp.paragraph_format.line_spacing = 1.15
            pp.paragraph_format.space_after = Pt(0)
            r = pp.add_run(str(v)); r.font.size = Pt(font)
    if widths:
        t.autofit = False
        for i, w in enumerate(widths):
            t.columns[i].width = Cm(w)
        for row in t.rows:
            for i, w in enumerate(widths):
                row.cells[i].width = Cm(w)
    doc.add_paragraph().paragraph_format.space_after = Pt(2)
    return t


def week_table(n, dates, rows):
    table(["Day", "Activities performed", "Hours"],
          [(d, a, "09 hrs") for d, a in rows],
          widths=[2.6, 11.0, 1.9], cap_text=f"Week {n} daily activities ({dates})")


def page_break():
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)


def chapter(title):
    page_break()
    doc.add_heading(title, level=1)


def centered(text, size=12, bold=False, after=6, italic=False):
    return para(text, bold=bold, align=WD_ALIGN_PARAGRAPH.CENTER, size=size, space_after=after, italic=italic)


def S(name):
    return os.path.join(SHOTS, name)


def D(name):
    return os.path.join(DRAW, name)


# =====================================================================
# COVER PAGE
# =====================================================================
centered(INSTITUTE, 15, True, 4)
centered("DEPARTMENT OF COMPUTER STUDIES", 12, True, 18)
p = doc.add_paragraph(); p.alignment = WD_ALIGN_PARAGRAPH.CENTER
p.add_run().add_picture(os.path.join(HERE, "logo.png"), width=Cm(6))
centered("INDUSTRIAL PRACTICAL TRAINING (IPT) REPORT", 16, True, 6)
centered("Conducted at", 12, False, 2, italic=True)
centered(COMPANY, 14, True, 2)
centered("P.O. Box 78004, House No. 14, Atisoko Street, Mikocheni B, Dar es Salaam", 11, False, 18)
centered("Software Development, Web Design and ICT Support on the", 12, False, 0)
centered("UltiTech ERP System and the ultimate.co.tz Website", 12, True, 24)

cover = [("STUDENT NAME", STUDENT), ("REGISTRATION NUMBER", "\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026"),
         ("PROGRAMME", "\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026"),
         ("TRAINING PERIOD", PERIOD + " (10 weeks)"),
         ("INDUSTRIAL SUPERVISOR", SUPERVISOR),
         ("ACADEMIC SUPERVISOR", "\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026")]
t = doc.add_table(rows=0, cols=2)
t.alignment = WD_TABLE_ALIGNMENT.CENTER
for k, v in cover:
    c = t.add_row().cells
    r = c[0].paragraphs[0].add_run(k + ":"); r.bold = True; r.font.size = Pt(11)
    r = c[1].paragraphs[0].add_run(v); r.font.size = Pt(11)
    c[0].width = Cm(6); c[1].width = Cm(9)
doc.add_paragraph()
centered("NOVEMBER 2026", 12, True)

# =====================================================================
# PRELIMINARIES (roman numerals)
# =====================================================================
prelim = doc.add_section(WD_SECTION.NEW_PAGE)
pg = OxmlElement("w:pgNumType"); pg.set(qn("w:fmt"), "lowerRoman"); pg.set(qn("w:start"), "1")
prelim._sectPr.append(pg)
prelim.footer.is_linked_to_previous = False
fp = prelim.footer.paragraphs[0]; fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
field(fp, " PAGE ", "i")

doc.add_heading("DECLARATION", level=1)
para(f"I, {STUDENT.title()}, declare that this Industrial Practical Training report is my own original work and "
     f"that it is a true record of the activities I performed during my training at {COMPANY.title()} from "
     f"{PERIOD}. This report has not been submitted to any other institution for any academic award.")
doc.add_paragraph()
para("Student signature: \u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026        Date: \u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026\u2026", align=WD_ALIGN_PARAGRAPH.LEFT)

doc.add_heading("CERTIFICATION", level=2)
para("This is to certify that the above-named student undertook Industrial Practical Training under our "
     "supervision and that this report reflects the work done during the training period.")
table(["Role", "Name", "Signature & Stamp", "Date"],
      [("Industrial Supervisor", SUPERVISOR, "", ""), ("Academic Supervisor", "", "", "")],
      widths=[4, 4.5, 4.5, 2.5])

page_break()
doc.add_heading("ACKNOWLEDGEMENT", level=1)
para("First, I thank the Almighty God for the gift of life, health and strength that enabled me to complete my "
     "Industrial Practical Training successfully.")
para(f"I am sincerely grateful to the management of {COMPANY.title()} for accepting me as a trainee and giving me "
     "a real working environment in which to apply what I learned in class. Special thanks go to my industrial "
     f"supervisor, {SUPERVISOR}, for her guidance, close follow-up and for signing and stamping my log book every week.")
para("I also thank all staff members of the Sales, Finance, Procurement and ICT departments who shared their "
     "time, answered my questions and tested the modules I developed. Their feedback helped me to improve the "
     "quality of my work.")
para(f"Lastly, I thank {INSTITUTE.title().replace('(Dit)', '(DIT)')} for organising the IPT programme, my academic "
     "supervisor for the guidance given, and my family and friends for their encouragement throughout the training.")

page_break()
doc.add_heading("ABSTRACT", level=1)
para(f"This report presents the activities I carried out during ten weeks of Industrial Practical Training at "
     f"{COMPANY.title()}, a Dar es Salaam company that supplies Personal Protective Equipment (PPE) and safety gear. "
     "The company runs its daily operations on a web-based Enterprise Resource Planning (ERP) system called UltiTech ERP "
     "and sells its products online through the website ultimate.co.tz.")
para("During the first three weeks I was introduced to the organisation, its ICT infrastructure and the ERP system, "
     "learning how the Payment Voucher, Sales, Stock, Accounting, Payroll and other modules exchange information. I set "
     "up a local development environment with XAMPP, studied the PHP and React source code and worked with the MySQL "
     "database using SQL. In weeks four to six I developed and integrated new ERP features: a CRM customer scraper that "
     "collects potential customers from online sources, a Letter Management module with digital signatures, stamps, PDF "
     "generation and sharing, and a centralised notification system covering payment vouchers, receivables, payroll and stock.")
para("In the remaining weeks I produced a 3D model of the office compound, redesigned the layout of the company website "
     "for better presentation and mobile responsiveness, carried out printer troubleshooting and Windows 11 installation, "
     "and compared the ERP accounting module with Odoo and EnterpriZe to recommend improvements. The training greatly "
     "improved my skills in full-stack web development, database management, system testing, UI/UX design, 3D "
     "visualisation and ICT support. Drawings and system screenshots are included.")

page_break()
doc.add_heading("TABLE OF CONTENTS", level=1)
field(doc.add_paragraph(), ' TOC \\o "1-3" \\h \\z \\u ', "Right-click here and choose \u201cUpdate Field\u201d to build the table of contents.")
page_break()
doc.add_heading("LIST OF FIGURES", level=1)
field(doc.add_paragraph(), ' TOC \\h \\z \\c "Figure" ', "Right-click here and choose \u201cUpdate Field\u201d to build the list of figures.")
doc.add_heading("LIST OF TABLES", level=2)
field(doc.add_paragraph(), ' TOC \\h \\z \\c "Table" ', "Right-click here and choose \u201cUpdate Field\u201d to build the list of tables.")

page_break()
doc.add_heading("LIST OF ABBREVIATIONS", level=1)
table(["Abbreviation", "Meaning"], [
    ("API", "Application Programming Interface"), ("CRM", "Customer Relationship Management"),
    ("CSS", "Cascading Style Sheets"), ("DB", "Database"), ("DIT", "Dar es Salaam Institute of Technology"),
    ("ERP", "Enterprise Resource Planning"), ("HTML", "HyperText Markup Language"),
    ("ICT", "Information and Communication Technology"), ("IPT", "Industrial Practical Training"),
    ("JSON", "JavaScript Object Notation"), ("OS", "Operating System"), ("PDF", "Portable Document Format"),
    ("PHP", "PHP: Hypertext Preprocessor"), ("PO", "Purchase Order"), ("PPE", "Personal Protective Equipment"),
    ("PV", "Payment Voucher"), ("SQL", "Structured Query Language"), ("UI / UX", "User Interface / User Experience"),
    ("VAT", "Value Added Tax"), ("XAMPP", "Cross-platform Apache, MariaDB, PHP and Perl package"),
], widths=[4, 11.5])

# =====================================================================
# MAIN BODY (arabic numerals)
# =====================================================================
body = doc.add_section(WD_SECTION.NEW_PAGE)
pg = OxmlElement("w:pgNumType"); pg.set(qn("w:fmt"), "decimal"); pg.set(qn("w:start"), "1")
body._sectPr.append(pg)
body.footer.is_linked_to_previous = False
fp = body.footer.paragraphs[0]; fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
fp.text = ""
field(fp, " PAGE ", "1")
body.header.is_linked_to_previous = False
hp = body.header.paragraphs[0]; hp.alignment = WD_ALIGN_PARAGRAPH.RIGHT
hr = hp.add_run("IPT Report \u2013 Ultimate General Trading Co. Ltd"); hr.italic = True; hr.font.size = Pt(9)
hr.font.color.rgb = RGBColor(0x6B, 0x72, 0x80)

# ---------------- CHAPTER 1 ----------------
doc.add_heading("CHAPTER ONE: INTRODUCTION", level=1)
doc.add_heading("1.1 Background of Industrial Practical Training", level=2)
para("Industrial Practical Training (IPT) is a compulsory component of the academic programme that places students in "
     "real organisations so that they can connect classroom theory with professional practice. It exposes the student to "
     "workplace culture, real systems and real users, and develops technical as well as soft skills such as communication, "
     "teamwork, time management and responsibility.")
doc.add_heading("1.2 Objectives of the Training", level=2)
bullets([
    "To apply knowledge of programming, databases and networking gained in class to solve real business problems.",
    "To understand how an ERP system supports the daily operations of a trading company.",
    "To gain hands-on experience in software development, testing and deployment on a live server.",
    "To develop skills in web design, user-interface improvement and responsive design.",
    "To acquire practical ICT-support skills in hardware, operating systems and printers.",
    "To build professional discipline, teamwork and reporting skills through the weekly log book.",
])
doc.add_heading("1.3 Profile of the Host Organisation", level=2)
para(f"{COMPANY.title()} is a Tanzanian company based at House No. 14, Atisoko Street, Mikocheni B, Dar es Salaam "
     "(P.O. Box 78004). The company\u2019s slogan is \u201cEnsuring Safety, one gear at a time\u201d. It supplies Personal "
     "Protective Equipment and safety products to construction, oil and gas, logistics, manufacturing and other industries, "
     "including head, face and eye, hearing, respiratory, hand, foot and body protection, reflective materials, fire "
     "extinguishers and first-aid kits.")
para("The company runs its internal operations on UltiTech ERP, a cloud ERP that covers payment vouchers, sales, stock, "
     "purchases, accounting, payroll, attendance, CRM, letters and analytics. Customers browse products and request "
     "quotations online through the company website, ultimate.co.tz.")
figure(S("web_home.png"), "Home page of the Ultimate General Trading website (ultimate.co.tz)")
doc.add_heading("1.3.1 Vision and Mission", level=3)
rich([("Vision: ", True), ("To be the leading and most trusted supplier of safety and protective equipment in Tanzania and East Africa.", False)])
rich([("Mission: ", True), ("To protect the workforce by supplying genuine, quality-checked safety gear at fair prices, with fast delivery and expert advice.", False)])
doc.add_heading("1.3.2 Organisation Structure", level=3)
para("The company is led by the Managing Director, assisted by the General Manager who oversees the operational "
     "departments. During the training I was placed in the ICT Department under the supervision of "
     f"{SUPERVISOR}, as shown in the figure below.")
figure(D("d01_org_chart.png"), "Organisation structure of Ultimate General Trading and my placement")
doc.add_heading("1.4 Scope of this Report", level=2)
para("Chapter Two describes the UltiTech ERP system and the company website. Chapter Three gives a week-by-week account of "
     "the activities performed, supported by drawings and screenshots. Chapter Four summarises skills gained and challenges "
     "faced, and Chapter Five gives the conclusion and recommendations. The appendix contains reference photographs of the office site.")
figure(D("d13_gantt.png"), "Timeline of the ten-week Industrial Practical Training")

# ---------------- CHAPTER 2 ----------------
chapter("CHAPTER TWO: THE ULTITECH ERP SYSTEM AND COMPANY WEBSITE")
doc.add_heading("2.1 Overview of UltiTech ERP", level=2)
para("UltiTech ERP is a multi-company, web-based Enterprise Resource Planning system. Each company has its own login page and "
     "its own tenant database, while a control database stores the list of companies and users. Ultimate General Trading "
     "accesses the system through its own company URL, where staff sign in with a username or email and password.")
figure_pair(S("erp_landing.png"), S("erp_login.png"), "UltiTech ERP public landing page (left) and Ultimate General Trading login page (right)")
doc.add_heading("2.2 System Architecture", level=2)
para("The system follows a three-tier architecture. The presentation tier is made of HTML pages and React components "
     "that run in the browser. The application tier runs on Apache with PHP 8.2, using Laravel and Yii2 services for "
     "some modules, and exposes JSON APIs used by the React screens and by the website integration. The data tier is MySQL "
     "/ MariaDB. During development the same stack runs locally on XAMPP, and the live system is hosted on a cPanel server.")
figure(D("d02_architecture.png"), "Three-tier architecture of UltiTech ERP")
doc.add_heading("2.3 ERP Modules", level=2)
para("After logging in, a user lands on the module selection page, which shows only the modules permitted for the "
     "user\u2019s role. The module map that follows groups the modules available to Ultimate General Trading and "
     "highlights the modules I developed or improved during the training.")
figure(S("erp_select.png"), "ERP module selection page for Ultimate General Trading")
figure(D("d03_module_map.png"), "Module map of UltiTech ERP")
table(["Module", "Main purpose"], [
    ("Payment Voucher", "Create, approve, pay and post payment vouchers for general payments and stock purchases."),
    ("Sales", "Quotations, sales orders, invoices, price lists, targets and sales leaderboard."),
    ("Stock Management", "Products, catalogue, suppliers, purchases, shipments, replenishment and stock control."),
    ("Accounting", "Balances, revenue, expenses, purchases, journal entries, VAT control, financial statements, reconciliation."),
    ("Payroll", "Monthly payroll runs, salaries and payslips."),
    ("Attendance", "Clock in / out with digital signature and attendance history."),
    ("CRM", "Customer list, leads and the Client Market lead-discovery tool."),
    ("Letter / Inbox", "Compose official letters on the company letterhead, approve, stamp, share and receive."),
    ("Website", "Push products from the ERP to ultimate.co.tz and monitor visitors, enquiries and quotes."),
    ("Analytics", "Cross-module KPIs, charts and AI-generated business reports."),
    ("Cash Book", "Daily cash-in / cash-out records."),
], widths=[4, 11.5], cap_text="Main modules of UltiTech ERP used at Ultimate General Trading")
doc.add_heading("2.3.1 Payment Voucher Module", level=3)
para("The Payment Voucher (PV) module is the most used module. It records every payment the company makes, shows totals "
     "of pending, approved, rejected, paid and posted vouchers, and moves each voucher through an approval workflow before it is "
     "posted to the accounting ledger.")
figure(S("erp_voucher.png"), "Payment Voucher dashboard")
doc.add_heading("2.3.2 Sales and Stock Modules", level=3)
para("The Sales dashboard tracks monthly sales, quotation value, pending orders, overdue invoices, yearly targets and a "
     "sales leaderboard. The Stock module tracks 427 products, shows stock health, items that need restocking and recent purchases.")
figure(S("erp_sales.png"), "Sales dashboard")
figure(S("erp_stock.png"), "Stock management dashboard")
doc.add_heading("2.3.3 Accounting, Payroll, Attendance and Analytics", level=3)
figure_pair(S("erp_accounting.png"), S("erp_payroll.png"), "Accounting module sections (left) and Payroll runs (right)")
figure_pair(S("erp_attendance.png"), S("erp_analytics.png"), "Attendance clock-in page (left) and Analytics overview dashboard (right)")
figure(S("erp_cashbook.png"), "Cash Book module")

doc.add_heading("2.4 The Company Website (ultimate.co.tz)", level=2)
para("The company website is an online PPE catalogue. Visitors can browse products by category or brand, view product "
     "details, request a price, add items to an enquiry list, request a quotation, track a quotation and contact the company "
     "through a form or WhatsApp. Products are sent to the website from the ERP Website module, and enquiries from the "
     "website flow back into the ERP.")
figure(D("d09_sitemap.png"), "Structure (sitemap) of the ultimate.co.tz website")
figure_pair(S("web_categories.png"), S("web_product.png"), "Website categories page (left) and product detail page (right)")
figure_pair(S("web_quotation.png"), S("web_contact.png"), "Website quotation page (left) and contact page (right)")
figure(S("erp_website.png"), "ERP Website module dashboard used to manage the online shop")

# ---------------- CHAPTER 3 ----------------
chapter("CHAPTER THREE: WEEKLY TRAINING ACTIVITIES")
para("This chapter presents the activities performed each week, as recorded and signed in my log book. Each section has a "
     "table of daily activities followed by a description of the work, the drawings I prepared and screenshots of the systems.")

# Week 1
doc.add_heading("3.1 Week One: Orientation and Introduction to the ERP System", level=2)
week_table(1, "24/08/2026 \u2013 28/08/2026", [
    ("Monday", "Introduction to the organisation and the industrial training environment; familiarisation with the workplace, staff and daily activities."),
    ("Tuesday", "Introduction to the computer systems and IT infrastructure."),
    ("Wednesday", "Introduced to the company ERP system, its purpose and the different modules in it. Observed how employees use the system for operations."),
    ("Thursday", "Reviewed the system modules and their functions and observed how business information is recorded and stored in the system."),
    ("Friday", "Continued familiarisation with the ERP system and its operations and reviewed the activities of the first week."),
])
para(f"During the first week I was introduced to {COMPANY.title()}, a PPE company located at Atisoko Street, Mikocheni. "
     "I became familiar with the workplace, the staff and the ICT-related activities carried out in the organisation. "
     "I was also introduced to the company\u2019s computer systems, IT infrastructure and management procedures.")
para("I was then introduced to the company ERP system and learned its purpose: to record sales, track profit and loss, "
     "create quotations, track product stock and movement, and generate invoices. I learned how the system is used to manage "
     "different business operations and how information is recorded and stored in the database. I also gained an understanding "
     "of basic system maintenance, troubleshooting and data-management activities. This week helped me understand the "
     "organisation\u2019s IT environment and prepared me for the practical work of the following weeks.")

# Week 2
doc.add_heading("3.2 Week Two: ERP Modules, Database and Testing", level=2)
week_table(2, "31/08/2026 \u2013 04/09/2026", [
    ("Monday", "Continued familiarisation with the company ERP system and observed the flow of information between different modules."),
    ("Tuesday", "Registered officially in the system to gain access, and learned how business records are stored in the database tables."),
    ("Wednesday", "Assisted in entering and organising business information in the ERP system and tested data-entry forms and validation procedures."),
    ("Thursday", "Identified minor errors while testing and assisted in making basic improvements to the system interface."),
    ("Friday", "Reviewed the week\u2019s work and documented the activities performed; gained practical experience in ERP operation, database and testing."),
])
para("In the second week I continued gaining practical experience with the ERP system. I studied its main modules: Payment "
     "Voucher, Sales, Attendance, Stock, Warehouse, Letter, Reports and Analytics, Accounting and Payroll. I learned how "
     "information flows between modules; for example, a payment voucher is connected to purchase orders and to the expense "
     "module, while the sales module is connected to the stock module.")
figure(D("d04_data_flow.png"), "Information flow between ERP modules")
para("I was introduced to the basic structure of the database and learned how business records are organised and stored in "
     "tables. I took part in entering, organising and checking business information in the system, such as importing expense "
     "and cash-book Excel records. I tested different forms and pages to validate that data was correctly entered and displayed, "
     "and assisted in identifying minor errors and inconsistencies. These activities developed my practical skills in database "
     "management, data handling, ERP operation and software testing.")

# Week 3
doc.add_heading("3.3 Week Three: Source Code, Local Environment and Interface Improvement", level=2)
week_table(3, "07/09/2026 \u2013 11/09/2026", [
    ("Monday", "Gained access to the system cPanel and software applications and studied the source-code structure of the application."),
    ("Tuesday", "Worked on improving selected ERP system interfaces, tested the updated interfaces and checked for errors."),
    ("Wednesday", "Worked on business data collection and organisation and practised importing and validating records."),
    ("Thursday", "Worked with the ERP database using SQL; reviewed database tables and existing records; tested database operations through the ERP system."),
    ("Friday", "Conducted a final review of changes made during the week and documented the technical activities completed."),
])
para("In the third week I moved from familiarisation to practical development and database work. After being given access "
     "to cPanel, I downloaded the project folder to my computer, used XAMPP as the Apache and SQL server and linked the testing "
     "database. The project then ran on localhost on my computer. I learned the framework used, how the front-end, back-end "
     "and database interact, and the folder structure of the application.")
figure(D("d05_dev_workflow.png"), "Local development and deployment workflow")
para("I took part in improving selected interfaces and tested the changes to make sure they functioned correctly. I used the "
     "React front-end library to improve page forms and layouts, dashboards and view pages. I also used SQL to review, retrieve "
     "and update database records, for example:")
code = doc.add_paragraph()
code.paragraph_format.left_indent = Cm(1)
cr = code.add_run("SELECT voucher_no, payee, amount, status\nFROM payment_vouchers\nWHERE status = 'pending'\nORDER BY created_at DESC;")
cr.font.name = "Consolas"; cr.font.size = Pt(9.5)
para("At the end of the week I tested the implementation changes, corrected minor errors such as a button click that was "
     "not functioning, and verified the consistency of the data. These activities improved my understanding of software "
     "development, database management, data validation and system testing.")

# Week 4
doc.add_heading("3.4 Week Four: CRM Customer Scraper Module", level=2)
week_table(4, "14/09/2026 \u2013 18/09/2026", [
    ("Monday", "Proposed the idea of generating customers through a web scraper; designed and studied the basic structure and workflow of the customer scraper."),
    ("Tuesday", "Started developing the CRM customer scraper module; designed the process for extracting and organising collected information."),
    ("Wednesday", "Worked on extracting relevant customer details and organising them into a standard format."),
    ("Thursday", "Connected the customer scraper with the ERP system."),
    ("Friday", "Made the API payments and tested the integration between the scraper and the ERP system."),
])
para("In the fourth week I worked on a customer data collection and scraping module for the ERP. Its purpose is to help the "
     "sales team find potential customers and business information from online sources and prepare that information for use "
     "within the ERP. I first analysed the requirements and designed the workflow of the scraper. I used the Yii2 "
     "framework for the back-end, React for the front-end, and SQL to store the customer details.")
figure(D("d06_scraper_workflow.png"), "Workflow of the CRM customer scraper module")
para("The scraper retrieves businesses from Google search results and other online sources, including social media, based on "
     "a keyword and location. The collected information is organised into a standard format, exported as a PDF, distributed "
     "equally to each sales person in the system and saved for documentation. I also improved validation so that duplicate "
     "records are not accepted.")
para("I then integrated the collected data with the CRM so that customer records could be generated and managed within the "
     "system, from lead to quotation to invoice. At the end of the week I tested the complete process, corrected errors, "
     "documented the work, purchased the API credits and deployed the changes to cPanel. In addition, I trained the sales "
     "team so that they were all familiar with the module and could use it to generate income.")
figure(S("erp_crm_market.png"), "CRM Client Market home page used to search and import leads")
figure(S("erp_crm.png"), "CRM \u2013 My Customers list where imported leads are managed")

# Week 5
doc.add_heading("3.5 Week Five: Letter Management Module", level=2)
week_table(5, "21/09/2026 \u2013 25/09/2026", [
    ("Monday", "Analysed the requirements for the Letter Management System, designed the structure of the module and modified the company letterhead."),
    ("Tuesday", "Developed the letter-creation module and implemented fields for letter details such as recipient, subject, content and date."),
    ("Wednesday", "Implemented digital signatures for letters and separated the signing functionality."),
    ("Thursday", "Added a digital manager-stamp feature to approved letters in watermark format."),
    ("Friday", "Added the ability to hide letters from public visibility where necessary, PDF download for letters and a share option."),
])
para("In the fifth week I developed a Letter Management module for the ERP. Its purpose is to allow authorised users to "
     "create, manage, approve and share official letters directly through the system instead of using separate word "
     "processors. I started by analysing the requirements and designing the workflow for creating and processing letters "
     "(see the workflow below), then developed the letter-creation interface that lets users write and format letters on the company "
     "letterhead within the ERP.")
figure(D("d07_letter_workflow.png"), "Approval workflow of the Letter Management module")
para("I implemented a digital signing workflow in which both the employee and the manager can digitally sign a letter when "
     "required, and added a digital stamping feature that allows the manager to apply the official company stamp to approved "
     "documents. To improve privacy, I implemented functionality for hiding letters from public visibility. I also added PDF "
     "generation so that completed letters can be saved for record keeping and official use, and a sharing function to make "
     "it easier to distribute completed letters digitally. The letter records are stored in the letter_documents table "
     "shown below.")
figure(D("d15_erd.png"), "Database design for letters and notifications (key columns)")
figure(S("erp_letter_compose.png"), "Composing a letter on the company letterhead")
figure(S("erp_letter.png"), "Letters list showing approved letters with share and privacy actions")
para("At the end of the week I tested the complete workflow from creating a letter through signing, stamping, generating "
     "the PDF, hiding the document when necessary and sharing the completed letter. I then trained all staff members, "
     "including the staff manager, on how to use the module. This work improved my understanding of document management, "
     "digital approval workflows, access control and PDF document generation in web applications.")

# Week 6
doc.add_heading("3.6 Week Six: Notification System Integration and IT Support", level=2)
week_table(6, "28/09/2026 \u2013 02/10/2026", [
    ("Monday", "Reviewed the existing notification system in the ERP and connected it to various modules."),
    ("Tuesday", "Integrated Payment Vouchers into the notification system by adding notifications for activities such as pending actions and approvals."),
    ("Wednesday", "Integrated Receivables and Payroll notifications and tested the notification settings."),
    ("Thursday", "Integrated stock notifications into the notification system and tested notifications generated by stock-related activities."),
    ("Friday", "Assisted with the selection of new computers for staff members based on their work, and with upgrading computers to Windows 11."),
])
para("During the sixth week I worked on improving the notification system of the ERP and also took part in general IT "
     "support. I began by reviewing the existing notification system and improving its integration with the different ERP "
     "modules. I linked it with the Payment Voucher module so that users receive notifications about voucher activities "
     "that require action, such as pending approvals and vouchers that must be posted after payment.")
figure(D("d08_notifications.png"), "Integration of ERP modules with the centralised notification system")
para("I continued by integrating notifications from the Receivables and Payroll modules, so that important events such as "
     "overdue invoices and approved payroll runs appear in the central notification system. I also integrated stock "
     "notifications so that users are alerted about low or out-of-stock items. I tested the notification workflows and the "
     "per-module notification settings to ensure that notifications were generated and displayed correctly.")
figure(S("erp_notifications.png"), "Notifications page showing sales, payment-voucher and account notifications")
para("In addition to software development, I assisted in the selection of new computers for staff members by considering "
     "the requirements of different users and the specifications needed for their daily work. I also upgraded some staff "
     "computers that were running outdated versions of Windows by installing Windows 11 and performing basic system checks "
     "after the upgrade. This week gave me practical experience in ERP integration, system testing, computer selection, "
     "operating-system installation and hardware support.")

# Week 7
doc.add_heading("3.7 Week Seven: 3D Design of the Office Compound", level=2)
week_table(7, "05/10/2026 \u2013 09/10/2026", [
    ("Monday", "Received the assignment for the office 3D design; discussed office requirements, room arrangement and the final expected look."),
    ("Tuesday", "Discussed office requirements, room arrangement and expected outlook, and created the basic structure including walls and floors."),
    ("Wednesday", "Collected reference measurements, observed the office layout and started planning the 3D office model."),
    ("Thursday", "Developed the 3D model and adjusted object sizes for better appearance and accuracy."),
    ("Friday", "Reviewed the design with fellow staff members, collected suggestions and finalised the design."),
])
para("During this week I was assigned to work on the 3D design of the office environment. The main objective was to create a "
     "visual representation of the office that shows the arrangement of the outer office compound, spaces, trees, planted "
     "grass and a general top view of the office.")
para("On the first day I was introduced to the task and given instructions on the expected design. I observed the office layout "
     "and took reference photographs and measurements. After that I started preparing the structure of the office in "
     "3D software, which runs in the browser using the Three.js library.")
figure_pair(os.path.join(OFFICE, "IMG_2129.jpg"), os.path.join(OFFICE, "IMG_2125.jpg"),
            "Reference photographs of the office compound: driveway (left) and container office (right)", w=7.0)
para("As the week continued, I developed the model by creating the walls, floor and partitioned sections and adding realistic "
     "features. Attention was given to the proper arrangement and positioning of objects so that the office would look "
     "organised and practical. Later in the week I improved the design by applying colours, textures and lighting effects, "
     "which made the model more attractive and presentable, and adjusted some objects and spaces after review.")
figure(S("3d_office.png"), "Finished 3D model of the office compound (perspective view)")
figure_pair(S("3d_office_top.png"), S("3d_office_gate.png"), "3D model top view (left) and view from the gate along the driveway (right)")
para("At the end of the week I finalised the 3D office design and prepared it for presentation. I learned the importance "
     "of accuracy, creativity and planning when developing a 3D model. The same 3D skills were later applied to an "
     "interactive PPE fitting room in which customers can try safety gear on a 3D model.")
figure(S("3d_showcase.png"), "Interactive 3D PPE fitting room showing Ultimate General Trading products")

# Week 8
doc.add_heading("3.8 Week Eight: Company Website Layout Improvement", level=2)
week_table(8, "12/10/2026 \u2013 16/10/2026", [
    ("Monday", "Reviewed the existing company website and identified areas requiring layout improvement."),
    ("Tuesday", "Modified the homepage layout by improving section arrangements, product displays and navigation elements."),
    ("Wednesday", "Improved product cards, images, buttons and spacing to create a cleaner and more organised appearance."),
    ("Thursday", "Adjusted the website layout for mobile devices and responsiveness."),
    ("Friday", "Tested the modified pages, corrected layout inconsistencies, reviewed the overall user interface and deployed to the live cPanel."),
])
para("During this week I worked on modifying and improving the layout of the company website. The main objective was to "
     "enhance the website\u2019s appearance, organisation and user experience. I started by reviewing the existing website and "
     "identifying areas that needed improvement, focusing on the homepage, product display sections, navigation menus and the "
     "general arrangement of content. I sketched the target layout for desktop and mobile before making changes.")
figure(D("d10_wireframe.png"), "Wireframe of the redesigned homepage for desktop and mobile")
para("As the week continued, I made several modifications to the layout, including adjusting product cards, image sizes, "
     "buttons and spacing and aligning the different elements, aiming to make the website more attractive, organised and easier "
     "for customers to navigate. Towards the end of the week I tested the modified pages, including responsiveness on mobile "
     "devices, made the necessary corrections and deployed the changes to the live server.")
figure(S("web_home_full.png"), "Redesigned homepage of ultimate.co.tz (full page)", width=11)
figure_pair(S("web_mobile.png"), S("web_brands.png"), "Mobile view of the homepage (left) and brands page (right)", w=5.0)
para("Through this activity I gained practical experience in website design, user-interface improvement, responsive web "
     "development and website testing. I also learned the importance of maintaining a consistent and user-friendly layout "
     "in an e-commerce website.")

# Week 9
doc.add_heading("3.9 Week Nine: Printer Troubleshooting and Windows Installation", level=2)
week_table(9, "19/10/2026 \u2013 23/10/2026", [
    ("Monday", "Inspected office printers and identified common printing problems (poor print quality and connection errors)."),
    ("Tuesday", "Performed printer troubleshooting: checked ink levels, cleaned print heads and tested printing performance."),
    ("Wednesday", "Resolved printer connectivity issues and configured printer drivers and settings."),
    ("Thursday", "Performed Windows operating-system installation and configured the necessary system settings and drivers."),
    ("Friday", "Installed essential computer applications, checked system functionality and tested printer connectivity."),
])
para("During this week I was involved in troubleshooting office printer problems and installing the Windows operating system "
     "on a computer. The main objective was to ensure that office equipment and computer systems were functioning properly.")
para("At the beginning of the week I inspected the office printers to identify problems affecting their performance. The "
     "issues investigated included poor print quality caused by low ink in the ink tanks, printer connectivity errors and "
     "general printing difficulties. I then performed printer maintenance, including print-head cleaning and test printing. "
     "I also checked the connection between printers and computers and configured the printer drivers together with the "
     "network settings to improve communication between the devices.")
figure(D("d11_network.png"), "Office network and printer setup")
para("Later in the week I installed the Windows operating system. This involved preparing the installation media, "
     "configuring system settings and installing the necessary device drivers to ensure proper hardware functionality, "
     "following the procedure shown below.")
figure(D("d12_windows_flow.png"), "Windows 11 installation and configuration procedure")
para("This week\u2019s activities improved my technical skills in diagnosing and resolving hardware and software problems, "
     "and gave me a better understanding of the importance of regular computer and printer maintenance in keeping office "
     "operations efficient.")

# Week 10
doc.add_heading("3.10 Week Ten: Study and Evaluation of the ERP Accounting Module", level=2)
week_table(10, "26/10/2026 \u2013 30/10/2026", [
    ("Monday", "Studied the existing ERP accounting module to understand its structure, features and functionality."),
    ("Tuesday", "Reviewed the Odoo ERP accounting module to understand its accounting process and available features."),
    ("Wednesday", "Studied the EnterpriZe accounting system and compared its features with the company\u2019s existing ERP system."),
    ("Thursday", "Identified missing accounting features and areas requiring improvement based on the comparison with Odoo and EnterpriZe."),
    ("Friday", "Conducted an overall review of the ERP system, checked the different modules and documented recommended improvements."),
])
para("During my final week I focused on studying and evaluating the accounting module of the company\u2019s ERP system. "
     "The main objective was to understand its functionality, identify missing features and recommend improvements. At the "
     "beginning of the week I examined how financial transactions, accounts and accounting records are managed within the "
     "system.")
figure(S("erp_accounting.png"), "Sections of the UltiTech ERP accounting module that were evaluated")
para("I then studied two existing ERP platforms, Odoo and EnterpriZe, which served as reference systems. I explored their "
     "accounting features, user interfaces and financial-management processes to understand how they handle different "
     "accounting operations, and compared their features with those available in our ERP system.")
table(["Accounting feature", "UltiTech ERP", "Odoo", "EnterpriZe"], [
    ("Balances / chart of accounts", "Available", "Available", "Available"),
    ("Revenue and expense recording", "Available", "Available", "Available"),
    ("Purchases linked to stock", "Available", "Available", "Available"),
    ("Journal entries", "Available", "Available", "Available"),
    ("VAT control", "Available", "Available", "Available"),
    ("Financial statements (P&L, balance sheet)", "Available", "Available", "Available"),
    ("Bank reconciliation", "Available", "Automatic bank feeds", "Available"),
    ("Budgets and variance", "Separate Budgets module", "Available", "Available"),
    ("Multi-currency (TZS / USD)", "Partial", "Full", "Available"),
    ("Fixed-asset register and depreciation", "Recommended", "Available", "Available"),
    ("Recurring entries and automatic reminders", "Recommended", "Available", "Partial"),
    ("Customer / supplier aged reports", "Partial (Statement module)", "Available", "Available"),
], widths=[5.6, 3.6, 3.2, 3.1], cap_text="Comparison of accounting features: UltiTech ERP, Odoo and EnterpriZe", font=9.5)
para("This comparison helped me identify missing functionalities and areas that need improvement to make the accounting "
     "module more complete and efficient. Towards the end of the week I conducted an overall review of the ERP system, "
     "examining the different modules, their functionality and how they interact with one another, defined the areas requiring "
     "further development and prepared recommendations for future improvement (Section 5.2).")
para("Through these activities I gained practical knowledge of ERP accounting systems, software feature analysis and system "
     "evaluation, and learned the importance of comparing existing software solutions when developing and improving a system.")

# ---------------- CHAPTER 4 ----------------
chapter("CHAPTER FOUR: SKILLS GAINED AND CHALLENGES")
doc.add_heading("4.1 Skills and Knowledge Gained", level=2)
figure(D("d14_time_split.png"), "Approximate distribution of the 450 training hours by area", width=11)
table(["Area", "Skills gained"], [
    ("Software development", "PHP back-end development, React front-end components, Yii2 framework, REST/JSON APIs, Git-based code organisation."),
    ("Database management", "Designing tables, writing SQL queries, importing and validating Excel data, maintaining data consistency."),
    ("System integration", "Connecting new modules (CRM scraper, letters, notifications) to existing ERP modules and to external APIs."),
    ("Testing and deployment", "Local testing with XAMPP, debugging, user-acceptance testing with staff, deploying to cPanel."),
    ("Web design", "Responsive layouts, product-card design, navigation and UI consistency for an e-commerce site."),
    ("3D visualisation", "Building a 3D model of a real site from photos and measurements with textures and lighting."),
    ("ICT support", "Computer selection, Windows 11 installation, driver configuration, printer maintenance and networking."),
    ("System analysis", "Requirement analysis, workflow design and feature comparison with Odoo and EnterpriZe."),
    ("Soft skills", "Communication with users, training staff, teamwork, time management and weekly reporting."),
], widths=[4, 11.5], cap_text="Skills gained during the training")
doc.add_heading("4.2 Challenges Faced and How They Were Solved", level=2)
table(["Challenge", "Solution"], [
    ("Understanding a large existing codebase with many modules.", "Studied the folder structure module by module, ran the system locally and traced requests from the page to the database."),
    ("Risk of breaking the live system while testing changes.", "Worked on a local copy with a testing database and deployed only after testing and supervisor approval."),
    ("Duplicate and incomplete records returned by the scraper.", "Added validation and duplicate checks before saving leads, and a standard record format."),
    ("Cost and limits of the external search API.", "Purchased API credits only after testing and limited searches to targeted keywords and locations."),
    ("Staff not familiar with new modules.", "Prepared demonstrations and trained the sales team and other staff on the scraper and letter modules."),
    ("Old computers not meeting Windows 11 requirements.", "Checked hardware requirements first and recommended suitable new computers where upgrades were not possible."),
    ("Rendering a realistic 3D scene on ordinary office computers.", "Optimised textures and object sizes and provided preset camera views."),
], widths=[7, 8.5], cap_text="Challenges and solutions")

# ---------------- CHAPTER 5 ----------------
chapter("CHAPTER FIVE: CONCLUSION AND RECOMMENDATIONS")
doc.add_heading("5.1 Conclusion", level=2)
para(f"The ten weeks of Industrial Practical Training at {COMPANY.title()} were a valuable experience. I was able to apply "
     "classroom knowledge to real business problems and to contribute features that staff now use every day: the CRM "
     "customer scraper, the Letter Management module and the improved notification system in UltiTech ERP, as well as a "
     "cleaner, mobile-friendly layout for ultimate.co.tz. I also gained practical ICT-support experience and learned how "
     "to analyse and compare business software. The objectives of the training were achieved, and the experience has "
     "prepared me well for my final studies and future career in software engineering and ICT.")
doc.add_heading("5.2 Recommendations", level=2)
doc.add_heading("5.2.1 To the Host Organisation", level=3)
bullets([
    "Add a fixed-asset register with automatic depreciation, recurring journal entries and full multi-currency support to the accounting module.",
    "Provide regular user training and a written user guide whenever a new ERP module is released.",
    "Set up a separate staging server so that new features can be tested by users before going live.",
    "Introduce a scheduled maintenance plan for printers and computers and keep an inventory of ICT equipment.",
    "Continue developing online features such as the 3D PPE fitting room to attract more website customers.",
])
doc.add_heading("5.2.2 To the Institute", level=3)
bullets([
    "Include more practical work on modern frameworks (React, Laravel, Yii2) and on deploying applications to real servers.",
    "Increase the coverage of ERP concepts and business processes in the curriculum.",
    "Visit students at their placements more often to give guidance during the training.",
])
doc.add_heading("5.2.3 To Future IPT Students", level=3)
bullets([
    "Be curious and ask questions; staff are willing to help those who show interest.",
    "Record activities in the log book every day so that the weekly report is accurate.",
    "Always test changes locally before applying them to a live system.",
])

doc.add_heading("REFERENCES", level=2)
for ref in [
    "Odoo S.A. (2026). Odoo Accounting documentation. https://www.odoo.com/documentation",
    "The PHP Group. (2026). PHP Manual. https://www.php.net/manual",
    "Meta Platforms. (2026). React documentation. https://react.dev",
    "Yii Software. (2026). The Definitive Guide to Yii 2.0. https://www.yiiframework.com/doc/guide/2.0",
    "Oracle Corporation. (2026). MySQL 8.0 Reference Manual. https://dev.mysql.com/doc",
    "Three.js authors. (2026). Three.js documentation. https://threejs.org/docs",
    "Microsoft. (2026). Windows 11 specifications and system requirements. https://www.microsoft.com/windows",
    "Ultimate General Trading Co. Ltd. (2026). Company website. https://ultimate.co.tz",
]:
    p = doc.add_paragraph(ref, style="List Number")
    p.paragraph_format.space_after = Pt(2)

# ---------------- APPENDICES ----------------
chapter("APPENDICES")
doc.add_heading("Appendix A: Office Reference Photographs", level=2)
office = sorted(f for f in os.listdir(OFFICE) if f.endswith(".jpg"))
picks = [f for f in office if f not in ("IMG_2125.jpg", "IMG_2129.jpg")][:6]
t = doc.add_table(rows=3, cols=2)
t.alignment = WD_TABLE_ALIGNMENT.CENTER
for k, f in enumerate(picks):
    cp = t.rows[k // 2].cells[k % 2].paragraphs[0]
    cp.alignment = WD_ALIGN_PARAGRAPH.CENTER
    cp.add_run().add_picture(os.path.join(OFFICE, f), height=Cm(5.4))
caption("Site photographs used as reference for the 3D office model")

doc.save(OUT)
print("saved", OUT)
