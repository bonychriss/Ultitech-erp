import os
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.patches import FancyBboxPatch, FancyArrowPatch, Circle

OUT = os.path.join(os.path.dirname(__file__), "drawings")
os.makedirs(OUT, exist_ok=True)

YELLOW = "#F5C518"
DARK = "#1F2937"
BLUE = "#2563EB"
LBLUE = "#DBEAFE"
GREEN = "#16A34A"
LGREEN = "#DCFCE7"
LYEL = "#FEF3C7"
GREY = "#F3F4F6"
PURPLE = "#7C3AED"
LPURP = "#EDE9FE"
RED = "#DC2626"
LRED = "#FEE2E2"

plt.rcParams["font.family"] = "DejaVu Sans"


def canvas(w, h, title=None):
    fig, ax = plt.subplots(figsize=(w, h), dpi=200)
    ax.set_xlim(0, w * 10)
    ax.set_ylim(0, h * 10)
    ax.axis("off")
    if title:
        ax.text(w * 5, h * 10 - 3, title, ha="center", va="top", fontsize=13, weight="bold", color=DARK)
    return fig, ax


def box(ax, x, y, w, h, text, fc=LBLUE, ec=BLUE, fs=9, bold=False, tc=DARK, r=1.2):
    ax.add_patch(FancyBboxPatch((x - w / 2, y - h / 2), w, h, boxstyle=f"round,pad=0.2,rounding_size={r}",
                                fc=fc, ec=ec, lw=1.4))
    ax.text(x, y, text, ha="center", va="center", fontsize=fs, color=tc, weight="bold" if bold else "normal",
            wrap=True, linespacing=1.25)


def diamond(ax, x, y, w, h, text, fc=LYEL, ec="#D97706", fs=8.5):
    ax.add_patch(plt.Polygon([(x, y + h / 2), (x + w / 2, y), (x, y - h / 2), (x - w / 2, y)], fc=fc, ec=ec, lw=1.4))
    ax.text(x, y, text, ha="center", va="center", fontsize=fs, color=DARK, linespacing=1.2)


def arrow(ax, x1, y1, x2, y2, text=None, color="#374151", style="-|>", ls="-", fs=7.5, rad=0.0, toff=(0, 1.2)):
    ax.add_patch(FancyArrowPatch((x1, y1), (x2, y2), arrowstyle=style, mutation_scale=12, color=color, lw=1.3,
                                 linestyle=ls, connectionstyle=f"arc3,rad={rad}"))
    if text:
        ax.text((x1 + x2) / 2 + toff[0], (y1 + y2) / 2 + toff[1], text, ha="center", va="bottom", fontsize=fs,
                color="#4B5563", style="italic",
                bbox=dict(fc="white", ec="none", pad=0.6, alpha=0.9))


def save(fig, name):
    fig.savefig(os.path.join(OUT, name), bbox_inches="tight", facecolor="white", pad_inches=0.15)
    plt.close(fig)
    print("saved", name)


# 1. Organisation structure
def org_chart():
    fig, ax = canvas(12, 6.2, "Ultimate General Trading Co. Ltd - Organisation Structure")
    box(ax, 60, 50, 26, 6, "Managing Director", fc=YELLOW, ec=DARK, bold=True, fs=10)
    box(ax, 60, 38, 26, 6, "General Manager /\nAdministration", fc=LYEL, ec="#D97706", bold=True)
    arrow(ax, 60, 47, 60, 41.2, style="-")
    depts = [("Sales &\nMarketing", 10), ("Finance &\nAccounts", 30), ("Procurement\n& Stores", 50),
             ("ICT\nDepartment", 70), ("Logistics &\nDrivers", 90), ("Human\nResources", 110)]
    ax.plot([10, 110], [29, 29], color="#374151", lw=1.3)
    ax.plot([60, 60], [35, 29], color="#374151", lw=1.3)
    for name, x in depts:
        hl = "ICT" in name
        box(ax, x, 22, 17, 7, name, fc=LGREEN if hl else LBLUE, ec=GREEN if hl else BLUE, bold=hl)
        ax.plot([x, x], [29, 25.7], color="#374151", lw=1.3)
    box(ax, 70, 8, 26, 6.5, "IPT Student (ICT)\nBoniface C. Magali", fc="white", ec=GREEN, fs=8.5)
    arrow(ax, 70, 18.3, 70, 11.5, style="-|>", ls="--", color=GREEN)
    box(ax, 30, 8, 30, 6.5, "Industrial Supervisor\nKhadija Nuru", fc="white", ec="#D97706", fs=8.5)
    arrow(ax, 45.3, 8, 56.7, 8, "supervises", color="#D97706")
    save(fig, "d01_org_chart.png")


