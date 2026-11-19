# UltiTech Accounting

Standalone multi-company enterprise accounting platform.

**Status:** Milestone 01 foundation implemented (backend + frontend + acceptance tests)

## Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2 + Laravel 11 + Sanctum |
| Frontend | React 19 + TypeScript + Vite + Tailwind CSS |
| Database | MariaDB/MySQL `ultitech_accounting_dev` |
| Queue | `sync` locally (Redis later) |
| API | `/api/v1` |

## Quick start (XAMPP)

### Backend

```powershell
cd C:\xampp\htdocs\public_html\ultitech-accounting\backend
C:\xampp\php\php.exe artisan migrate:fresh --seed
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

### Frontend

```powershell
cd C:\xampp\htdocs\public_html\ultitech-accounting\frontend
npm install
npm run dev
```

Open `http://127.0.0.1:5173`

### Demo login

- Email: `admin@ultitech.local`
- Password: `password`
- Company: `DEMO` / UltiTech Demo Limited

## Project layout

```
ultitech-accounting/
  backend/
  frontend/
  documentation/
  integration/
  infrastructure/
  tests/
  README.md
```

## Milestone 01 scope delivered

- Shared-DB tenant isolation (`company_id` + middleware)
- Accounting books, currencies/FX, fiscal years/periods, period locks
- Chart of Accounts
- Central GL posting engine, reversals, audit + outbox
- Trial Balance API
- React enterprise shell (dashboard, CoA, journals, TB, settings)
- Automated acceptance tests (13 passing)

## Documentation

See `documentation/` for architecture, ERD, schema, API, security, migration plan and session reports.

## Isolation rules

- Does not modify UltiTech ERP under `public_html` outside this folder
- Does not use ERP production databases or credentials
- Own database only: `ultitech_accounting_dev`
