# Accounting upgrade (in-ERP dual-run)

The new Accounting module runs **inside the ERP** (same sidebar, company logo, session). Classic Accounting stays as rollback until you cut over.

## Modes (`env.local.php`)

| Mode | Behaviour |
|------|-----------|
| `parallel` | Classic hub + New Accounting both available |
| `primary` | `/accounting` opens **new** module; Classic via sidebar **Classic Accounting** or `?engine=classic` |
| `off` | New module hidden — classic only |

```php
$ULTITECH_ACCOUNTING_MODE = 'primary'; // or parallel | off
$ULTITECH_ACCOUNTING_API = 'http://127.0.0.1:8000';
$ULTITECH_ACCOUNTING_BRIDGE_SECRET = '…'; // match Accounting ERP_BRIDGE_SECRET
```

## Run

1. Accounting API: `ultitech-accounting/backend` → `php artisan serve`
2. Build UI into ERP: `ultitech-accounting/frontend` → `npm run build`
3. Open ERP Accounting (or New Accounting / Dashboard in sidebar)

No need for `http://127.0.0.1:5173` in normal ERP use.

## Rollback

- Sidebar → **Classic Accounting**, or set mode to `off`.
- ERP GL data is never written by the new module (read-only sync only).

## After success

Set mode `primary`, validate, then remove classic hub/cards when ready.