# 2. ERP architecture
def architecture():
    fig, ax = canvas(12, 7.2, "UltiTech ERP - Three-Tier System Architecture")
    # tiers
    for y, label, fc in [(56, "PRESENTATION TIER", "#EFF6FF"), (35, "APPLICATION TIER", "#F0FDF4"), (13, "DATA TIER", "#FFFBEB")]:
        ax.add_patch(FancyBboxPatch((3, y - 8.5), 114, 17, boxstyle="round,pad=0.2,rounding_size=1.5", fc=fc, ec="#D1D5DB", lw=1))
        ax.text(5, y + 6.8, label, fontsize=8, weight="bold", color="#6B7280")
    box(ax, 18, 55, 22, 8, "Web Browser\n(Staff, Desktop)", fs=8.5)
    box(ax, 45, 55, 22, 8, "Mobile Browser /\nPWA", fs=8.5)
    box(ax, 75, 55, 24, 8, "React UI Components\n(dashboards, forms)", fc=LPURP, ec=PURPLE, fs=8.5)
    box(ax, 103, 55, 20, 8, "Customers\nultimate.co.tz", fc=LYEL, ec="#D97706", fs=8.5)
    box(ax, 18, 34, 22, 9, "Apache Web Server\n(XAMPP local /\ncPanel live)", fc=LGREEN, ec=GREEN, fs=8)
    box(ax, 45, 34, 22, 9, "PHP 8.2 Core\n+ Laravel / Yii2\nservices", fc=LGREEN, ec=GREEN, fs=8)
    box(ax, 75, 34, 24, 9, "Module Logic\nPV, Sales, Stock, CRM,\nLetters, Notifications", fc=LGREEN, ec=GREEN, fs=8)
    box(ax, 103, 34, 20, 9, "REST / JSON APIs\n(website sync,\nRapidAPI search)", fc=LGREEN, ec=GREEN, fs=8)
    box(ax, 30, 12, 30, 8, "MySQL / MariaDB\n(per-company tenant DB)", fc=LYEL, ec="#D97706", fs=8.5)
    box(ax, 70, 12, 26, 8, "Control-plane DB\n(companies, users)", fc=LYEL, ec="#D97706", fs=8.5)
    box(ax, 103, 12, 20, 8, "File Storage\n(PDFs, uploads)", fc=LYEL, ec="#D97706", fs=8.5)
    for x in (18, 45):
        arrow(ax, x, 50.8, x, 38.7, "HTTPS" if x == 18 else None, style="<|-|>")
    arrow(ax, 75, 50.8, 75, 38.7, style="<|-|>")
    arrow(ax, 103, 50.8, 103, 38.7, style="<|-|>")
    arrow(ax, 29.2, 34, 33.8, 34, style="-|>")
    arrow(ax, 56.2, 34, 62.8, 34, style="-|>")
    arrow(ax, 87.2, 34, 92.8, 34, style="<|-|>")
    arrow(ax, 45, 29.3, 32, 16.2, "PDO / SQL", style="<|-|>")
    arrow(ax, 75, 29.3, 70, 16.2, style="<|-|>")
    arrow(ax, 103, 29.3, 103, 16.2, style="<|-|>")
    save(fig, "d02_architecture.png")


