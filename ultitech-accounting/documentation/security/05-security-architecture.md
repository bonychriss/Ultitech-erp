# 05 — Security Architecture (Milestone 01)

## Principles

1. Financial integrity over convenience  
2. Deny by default  
3. Tenant isolation is mandatory on every company-owned query  
4. Posted journals are immutable  
5. Secrets never committed  
6. No unrestricted financial endpoints  

## Authentication

- Laravel Sanctum personal access tokens for API
- Passwords hashed with `bcrypt`/`argon2` (Laravel default)
- Tokens revocable on logout
- Optional future: SPA cookie auth behind same-site reverse proxy — design allows either; M01 ships token auth for clarity on XAMPP

## Authorization

- RBAC via `acc_roles` + `acc_permissions` + `acc_user_company_access`
- Laravel Policies for resource ownership
- Permission codes namespaced by module (`journals.post`, `coa.manage`, …)
- Company administrators cannot escalate outside their company unless granted platform role (future)

## Tenant isolation controls

| Layer | Control |
|---|---|
| HTTP | `X-Company-Id` validated against membership |
| Application | `TenantContext` service |
| Eloquent | global `BelongsToCompany` scope |
| SQL | FKs + composite indexes; no cross-company joins without explicit consolidation service (later) |
| Tests | isolation feature tests in M01 acceptance |

## Data protection

- Prepared statements via Eloquent/Query Builder (SQL injection mitigation)
- Form Request + Zod validation (server is authoritative)
- Mass-assignment guarded (`$fillable` / `$guarded`)
- File uploads (journal attachments): MIME allow-list, size caps, stored outside web root / private disk
- Error responses sanitized

## CSRF / XSS / transport

- API token clients: CSRF N/A for pure Bearer usage
- If cookie SPA mode enabled later: Sanctum CSRF cookie flow
- Frontend escapes React defaults; no `dangerouslySetInnerHTML` for financial data
- Production requires HTTPS (M09); local HTTP acceptable on XAMPP only

## Audit & non-repudiation

- `acc_audit_logs` for auth events, master changes, posts, reversals, period locks
- Journals store `posted_by`, `posted_at`, source hash
- Reversals linked, never silent edits

## Idempotency & duplicate prevention

- `Idempotency-Key` header persisted in `acc_idempotency_keys`
- Source hash unique per company/book for automated/source postings
- Double-submit posting returns prior result

## Rate limiting & abuse

- Auth and posting throttles
- Lockout-friendly login responses (generic error messages)

## Secrets & config

- `.env` local only; `.env.example` with placeholders
- DB user for app should be least-privilege (local root OK for XAMPP dev only)
- No ERP production credentials

## Backup (design note for M01 ops)

- Daily mysqldump of `ultitech_accounting_dev` recommended locally
- Restoration test procedure documented in infrastructure notes during M09; mention in M01 README checklist

## Threats explicitly considered

| Threat | Mitigation |
|---|---|
| Cross-tenant data leak | scopes + membership + tests |
| Tampering posted JE | immutability + reversal only |
| Replay posting | idempotency keys / source hash |
| Privilege escalation | RBAC + policies |
| Injection | ORM + validation |
| Secret leakage | env discipline |

## Out of scope for M01 (tracked)

- WAF, SIEM, hardware HSM
- Full penetration test (M09)
- TRA/government integrations
