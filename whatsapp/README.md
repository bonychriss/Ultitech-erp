# WhatsApp Bot (Yii2 + React)

Send WhatsApp messages to **customers** and **staff**, run broadcasts, and optionally auto-reply to inbound messages via Meta Cloud API.

## Local setup (XAMPP)

1. Create MySQL database `whatsapp_bot` (user `root`, empty password).
2. Install PHP deps and migrate:

```bash
cd c:\xampp\htdocs\public_html\whatsapp
composer install
c:\xampp\php\php.exe yii migrate --interactive=0
```

3. Build React UI:

```bash
cd frontend\react
npm install
npm run build
```

4. Open: http://localhost/public_html/whatsapp/frontend/web/

**Demo login:** `admin` / `admin123`

## Meta WhatsApp Cloud API

1. Create an app at [Meta for Developers](https://developers.facebook.com/) ? WhatsApp ? API Setup.
2. Copy **Phone number ID** and a permanent **Access token**.
3. In the app **Settings**, paste them and save.
4. Set the webhook URL shown in Settings, with your verify token.
5. For production messaging outside the 24h window, use approved **message templates** (can be added next).

## Features

- Compose single messages (pick contact or enter phone)
- Contact directory: customers vs staff + tags
- Broadcast campaigns to customers / staff / all
- Message history (in + out)
- Webhook + optional auto-reply bot

## Notes

- Stack matches your mail app: **Yii2 advanced-style + Vite/React** (same pattern as the mail console).
- Phones should include country code without `+` (e.g. `2557XXXXXXXX`).
- Live DB names in `common/config/main-local.php` are placeholders (`roady_wa_bot` / `ultimate_wa_bot`) ù create those DBs on cPanel before deploying.