# 3. Module map
def module_map():
    import math
    fig, ax = canvas(13, 9, "UltiTech ERP - Module Map (Ultimate General Trading)")
    cx, cy = 65, 43
    ax.add_patch(Circle((cx, cy), 11, fc=YELLOW, ec=DARK, lw=1.8))
    ax.text(cx, cy, "UltiTech\nERP Core\n(users, roles,\ncompanies)", ha="center", va="center", fontsize=9, weight="bold", color=DARK)
    mods = [("Payment\nVouchers", LBLUE, BLUE), ("Sales &\nQuotations", LGREEN, GREEN), ("Stock &\nWarehouses", LGREEN, GREEN),
            ("Purchases", LBLUE, BLUE), ("Accounting", LBLUE, BLUE), ("Payroll", LBLUE, BLUE),
            ("Attendance", LPURP, PURPLE), ("CRM &\nClient Market", LRED, RED), ("Letters &\nStamp", LRED, RED),
            ("Notifications", LRED, RED), ("Website\nSync", LYEL, "#D97706"), ("Analytics &\nReports", LPURP, PURPLE),
            ("Cash Book", LBLUE, BLUE), ("Delivery &\nDispatch", LGREEN, GREEN)]
    n = len(mods)
    for i, (m, fc, ec) in enumerate(mods):
        a = math.pi / 2 - 2 * math.pi * i / n
        x, y = cx + 46 * math.cos(a), cy + 33 * math.sin(a)
        ax.plot([cx + 11 * math.cos(a), x - 7 * math.cos(a)], [cy + 11 * math.sin(a), y - 4.5 * math.sin(a)], color="#9CA3AF", lw=1.1)
        box(ax, x, y, 15, 7, m, fc=fc, ec=ec, fs=8)
    ax.add_patch(FancyBboxPatch((2, 1), 30, 6, boxstyle="round,pad=0.2", fc="white", ec=RED))
    ax.text(17, 4, "Red = modules I developed /\nimproved during IPT", ha="center", va="center", fontsize=7.5, color=RED)
    save(fig, "d03_module_map.png")


# 4. Data flow between modules (week 2)
def data_flow():
    fig, ax = canvas(12, 5.6, "Information Flow Between ERP Modules")
    box(ax, 12, 40, 18, 8, "Purchase Order\n(Procurement)")
    box(ax, 42, 40, 20, 8, "Payment Voucher\n(Stock Purchase)", fc=LYEL, ec="#D97706")
    box(ax, 74, 40, 18, 8, "Approval\n(Manager / Finance)", fc=LPURP, ec=PURPLE)
    box(ax, 104, 40, 18, 8, "Accounting\nLedger / Purchases")
    box(ax, 42, 18, 20, 8, "Payment Voucher\n(General Payment)", fc=LYEL, ec="#D97706")
    box(ax, 104, 18, 18, 8, "Expenses\nDesk")
    box(ax, 12, 18, 18, 8, "Stock\n(goods received)", fc=LGREEN, ec=GREEN)
    box(ax, 12, 4, 18, 6, "Sales Orders /\nInvoices", fc=LGREEN, ec=GREEN)
    arrow(ax, 21.2, 40, 31.8, 40, "linked PO")
    arrow(ax, 52.2, 40, 64.8, 40, "submit")
    arrow(ax, 83.2, 40, 94.8, 40, "post")
    arrow(ax, 52.2, 18, 94.8, 18, "approved & posted")
    arrow(ax, 74, 35.8, 52, 22.2, style="<|-", ls="--")
    arrow(ax, 12, 35.8, 12, 22.2, "receive")
    arrow(ax, 12, 13.8, 12, 7.2, "issued on sale", toff=(0, -1.8))
    save(fig, "d04_data_flow.png")


