# UltiTech ERP ù GitHub ? cPanel Deployment

Production: **https://ultitech.io**  
GitHub: **https://github.com/bonychriss/Ultitech-erp**  
cPanel target: **`public_html/`**

## Workflow

```text
Local dev ? git commit ? git push origin main ? GitHub
    ? cPanel Git pull/deploy ? public_html/ ? ultitech.io
```

## One-time cPanel setup

1. **Back up** `public_html/` and the production database.
2. **SSH:** cPanel ? SSH Access ? generate a key pair.
3. **GitHub deploy key:** Repository ? Settings ? Deploy keys ? add the **public** key (read-only).
4. **Clone:** cPanel ? Git Version Control ? Clone:
   - URL: `git@github.com:bonychriss/Ultitech-erp.git`
   - Path: `/home/YOUR_CPANEL_USER/repositories/ultitech-erp`
5. **Deploy path:** Git Version Control ? Manage ? Deploy ? set target to `public_html/`.
6. **First deploy:** Deploy HEAD Commit.
7. **Production env:** Ensure `public_html/env.php` exists (never in Git). Use `env.php.example` as template.
8. **Composer (once):** In Terminal: `cd ~/public_html && composer install --no-dev`
9. **Desktop installer:** Upload `UltiTech-ERP-Setup-1.0.0.exe` to `client-apps/desktop/dist/` via FTP.
10. **Webhook (optional):** GitHub ? Webhooks ? cPanel deploy URL for auto-deploy on push.

## One-time GitHub setup

1. Add the cPanel **read-only** deploy key.
2. **Branch protection** on `main`: require pull request reviews before merge.
3. **Rotate credentials** that were previously committed (DB, SMTP, mail bridge passwords).
4. Tag releases before major deploys: `git tag v2026.08.31 && git push origin v2026.08.31`

## Every deploy (developers)

1. Change PHP/React code locally.
2. For React UI changes: `npm run build` inside the affected `frontend/` folder.
3. Commit including updated `dist/` files.
4. `git push origin main`
5. cPanel: Pull or Deploy (or wait for webhook).

## Protected on server (never overwritten by deploy)

- `env.php` ù database credentials
- `config_mail.php` ù SMTP credentials
- `mail-bridges/*/config.php` ù mail bridge secrets
- `assets/uploads/`, `assets/signatures/`, `uploads/`, `storage/`, `logs/`, `backups/`
- `vendor/` ù updated via `composer install`, not rsync
- `client-apps/desktop/dist/*.exe` ù uploaded manually

## Rollback

1. cPanel ? Git Version Control ? History ? select last good commit.
2. Reset branch to that commit ? Deploy HEAD Commit.

Or on GitHub: `git revert` bad commit ? push ? redeploy.

## Database changes

Git deploy **does not** run migrations. Apply SQL/schema changes manually via phpMyAdmin after review.

## Local development paths

| Environment | URL base |
|-------------|----------|
| XAMPP `htdocs/public_html` | `http://localhost/public_html/` |
| Alternate folder under `htdocs` | Auto-detected via `APP_BASE_PATH` |
| Production StackCP | `https://ultitech.io/` (`APP_BASE_PATH` = empty) |

Local overrides: copy `env.local.example.php` ? `env.local.php` (gitignored).
