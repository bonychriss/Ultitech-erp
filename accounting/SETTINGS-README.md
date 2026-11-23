# Accounting Settings

Page: `/<company>/accounting/settings?module=accounting`
Source: [settings.php](./settings.php), helpers in [../includes/accounting_settings.php](../includes/accounting_settings.php)
Access: logged-in admins and finance users only (others get 403).

The page controls **which general ledger (GL) accounts the system posts to**, and shows a few related settings that are edited elsewhere.

## Tabs

| Tab | Content |
|---|---|
| **GL Mapping** (dropdown) | Sales, Stock, Receipts, Payables, System: one account picker per posting role |
| **Periods & tax** (dropdown) | Period, Currency, Exchange rates, VAT / Tax. Each is its own section; all share one Save changes. |
| **Manual** | Plain-language guide to the period settings (read-only, no form fields). Deep link: `#as-manual`. |

The GL Mapping dropdown also links to the Chart of Accounts. The active tab is remembered in the URL hash and `sessionStorage`.

## Manual tab

An interactive help page for non-accountants. The side cards are hidden on this tab. Layout, top to bottom:

1. **Hero banner**: "Your accounting year in 3 settings".
2. **A year at a glance**: a 4-step timeline (Start, Every month, Year ends, Then lock).
3. **The settings**: three cards (Financial Year, Year-End Close, Books closed through) with a "when" tag, an example and an **Open setting** button that jumps to the real setting in Periods & tax.
4. **Closing the year: your checklist**: three tick-able steps with a progress bar and **Start over**. Ticks are saved in the browser (`localStorage` key `asManualSteps`) and are only a personal reminder; they do not change any data.
5. **Order warning**: close the year *before* locking it.
6. **Common questions**: expandable answers (undo a close, backdated entries, when Save is needed, reopening a lock).

The markup is the `#as-manual` section in `settings.php` and the styles are the `.as-man*` rules. Keep the wording simple when editing it. If the close or lock rules change, update this tab and the Periods & tax table below.

## GL Mapping

| Group | Role | Used for | Required |
|---|---|---|---|
| Sales | Sales Revenue | Net amount credited on each invoice | Yes |
| Sales | Accounts Receivable | Invoice total debited; customer receipts credited | Yes |
| Sales | VAT / Output Tax Payable | VAT on invoices | Yes |
| Stock | Cost of Goods Sold | Cost of stock sold | Yes |
| Stock | Inventory | Cost of stock sold (credit); stock purchases and supplier payments | Yes |
| Receipts | Unassigned Receipts (Undeposited Funds) | Customer payments saved without a bank / cash account | Yes |
| Payables | Accounts Payable | Money owed to suppliers | Optional |
| System | Opening Balance Equity (default 3900) | Opening balances of bank and cash accounts | Optional |
| System | Foreign Exchange Gain / Loss (5990) | Differences on transfers between currencies | Optional |
| System | Transfers Clearing (1950) | Transfers between your own accounts | Optional |
| System | Suspense (1999) | Transactions that cannot be classified yet | Optional |
| System | Retained Earnings (3200) | Receives each year's profit or loss at year-end close | Optional |

### Optional roles and "System default"
Optional roles have a **System default: code - name (created when first needed)** choice. Saving it clears the mapping. The ledger then uses an account already tagged for that role. Otherwise it creates one the first time it is needed, with the default code, or `<code>9` if the code is taken. It never picks an unrelated account, for example Loans Payable at 2100 is not treated as Accounts Payable.

### Required roles
A required role must have an account selected. The list is filtered to suitable accounts, and the page warns when no dedicated account exists and all accounts of that type are listed. An account that no longer qualifies is shown as "not suitable, please change" and blocks saving.

### Auto-detected
If nothing is saved yet, the page proposes an account found from the chart of accounts and tags it **Auto-detected**. It is only locked in once you press **Save changes**.

### Save behaviour
The Save button is enabled when something changed, or when auto-detected values need confirming. It is disabled while a required field is empty or an unsuitable account is selected. The server re-validates every choice and lists any role it could not save.