# 5. Development & deployment workflow (week 3)
def dev_workflow():
    fig, ax = canvas(12, 4.6, "Local Development and Deployment Workflow")
    steps = [("cPanel\n(live server)", LYEL, "#D97706"), ("Download\nsource code\n& DB backup", LBLUE, BLUE),
             ("XAMPP on PC\nApache + MySQL", LGREEN, GREEN), ("Import test\ndatabase\n(phpMyAdmin)", LGREEN, GREEN),
             ("Edit code\n(PHP / React)\nin IDE", LPURP, PURPLE), ("Test on\nlocalhost", LPURP, PURPLE),
             ("Deploy to\nlive cPanel", LYEL, "#D97706")]
    xs = [9 + i * 17 for i in range(len(steps))]
    for (t, fc, ec), x in zip(steps, xs):
        box(ax, x, 25, 14, 12, t, fc=fc, ec=ec, fs=8)
    for a, b in zip(xs, xs[1:]):
        arrow(ax, a + 7.2, 25, b - 7.2, 25)
    arrow(ax, xs[5], 18.6, xs[4], 18.6, "fix errors & retest", rad=-0.6, color=RED, toff=(0, -9))
    save(fig, "d05_dev_workflow.png")


# 6. CRM scraper workflow (week 4)
def scraper():
    fig, ax = canvas(12, 8, "CRM Customer Scraper Module - Workflow")
    box(ax, 15, 70, 22, 7, "Sales user enters\nkeyword + location", fc=LPURP, ec=PURPLE)
    box(ax, 15, 56, 22, 7, "React front-end\n(Client Market search)", fc=LPURP, ec=PURPLE)
    box(ax, 15, 42, 22, 7, "Yii2 back-end API\n(PHP controller)", fc=LGREEN, ec=GREEN)
    box(ax, 50, 42, 24, 7, "External search API\n(Google results via RapidAPI,\nsocial media pages)", fc=LYEL, ec="#D97706", fs=8)
    box(ax, 50, 28, 24, 7, "Extract details\nname, phone, email,\naddress, website", fc=LGREEN, ec=GREEN, fs=8)
    diamond(ax, 50, 14, 22, 10, "Duplicate or\ninvalid?")
    box(ax, 85, 14, 20, 7, "Discard /\nmerge record", fc=LRED, ec=RED)
    box(ax, 85, 30, 22, 7, "Save to MySQL\n(standard format)", fc=LYEL, ec="#D97706")
    box(ax, 85, 46, 22, 7, "Distribute leads equally\nto sales persons", fc=LBLUE, ec=BLUE, fs=8)
    box(ax, 85, 62, 22, 7, "Generate PDF lead list\n& save for records", fc=LBLUE, ec=BLUE, fs=8)
    box(ax, 50, 70, 24, 7, "CRM: My Customers \u2192\nQuotation \u2192 Invoice", fc=LGREEN, ec=GREEN, fs=8)
    arrow(ax, 15, 66.3, 15, 59.7)
    arrow(ax, 15, 52.3, 15, 45.7)
    arrow(ax, 26.2, 42, 37.8, 42, "request")
    arrow(ax, 50, 38.3, 50, 31.7, "JSON")
    arrow(ax, 50, 24.3, 50, 19.2)
    arrow(ax, 61, 14, 74.8, 14, "yes")
    arrow(ax, 56, 17.5, 74, 28, "no", toff=(0, -3))
    arrow(ax, 85, 33.7, 85, 42.3)
    arrow(ax, 85, 49.7, 85, 58.3)
    arrow(ax, 73.8, 62, 62.2, 68, "import to CRM", toff=(-2, -4))
    save(fig, "d06_scraper_workflow.png")


