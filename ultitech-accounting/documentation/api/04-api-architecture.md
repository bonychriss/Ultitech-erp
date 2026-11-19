# 04 — API Architecture (Milestone 01)

## Base

- Prefix: `/api/v1`
- Format: JSON
- Auth: Laravel Sanctum bearer tokens (SPA + token clients)
- Company context: header `X-Company-Id` (UUID or numeric id) **required** on tenant routes after login
- Book context: header `X-Book-Id` optional; defaults to company primary book when omitted
- Idempotency: header `Idempotency-Key` on posting endpoints
- Errors: RFC7807-style problem JSON `{ "message", "errors", "code" }` — no stack traces in production

## Cross-cutting middleware

1. `auth:sanctum`
2. `EnsureCompanyAccess` — membership + active status
3. `SetTenantContext`
4. `EnsurePermission:{ability}`
5. `ThrottleRequests` — stricter on auth and posting
6. `Idempotency` — posting routes

## Endpoint map (M01)

### Auth
| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/auth/login` | public | email/password → token + companies |
| POST | `/auth/logout` | auth | revoke current token |
| GET | `/auth/me` | auth | profile + company memberships |

### Companies
| Method | Path | Permission |
|---|---|---|
| GET | `/companies` | auth (memberships) |
| POST | `/companies` | `companies.create` (platform/admin) |
| GET | `/companies/{id}` | company member |
| PATCH | `/companies/{id}` | `companies.update` |
| GET/PATCH | `/companies/{id}/settings` | `companies.settings` |

### Books / frameworks / jurisdictions
| Method | Path | Permission |
|---|---|---|
| GET | `/frameworks` | auth |
| GET | `/jurisdictions` | auth |
| GET | `/books` | `books.view` |
| POST | `/books` | `books.manage` |
| PATCH | `/books/{id}` | `books.manage` |

### Currencies & rates
| Method | Path | Permission |
|---|---|---|
| GET | `/currencies` | auth |
| GET | `/exchange-rates` | `fx.view` |
| POST | `/exchange-rates` | `fx.manage` |

### Fiscal calendar
| Method | Path | Permission |
|---|---|---|
| GET/POST | `/fiscal-years` | `periods.view` / `periods.manage` |
| GET/POST | `/fiscal-periods` | `periods.view` / `periods.manage` |
| POST | `/period-locks` | `periods.lock` |
| DELETE | `/period-locks/{id}` | `periods.unlock` (controlled) |

### Chart of accounts
| Method | Path | Permission |
|---|---|---|
| GET | `/account-types` | auth |
| GET/POST | `/account-groups` | `coa.view` / `coa.manage` |
| GET/POST | `/accounts` | `coa.view` / `coa.manage` |
| GET/PATCH | `/accounts/{id}` | `coa.view` / `coa.manage` |
| POST | `/accounts/{id}/deactivate` | `coa.manage` |
| GET/POST | `/account-openings` | `coa.view` / `coa.openings` |
| POST | `/account-openings/post` | `journals.post` | posts balanced opening JE |

### Journals / GL
| Method | Path | Permission |
|---|---|---|
| GET | `/journals` | `journals.view` |
| POST | `/journals` | `journals.create` | draft |
| GET | `/journals/{id}` | `journals.view` |
| PATCH | `/journals/{id}` | `journals.create` | draft only |
| POST | `/journals/{id}/post` | `journals.post` | idempotent |
| POST | `/journals/{id}/reverse` | `journals.reverse` |
| GET | `/accounts/{id}/ledger` | `journals.view` | drill-down |
| GET | `/trial-balance` | `reports.trial_balance` | M01 basic TB for validation |

### Audit
| Method | Path | Permission |
|---|---|---|
| GET | `/audit-logs` | `audit.view` |

## Posting request shape (illustrative)

```json
{
  "journal_date": "2026-11-17",
  "description": "Rent accrual",
  "reference": "ACC-001",
  "currency_code": "TZS",
  "lines": [
    { "account_id": 101, "debit": "100000.0000", "credit": "0.0000", "description": "Expense" },
    { "account_id": 201, "debit": "0.0000", "credit": "100000.0000", "description": "Accrual" }
  ]
}
```

Server computes functional amounts; client amounts are validated but not trusted as sole authority after FX.

## Versioning & evolution

- Breaking changes → `/api/v2`
- Additive fields allowed in v1 with care
- Integration webhooks signed in M08; M01 only persists outbox rows

## Rate limiting (initial)

| Route class | Limit |
|---|---|
| login | 5/min/IP |
| read APIs | 120/min/user |
| posting APIs | 30/min/user |

Tune after load testing (M09).
