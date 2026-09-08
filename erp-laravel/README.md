# erp-laravel

Single Laravel host for UltiTech ERP. Modules migrate **one by one** into `app/Domains/{Module}/`.

## Sales (Phase 2)

| Surface | Path |
|--------|------|
| Dashboard + list/create/view/edit desks | Blade `erp.react-shell` via `sales.php` |
| Desk JSON | `LegacyApiBridge` → `modules/sales/**/api` |
| Still legacy | print, payment, send-doc, admin, catalogue, invoice convert (`order_id`) |

## Suggest (Phase 3)

| Surface | Path |
|--------|------|
| Page | `suggest.php` → `/suggest` → Blade + `suggest-laravel/frontend/dist` |
| JSON | `suggest.php?api=suggestions` → `SuggestApiController` (Eloquent) |

`sales-laravel/` and `suggest-laravel/` hosts are **deprecated** (React dist may stay under `suggest-laravel/frontend`).

## Next

1. Port Sales desk APIs off `require` into Domains/Sales  
2. Stock (or next module) bridge-in  
3. Relocate Suggest React app out of `suggest-laravel/`