# 7. Letter management workflow (week 5)
def letters():
    fig, ax = canvas(12, 6.6, "Letter Management Module - Approval Workflow")
    box(ax, 12, 52, 18, 7, "Employee\ncomposes letter\n(company letterhead)", fs=8)
    box(ax, 38, 52, 18, 7, "Fill recipient,\nsubject, content,\ndate", fs=8)
    box(ax, 64, 52, 18, 7, "Employee\ndigital signature", fc=LPURP, ec=PURPLE, fs=8)
    diamond(ax, 92, 52, 22, 11, "Manager\nreview")
    box(ax, 92, 30, 20, 7, "Manager signs +\napplies digital stamp\n(watermark)", fc=LGREEN, ec=GREEN, fs=8)
    box(ax, 64, 30, 18, 7, "Status:\nAPPROVED", fc=LGREEN, ec=GREEN, bold=True, fs=8)
    box(ax, 38, 30, 18, 7, "Generate PDF", fc=LYEL, ec="#D97706", fs=8.5)
    box(ax, 12, 30, 18, 7, "Share link /\ndownload", fc=LYEL, ec="#D97706", fs=8.5)
    box(ax, 38, 10, 22, 7, "Hide from public\nvisibility (optional)", fc=GREY, ec="#6B7280", fs=8)
    box(ax, 92, 10, 20, 7, "Returned for\ncorrection", fc=LRED, ec=RED, fs=8)
    arrow(ax, 21.2, 52, 28.8, 52)
    arrow(ax, 47.2, 52, 54.8, 52)
    arrow(ax, 73.2, 52, 81, 52, "submit")
    arrow(ax, 92, 46.5, 92, 33.7, "approve")
    arrow(ax, 81.8, 30, 73.2, 30)
    arrow(ax, 54.8, 30, 47.2, 30)
    arrow(ax, 28.8, 30, 21.2, 30)
    arrow(ax, 38, 26.3, 38, 13.7, ls="--")
    ax.plot([103, 108, 108], [52, 52, 10], color="#374151", lw=1.3)
    ax.text(109, 31, "reject", fontsize=7.5, color="#4B5563", style="italic", va="center")
    arrow(ax, 108, 10, 102.2, 10)
    ax.plot([82, 78, 78, 1, 1], [10, 10, 3, 3, 52], color=RED, lw=1.3, ls="--")
    arrow(ax, 1, 52, 2.8, 52, color=RED)
    ax.text(40, 3.6, "edit & resubmit", fontsize=7.5, color=RED, style="italic", ha="center", va="bottom",
            bbox=dict(fc="white", ec="none", pad=0.4))
    save(fig, "d07_letter_workflow.png")


# 8. Notification architecture (week 6)
def notifications():
    fig, ax = canvas(12, 6.4, "Centralised Notification System - Integration")
    srcs = [("Payment Vouchers\n(pending, approved, paid)", 54), ("Receivables\n(overdue invoices)", 42),
            ("Payroll\n(run approved)", 30), ("Stock\n(low / out of stock)", 18), ("Accounts\n(new team member)", 6)]
    for t, y in srcs:
        box(ax, 15, y, 24, 8, t, fs=8)
        arrow(ax, 27.2, y, 44.8, 32)
    box(ax, 57, 32, 24, 14, "Notification Service\ncreateNotification()\n+ user preferences\n(settings per module)", fc=YELLOW, ec=DARK, fs=8.5, bold=True)
    box(ax, 57, 10, 24, 8, "notifications table\n(MySQL)", fc=LYEL, ec="#D97706", fs=8.5)
    arrow(ax, 57, 24.8, 57, 14.2, "store", style="<|-|>")
    outs = [("Bell badge &\npop-up toast", 50), ("Notifications page\n(today / earlier)", 32), ("Email / mobile\nalerts", 14)]
    for t, y in outs:
        box(ax, 100, y, 22, 8, t, fc=LGREEN, ec=GREEN, fs=8)
        arrow(ax, 69.2, 32, 88.8, y)
    save(fig, "d08_notifications.png")


