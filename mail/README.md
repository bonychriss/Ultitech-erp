# Mail — Gmail-style webmail for ERP (Yii2 Advanced)

Webmail app built on **Yii2 Advanced** + **MySQL**. Users sign in, connect IMAP/SMTP accounts, sync inbox, compose/send, star, search, and manage folders like Gmail.

## React frontend

Modern mail UI (React + Vite) is the default app:

- **Local (short URL):** http://localhost/mail/ or http://localhost/mail/app/
- Apache Alias: `apache/conf/extra/httpd-mail.conf` (included from `httpd-xampp.conf`)

Source: `frontend/react`  
Build output: `frontend/web/app`

```bash
cd frontend/react
npm install
npm run build
```

**Production** (document root = `frontend/web`):

```bash
set VITE_BASE_PATH=/app/
npm run build
```

Then point the vhost/docroot at `frontend/web` and set `RewriteBase /` in `frontend/web/.htaccess`.

Legacy PHP Gmail UI remains at `/mail/index` (Yii route).


## Demo login

| Username | Password  |
|----------|-----------|
| `demo`   | `demo1234` |

Demo mailbox already has sample ERP-style messages (inbox / sent / drafts).

## Connect a real mailbox

1. Sign in → **Accounts** → **Add account**
2. Enter IMAP + SMTP settings (examples):

**Gmail**
- IMAP: `imap.gmail.com:993` SSL  
- SMTP: `smtp.gmail.com:587` TLS  
- Use an [App Password](https://myaccount.google.com/apppasswords)

**Microsoft 365**
- IMAP: `outlook.office365.com:993` SSL  
- SMTP: `smtp.office365.com:587` TLS  

3. Click **Refresh mail** in the sidebar to sync.

## Database

**Local (XAMPP):** `mail_app` / `root` — `common/config/main-local.php`

**Live:** `ultimate_mail_app` / `ultimate_mail_user` — set in `common/config/main-local.php` (auto-detects live host)

Migrations:

```bash
c:\xampp\php\php.exe yii migrate
# on live:
php yii migrate --interactive=0
```

## Features

- Inbox, Starred, Sent, Drafts, Trash, Spam
- Compose / Reply / Forward / Save draft
- **Attach PDFs and files** when composing (PDF, images, Office, ZIP)
- **View PDFs inline** in the message reader + download any attachment
- Search (subject, from, body snippet)
- Star / delete
- IMAP sync into SQL for fast UI
- SMTP send with attachments (demo account still stores Sent locally if SMTP is unavailable)

## ERP usage tip

**Live site (cPanel `public_html/staff/mail`):**

- App URL: https://ultimate.co.tz/staff/mail/frontend/web/
- After uploading root `.htaccess`, https://ultimate.co.tz/staff/mail/ redirects there
- On the server, use `frontend/web/.htaccess.live` as `.htaccess`
- Build assets with: `cd frontend/react && npm run build:live`
- Set DB in `common/config/main-local.php` (prod values), then `php yii migrate --interactive=0`
- Ensure `frontend/web/index.php` is the **prod** version (`YII_ENV` = `prod`)

Open Mail in a new tab from your ERP menu:

`https://ultimate.co.tz/staff/mail/frontend/web/`

Share the same MySQL host later if you want to SSO against ERP `user` tables.