## How posting uses the mapping
The invoice posting and the GL sync engine (`includes/gl/gl_bridge.php`) both resolve accounts through `gl_system_account()` in `includes/gl/gl_core.php`. Order of resolution:

1. The account chosen in GL Mapping.
2. An account already tagged with the role's system key.
3. An untagged account with the default code **and** a fitting name and type.
4. A new system account is created.

So receipts, stock purchases and supplier payments follow the same choices as invoices.

## Periods & tax (editable)

One form with its own **Reset** and **Save changes** buttons. It saves independently of GL Mapping.

| Setting | Stored in | Rules |
|---|---|---|
| Books closed through | `gl_period_locks` | Can only move forward; a closed period cannot be reopened. The browser asks for confirmation before saving a new date. Entries dated on or before it are moved to the first open day. |
| Financial year start | GL meta `fiscal_year_start_month` (default 1) | Month 1-12. Helpers `gl_fiscal_year_start_month()` and `gl_fiscal_year_bounds()` in `gl_core.php`. |
| Year-End Close | `gl_journal_entries` (`entry_kind = 'closing'`) | Button for the latest finished year. Posts one entry on the last day that zeroes income and expense accounts into Retained Earnings. Blocked if the books are already closed through that date, or if the year is already closed. The Profit & Loss report ignores closing entries (`gl_year_end.php`). Reverse it from the Journal to undo. |
| Base currency | GL meta `base_currency` (default TZS) | Locked once the ledger has any entries. Flag dropdown. |
| Auto exchange rate | GL meta `fx_mode` | Switch on to fetch BOT rates (AI assistant fallback) at most once a day; **Add Rates** lets you enter or delete rates inline (`fx_ajax` in `settings.php`). |
| VAT registration (VRN) | Company setting `company_vat` | Free text. |
| Prices include VAT | Company setting `tax_calculation_mode` (`inclusive` / `exclusive`) | One of the two values is required. |

A **Current settings** card replaces the invoice journal card on this tab.

The VAT and tax-mode values are the same company settings that Sales settings and Company settings use.

## Field help (info icons)
Each GL Mapping field has an info icon next to its label. Hovering over it, or focusing it with the keyboard, shows the explanation as a tooltip. The text comes from `help` in `accounting_gl_roles()`.

## Storage

Mappings are saved per company in `company_settings` under keys starting `accounting.` (via `accounting_settings_get/set`).

Journal profiles are also saved per company under `accounting.journals`. They hold journal metadata and behavior preferences (type, currency, prefix, linked module, status, and flags). Profile flags do not override GL account mappings; use the GL Mapping tab for posting accounts. The journal itself is now stamped on each new ledger entry (`gl_journal_entries.journal_code`, derived from `source_type` by `accounting_journal_code_for_source`); older entries stay NULL and the Journal list derives their code for display. Entry numbers remain one global sequence.

The journal create/edit screen uses a compact, row-based settings layout with Reset and Save changes actions.

The Journals tab in Accounting Settings uses the same section header, `.as-row` rows and right-hand aside as the other tabs. Each journal is a compact icon row (type icon, name, code, default star, status dot) with icon-only Edit and Activate/Deactivate buttons (tooltips carry the labels); deactivation posts `toggle_journal` to `settings.php`. New journal opens `journal-create.php`. The standalone `journal-configuration.php` page still exists.

The standalone journal list keeps only Journal, Code, Type, Status, Default, and Actions; mappings are managed separately in GL Mapping.

## Not yet available

Accounts for bank charges, discounts and input VAT: the ledger does not post to them yet, so they are not offered as settings.

Editing a journal from the Journals tab (right card) only changes Name, Status, Default, Allow manual entries and Description (ccounting_journal_update_basic). Code, type, prefix and linked module are shown read-only; posting, reversal and auto-numbering are always on; the default journal cannot be deactivated or unset (pick another default instead). General, Sales and Purchase can never be deactivated (`accounting_journal_is_core`). The New button opens an inline create form in the same card (`create_journal`). The details card also shows the posted-entry count and a change log (`accounting.journals_log`, last 300 lines, who/when/what) per journal.