# 9. Website sitemap (week 8)
def sitemap():
    fig, ax = canvas(12, 5.6, "ultimate.co.tz - Website Structure (Sitemap)")
    box(ax, 60, 43, 22, 6, "Home Page\nultimate.co.tz", fc=YELLOW, ec=DARK, bold=True)
    pages = ["All\nProducts", "Categories", "Brands", "News /\nBlog", "Quotation\n(Enquiry)", "Track\nOrder", "Contact\nUs", "Staff\nLogin"]
    xs = [8 + i * 14.8 for i in range(len(pages))]
    ax.plot([xs[0], xs[-1]], [33, 33], color="#374151", lw=1.2)
    ax.plot([60, 60], [39.5, 33], color="#374151", lw=1.2)
    for p, x in zip(pages, xs):
        box(ax, x, 26, 12, 7, p, fs=8)
        ax.plot([x, x], [33, 29.7], color="#374151", lw=1.2)
    box(ax, xs[1], 10, 16, 7, "Head / Eye / Hand /\nFoot / Body protection", fc=LGREEN, ec=GREEN, fs=7)
    box(ax, 45, 10, 16, 7, "Product Detail\n(Request price,\nAdd to enquiry)", fc=LGREEN, ec=GREEN, fs=7)
    box(ax, xs[6], 10, 12, 7, "WhatsApp\nquick order", fc=LGREEN, ec=GREEN, fs=7)
    box(ax, xs[7], 10, 12, 7, "UltiTech ERP\n(staff)", fc=LPURP, ec=PURPLE, fs=7)
    arrow(ax, xs[1], 22.3, xs[1], 13.7)
    arrow(ax, xs[0], 22.3, 41, 13.7)
    arrow(ax, xs[6], 22.3, xs[6], 13.7)
    arrow(ax, xs[7], 22.3, xs[7], 13.7)
    save(fig, "d09_sitemap.png")


# 10. Responsive layout wireframe (week 8)
def wireframe():
    fig, ax = canvas(12, 6.4, "Homepage Layout Redesign - Desktop vs Mobile Wireframe")
    # desktop
    ax.add_patch(FancyBboxPatch((3, 4), 72, 50, boxstyle="round,pad=0.3", fc="white", ec=DARK, lw=1.6))
    ax.text(39, 56, "Desktop (1440 px)", ha="center", fontsize=9, weight="bold")
    rows = [(49, 3, "Top bar: phone | WhatsApp | email | social | Request a quote", GREY),
            (44, 5, "Logo | Search bar | Login | Registration | Staff", LYEL),
            (39.5, 3, "Nav: Store categories | Products | Categories | Brands | News | Contact | Enquiry", DARK),
            (35, 3.5, "Hot-sale ticker (scrolling product strip)", LRED),
            (26, 12, "Hero banner slider (HEAD PROTECTIONS) + trust badges", YELLOW)]
    for y, h, t, c in rows:
        ax.add_patch(plt.Rectangle((5, y - h / 2), 68, h, fc=c, ec="#9CA3AF"))
        ax.text(39, y, t, ha="center", va="center", fontsize=6.5, color="white" if c == DARK else DARK)
    for i in range(6):
        ax.add_patch(plt.Rectangle((5 + i * 11.4, 6), 10.6, 12, fc=LBLUE, ec=BLUE))
        ax.text(10.3 + i * 11.4, 12, "Product\ncard", ha="center", va="center", fontsize=6)
    # mobile
    ax.add_patch(FancyBboxPatch((88, 4), 26, 50, boxstyle="round,pad=0.6", fc="white", ec=DARK, lw=1.6))
    ax.text(101, 56, "Mobile (390 px)", ha="center", fontsize=9, weight="bold")
    mrows = [(49, 4, "Menu | Logo | Search | Staff", LYEL), (43.5, 3.5, "Hot-sale ticker", LRED),
             (35, 10, "Hero banner", YELLOW)]
    for y, h, t, c in mrows:
        ax.add_patch(plt.Rectangle((90, y - h / 2), 22, h, fc=c, ec="#9CA3AF"))
        ax.text(101, y, t, ha="center", va="center", fontsize=6.5)
    for i in range(2):
        for j in range(2):
            ax.add_patch(plt.Rectangle((90 + i * 11.2, 10.5 + j * 9.5), 10.6, 8.6, fc=LBLUE, ec=BLUE))
            ax.text(95.3 + i * 11.2, 14.8 + j * 9.5, "Card", ha="center", va="center", fontsize=6)
    ax.add_patch(plt.Rectangle((90, 5), 22, 4, fc=DARK, ec=DARK))
    ax.text(101, 7, "Home | Categories | Enquiry | Account", ha="center", va="center", fontsize=5.2, color="white")
    arrow(ax, 76, 30, 87, 30, "responsive\nCSS grid", toff=(0, 1.5))
    save(fig, "d10_wireframe.png")


