# Sales Document Layout Settings

Brief for AI / designers: how invoice & quotation layouts work on the Ultimate Sales Settings page, so you can review current options and suggest new ones.

## Live page

| Item | Value |
|------|--------|
| URL | `http://localhost/public_html/ultimate/sales/settings?module=sales` |
| Tab | **Document layout** (Ultimate) |
| Roadmaster equivalent | Truck Layout / Spare Layout tabs on the same settings app |

On Ultimate, users pick **one** print layout for quotations and invoices via `sales_settings.spare_part_layout`.

---

## What this page does

1. Shows a **grid of layout cards** (thumbnail = live HTML preview).
2. Clicking a card opens a **two-column modal**:
   - Left: full document preview (scaled to fit)
   - Right: name, description, status, **Use this layout** / **Close**
3. Below the grid: **Footer content** fields (payment, terms, validity, closing note, return policy) that print on documents.
4. Choosing a layout saves immediately to company settings (per company).

Layouts are **not** chosen per invoice — they are company-wide defaults.

---

## Ultimate layout catalog (IDs)

Stored in `sales_settings.spare_part_layout` (integer).

### Built-in Ultimate styles

| ID | Label | Description | Render path |
|----|--------|-------------|-------------|
| **1** | Standard | Classic Ultimate sheet: yellow product table header, photo/product/description columns | `modules/sales/invoices/view_sheet_inner.php` (and order/quote sheet equivalents) |
| **2** | Classic | Boxed table borders, structured sections | `layouts/ultimate/` ? Roadmaster spare inner with Ultimate branding |
| **3** | Minimalist | Teal header, white typography, clear grid | Same branded spare path, `$layout === 3` styles |
| **4** | Formal | Soft header band, refined ruled product table | Same branded spare path, `$layout === 4` styles |

### Shared library layouts (from `sales/invoice-app` prototypes)

IDs **11–15** are available to Ultimate, Roadmaster spare, and Roadmaster truck. They must **not** collide with 1–4.

| ID | Label | Visual character | PHP variant |
|----|--------|------------------|-------------|
| **11** | Purple Classic | Purple `#5b4ea3` table header + TOTAL DUE bar; 3-column footer | `layouts/library/variants/purple-classic.php` |
| **12** | Purple Brand Name | Purple pills, rounded total, black/purple footer wave | `purple-brand.php` |
| **13** | Navy & Orange Swoosh | Frame artwork + corner swooshes (`bg-layout3.webp`) | `navy-orange.php` |
| **14** | Pixel Mart Red & Black | Black title band, red angles (`bg-layout4.webp`) | `pixel-mart.php` |
| **15** | Yellow Bar Classic | Yellow bar art, dark table header, yellow total (`bg-layout5.webp`) | `yellow-bar.php` |

Catalog source: `modules/sales/settings/includes/settings-lib.php` ? `sales_settings_layout_catalog()` / `sales_settings_library_layout_catalog_entries()`.

Prototype React designs (reference only): `sales/invoice-app/src/layouts/`.

---

## Architecture (integration points)

```
Sales Settings UI (React)
  modules/sales/settings/frontend/src/pages/SalesSettingsPage.jsx
        ?
        ?? Catalog from init API: sales_settings_layout_catalog()
        ?? Card thumbs: layout-preview.php?type=spare&layout=N&card=1
        ?? Modal: layout-preview.php?type=spare&layout=N&modal=1
                ?
                ?
sales_branded_document_layout_inner_path()  (modules/sales/functions.php)
        ?
        ?? IDs 11–15 ? layouts/library/invoice-layout-inner.php
        ?? Ultimate ID 1 ? standard view_sheet_inner.php
        ?? Ultimate ID 2–4 ? layouts/ultimate/spare-invoice-layout-inner.php
                              ? roadmaster/spare-invoice-layout-inner.php
```

### Key files

