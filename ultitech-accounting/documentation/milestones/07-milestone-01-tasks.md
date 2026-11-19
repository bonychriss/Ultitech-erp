# 07 — Milestone 01 Task Breakdown

## Objective

Deliver a tested foundation: project scaffold, tenancy, books, CoA, GL posting/reversal, audit, and basic trial balance validation.

**Do not start Milestone 02 until M01 acceptance tests pass.**

---

## Phase A — Bootstrap (after design approval)

| ID | Task | Done when |
|---|---|---|
| A1 | Scaffold Laravel in `backend/` | `artisan` works with PHP 8.2 |
| A2 | Scaffold React+Vite+TS+Tailwind in `frontend/` | app builds |
| A3 | Create `ultitech_accounting_dev` (+ test DB) | DB reachable from `.env` |
| A4 | Configure Sanctum, CORS, queue=database | login endpoint reachable |
| A5 | Init git repo inside `ultitech-accounting` | `.gitignore` excludes `.env`, `node_modules`, `vendor` |
| A6 | Domain folder skeleton + base TenantContext | structure matches architecture doc |

## Phase B — Schema & seeds

| ID | Task | Done when |
|---|---|---|
| B1 | Migrations 01–13 applied | schema matches doc 03 |
| B2 | Catalog seeders | currencies, frameworks, jurisdictions, types, permissions |
| B3 | Demo company seeder (local) | admin can authenticate |
| B4 | Standard catalog seed (metadata only) | IFRS/IAS rows present |

## Phase C — Auth & company context

| ID | Task | Done when |
|---|---|---|
| C1 | Login/logout/me APIs | token issued/revoked |
| C2 | Company membership + `X-Company-Id` middleware | cross-company denied |
| C3 | RBAC middleware/policies | unauthorized → 403 |
| C4 | Frontend auth + company switcher | session usable |

## Phase D — Masters

| ID | Task | Done when |
|---|---|---|
| D1 | Books CRUD | primary book enforced |
| D2 | Fiscal years/periods | generated months |
| D3 | Period locks | posting blocked when locked |
| D4 | Currencies + exchange rates | rate by date resolution |
| D5 | CoA hierarchy CRUD | parent/child, postable flags |
| D6 | Account mappings + openings | opening JE posts balanced |

## Phase E — Posting engine

| ID | Task | Done when |
|---|---|---|
| E1 | `JournalValidationService` | period/account/currency/balance checks |
| E2 | `JournalPostingService` | transactional post + sequence + audit + outbox |
| E3 | Draft journal APIs | create/update draft |
| E4 | Post + idempotency | duplicate key returns same result |
| E5 | `JournalReversalService` | original marked reversed; balancing reverse JE |
| E6 | Account ledger drill-down | lines queryable |
| E7 | Immutability guards | update/delete posted → rejected |

## Phase F — Reporting sanity (foundation only)

| ID | Task | Done when |
|---|---|---|
| F1 | Trial Balance API | Σ debit = Σ credit for period/book |
| F2 | AccountBalanceService | opening + movements |

## Phase G — Frontend M01 screens

| ID | Task | Done when |
|---|---|---|
| G1 | Design system primitives | layout, table, form, states |
| G2 | CoA screen | tree/list + create |
| G3 | Journal screens | create, post, reverse, detail |
| G4 | Periods / books / rates admin | usable |
| G5 | Basic dashboard shell | KPIs placeholder wired to TB totals if available |

## Phase H — Tests & acceptance

| ID | Test | Pass criteria |
|---|---|---|
| H1 | Debit = credit enforcement | unbalanced post fails |
| H2 | Account validation | inactive/non-postable rejected |
| H3 | Company isolation | user A company 1 ≠ company 2 data |
| H4 | Multi-book separation | book A post invisible in book B TB |
| H5 | Duplicate source prevention | same source_hash/idempotency rejected/replayed safely |
| H6 | Reversal | nets to zero; audit present |
| H7 | Fiscal period restrictions | locked/closed period blocks post |
| H8 | Opening balance validation | openings post only when balanced |
| H9 | Currency conversion | functional amounts use rate date |
| H10 | API permissions | missing ability → 403 |
| H11 | Trial Balance reconciles | totals equal |
| H12 | Traceability | journal → user/time/source |

---

## Milestone 01 exit criteria

- [ ] All Phase H tests green  
- [ ] No ERP files modified  
- [ ] Only `ultitech_accounting_*` DBs used  
- [ ] Design docs remain consistent with implemented schema  
- [ ] Session development report completed  

## Suggested implementation order after approval

`A → B → C → D → E → H(partial) → F → G → H(full)`

UI may lag APIs, but APIs+tests gate completion.