# 11. Office network / printer (week 9)
def network():
    fig, ax = canvas(12, 6.2, "Office Network and Printer Setup")
    box(ax, 60, 52, 22, 7, "Internet (ISP)", fc=GREY, ec="#6B7280")
    box(ax, 60, 39, 22, 7, "Router / Firewall\n(DHCP)", fc=LYEL, ec="#D97706")
    box(ax, 60, 26, 22, 7, "Network Switch /\nWi-Fi Access Point", fc=LYEL, ec="#D97706")
    pcs = [("Sales PC\n(Win 11)", 12), ("Finance PC\n(Win 11)", 32), ("Procurement\nPC (Win 11)", 52), ("IT Laptop\n(Win 11)", 72)]
    for t, x in pcs:
        box(ax, x, 8, 16, 7, t, fs=8)
        arrow(ax, 60, 22.3, x, 11.7, style="<|-|>")
    box(ax, 100, 8, 20, 7, "Office Printer\n(ink-tank, network)", fc=LGREEN, ec=GREEN, fs=8)
    arrow(ax, 71.2, 26, 92, 11.7, "TCP/IP + driver", style="<|-|>")
    arrow(ax, 60, 48.3, 60, 42.7, style="<|-|>")
    arrow(ax, 60, 35.3, 60, 29.7, style="<|-|>")
    box(ax, 100, 30, 22, 13, "Troubleshooting done:\n\u2022 check ink levels\n\u2022 clean print heads\n\u2022 reinstall drivers\n\u2022 fix IP / connection", fc="white", ec=GREEN, fs=7.5)
    save(fig, "d11_network.png")


# 12. Windows installation flow (week 9)
def windows_flow():
    fig, ax = canvas(12, 3.6, "Windows 11 Installation and Configuration Procedure")
    steps = ["Back up\nuser data", "Check HW\nrequirements\n(TPM 2.0, RAM)", "Create\nbootable USB", "Boot & install\nWindows 11",
             "Install drivers\n& updates", "Install apps\n(Office, browser,\nantivirus)", "Connect printer\n& test"]
    xs = [9 + i * 17 for i in range(len(steps))]
    for t, x in zip(steps, xs):
        box(ax, x, 17, 14, 12, t, fc=LBLUE if x < 60 else LGREEN, ec=BLUE if x < 60 else GREEN, fs=7.8)
    for a, b in zip(xs, xs[1:]):
        arrow(ax, a + 7.2, 17, b - 7.2, 17)
    save(fig, "d12_windows_flow.png")


# 13. Gantt timeline
def gantt():
    weeks = [("Orientation & ERP familiarisation", 1, 1), ("ERP modules, database & testing", 2, 2),
             ("Source code, XAMPP, React UI work", 3, 3), ("CRM customer scraper module", 4, 4),
             ("Letter management module", 5, 5), ("Notification system + IT support", 6, 6),
             ("3D office design", 7, 7), ("Company website layout (ultimate.co.tz)", 8, 8),
             ("Printer troubleshooting & Windows install", 9, 9), ("Accounting module study (Odoo, Enterprize)", 10, 10)]
    colors = [BLUE, BLUE, BLUE, RED, RED, RED, PURPLE, "#D97706", GREEN, BLUE]
    fig, ax = plt.subplots(figsize=(11, 4.8), dpi=200)
    for i, ((t, s, e), c) in enumerate(zip(weeks, colors)):
        ax.barh(i, e - s + 1, left=s - 0.5, color=c, alpha=0.85, edgecolor="white", height=0.62)
        ax.text(s + 0.62, i, t, va="center", fontsize=8, color=DARK)
    ax.set_yticks(range(len(weeks)))
    ax.set_yticklabels([f"Week {i + 1}" for i in range(len(weeks))], fontsize=8)
    ax.invert_yaxis()
    dates = ["24 Aug", "31 Aug", "7 Sep", "14 Sep", "21 Sep", "28 Sep", "5 Oct", "12 Oct", "19 Oct", "26 Oct"]
    ax.set_xticks(range(1, 11))
    ax.set_xticklabels(dates, fontsize=7.5)
    ax.set_xlim(0.5, 14.5)
    ax.grid(axis="x", ls=":", alpha=0.6)
    for sp in ("top", "right"):
        ax.spines[sp].set_visible(False)
    ax.set_title("Industrial Practical Training Timeline (24 Aug - 30 Oct 2026)", fontsize=12, weight="bold", color=DARK)
    save(fig, "d13_gantt.png")