| Role | Path |
|------|------|
| Settings UI | `modules/sales/settings/frontend/src/pages/SalesSettingsPage.jsx` |
| Settings CSS | `modules/sales/settings/frontend/src/sales-settings.css` |
| Catalog + preview HTML | `modules/sales/settings/includes/settings-lib.php` |
| Preview API | `modules/sales/settings/api/layout-preview.php` |
| Path resolver | `modules/sales/functions.php` ? `sales_branded_document_layout_inner_path()` |
| Library dispatcher | `modules/sales/layouts/library/invoice-layout-inner.php` |
| Library variants | `modules/sales/layouts/library/variants/*.php` |
| Library assets | `modules/sales/layouts/library/assets/bg-layout{3,4,5}.webp` |
| Roadmaster/Ultimate branded | `modules/sales/layouts/roadmaster/spare-invoice-layout-inner.php` |
| Standard Ultimate sheet | `modules/sales/invoices/view_sheet_inner.php` |

After UI changes: `npm run build` in `modules/sales/settings/frontend/`.

---

## Document data the layouts must support

Layouts render real ERP fields (preview uses sample data if settings footers are empty):

**Company:** name, logo, address, phone, email, TIN/VRN, tagline  

**Customer:** name, address, TIN, VRN  

**Document:** number, date, due date, salesperson, currency  

**Lines:** product name, description/code, qty, unit price, line total (optional image)  

**Totals:** subtotal, tax, discount, total / balance due  

**Footer (settings):**  
`spare_payment_details`, `spare_terms`, `spare_validity`, `spare_thanks_note`, `spare_return_policy`, `document_footer_message`  
(Ultimate Document layout tab uses the `spare_*` field prefix.)

Print size target: **A4** (`210mm` wide), PDF/print friendly.

---

## UI behaviour notes (current)

- Cards: no outer “big box”; selection ring is on the **thumbnail** only.
- Card thumbs: live iframe previews with skeleton loading until ready; scrollbars hidden.
- Modal: product-style quick view (preview left, meta + actions right).
- Preview sample footers: filled automatically when company footer fields are blank (`sales_settings_with_preview_footer_defaults()`).

---

## How to suggest / add a new layout

When proposing a new layout, specify:

1. **Name** and one-line description  
2. **Visual direction** (colors, header treatment, table style, footer structure)  
3. **Whether it needs frame artwork** (like 13–15) or is pure CSS/HTML (like 11–12 / Standard)  
4. **Recommended next ID** (use **16+** for new library layouts; do not reuse 1–4 or 11–15)  
5. **Where it should appear** (Ultimate only vs all companies: spare + truck + ultimate)

### Implementation checklist for a new library layout (ID ? 16)

1. Add entry in `sales_settings_library_layout_catalog_entries()`.  
2. Add PHP variant under `modules/sales/layouts/library/variants/`.  
3. Register it in `$variantMap` inside `invoice-layout-inner.php`.  
4. Copy any background assets into `layouts/library/assets/`.  
5. Extend `sales_is_library_document_layout_id()` range if needed (currently `11–15`).  
6. Optionally add a React prototype under `sales/invoice-app/src/layouts/` for design iteration.  
7. Rebuild settings frontend only if UI labels/helpers change.

Style variants that only tweak colors on the existing spare PHP file (like Formal) can instead add a new `$layout === N` branch in `spare-invoice-layout-inner.php` and a catalog entry under `ultimate` with ID **5+** (not overlapping library 11+).

---

## Prompt you can paste to another AI

```text
You are helping design invoice/quotation print layouts for an ERP Sales module.

Read: modules/sales/settings/DOCUMENT-LAYOUT-README.md

Context:
- Ultimate company settings page: /ultimate/sales/settings?module=sales ? Document layout tab
- Users pick one company-wide layout; preview cards + modal show live HTML
- Existing Ultimate IDs: 1 Standard, 2 Classic, 3 Minimalist, 4 Formal
- Shared library IDs: 11–15 (Purple Classic, Purple Brand Name, Navy & Orange, Pixel Mart, Yellow Bar)
- New library layouts should use IDs 16+
- Must support A4, company branding, line items, tax totals, and footer fields (payment/terms/thanks)

Please:
1) Critique the current layout set for clarity and brand fit
2) Suggest 2–3 new layout concepts (name, description, visual rules, colors)
3) For each, say whether it should be a CSS-only PHP variant or needs background artwork
4) Give a concrete implementation plan against the files listed in the README
```

---

## Related prototype app

Standalone design playground (not wired into production render by itself):

- Path: `sales/invoice-app/`
- Run: `npm install && npm run dev` inside that folder  
- Layouts 1–5 there map to production library IDs **11–15**.