# 14. Hours / skills distribution
def skills_pie():
    fig, ax = plt.subplots(figsize=(7, 4.6), dpi=200)
    labels = ["ERP software\ndevelopment", "Database &\ndata handling", "Web design\n(ultimate.co.tz)", "IT support &\nhardware", "3D design", "System analysis\n(accounting)"]
    vals = [4, 2, 1, 1.5, 1, 0.5]
    cols = [RED, BLUE, "#D97706", GREEN, PURPLE, "#6B7280"]
    ax.pie(vals, labels=labels, colors=cols, autopct=lambda p: f"{p:.0f}%", startangle=90,
           wedgeprops=dict(width=0.45, edgecolor="white"), textprops=dict(fontsize=8))
    ax.set_title("Distribution of Training Time by Area (450 hours)", fontsize=11, weight="bold", color=DARK)
    save(fig, "d14_time_split.png")


# 15. Letter / notification ER diagram
def erd():
    fig, ax = canvas(12, 6.6, "Database Design - Letters and Notifications (key columns)")

    def table(x, y, name, cols, fc=LBLUE, ec=BLUE):
        h = 3.2 * (len(cols) + 1)
        ax.add_patch(plt.Rectangle((x, y - h), 24, h, fc="white", ec=ec, lw=1.4))
        ax.add_patch(plt.Rectangle((x, y - 3.2), 24, 3.2, fc=ec, ec=ec))
        ax.text(x + 12, y - 1.6, name, ha="center", va="center", color="white", fontsize=8.5, weight="bold")
        for i, c in enumerate(cols):
            ax.text(x + 1, y - 3.2 * (i + 1.5), c, va="center", fontsize=7, color=DARK, family="DejaVu Sans Mono")
        return h

    table(3, 60, "users", ["PK id", "username", "full_name", "role", "department"])
    table(44, 60, "letter_documents", ["PK id (uuid)", "   company_id", "FK author_id", "   title", "   form_json", "   visibility",
                                       "   status", "FK approver_id", "   approver_signature_url", "   approved_at", "   created_at"], ec=RED)
    table(3, 36, "notifications", ["PK id", "FK user_id", "   audience", "   title", "   message", "   type", "FK voucher_id", "   is_read", "   created_at"], ec=PURPLE)
    table(88, 36, "payment_vouchers", ["PK id", "   voucher_no", "   payee", "   amount", "   status", "   purpose"], ec="#D97706")
    arrow(ax, 27, 53.6, 44, 53.6, "author 1 : N", style="-")
    arrow(ax, 27, 47.2, 44, 34.4, "approver 1 : N", style="-", toff=(0, -2))
    arrow(ax, 12, 40.4, 12, 36, "1 : N", style="-", toff=(4, -1.5))
    arrow(ax, 27, 15, 88, 15, "voucher 1 : N", style="-", toff=(0, 0))
    save(fig, "d15_erd.png")


if __name__ == "__main__":
    for f in (org_chart, architecture, module_map, data_flow, dev_workflow, scraper, letters, notifications,
              sitemap, wireframe, network, windows_flow, gantt, skills_pie, erd):
        f()
