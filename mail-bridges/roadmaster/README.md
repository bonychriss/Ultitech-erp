# Roadmaster Spares Mail Bridge

Deploy this folder on the **roadmasterspares.com** cPanel so Ultitech can pull/send `sales@roadmasterspares.com` mail over HTTPS APIs.

## cPanel setup

1. Zip this folder (or upload via File Manager).
2. Create folder **`public_html/staff/roadmaster/`** and upload the bridge files there
   (public URL: `https://roadmasterspares.com/staff/roadmaster`).
   Do not put files directly in `public_html/staff/` — that path returns Forbidden without an index.
3. Ensure `config.php` has the mailbox password and API key.
4. Enable PHP **IMAP** (cPanel ? Select PHP Version ? Extensions ? `imap`).
5. Visit `https://roadmasterspares.com/staff/roadmaster/` — you should see JSON service info.
6. Test:

```bash
curl -H "X-Api-Key: Ultitech_Roadmaster_Bridge_9mKp2xR7vN4wQ6tY" https://roadmasterspares.com/staff/roadmaster/api/health.php
curl -H "X-Api-Key: Ultitech_Roadmaster_Bridge_9mKp2xR7vN4wQ6tY" "https://roadmasterspares.com/staff/roadmaster/api/messages.php?limit=10"
```

## API

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/health.php` | Test IMAP + SMTP |
| GET | `/api/messages.php?limit=50&since=YYYY-MM-DD` | List emails |
| GET | `/api/message.php?uid=12` | One email |
| POST | `/api/send.php` | Send `{ "to","subject","body" }` |

All endpoints require header: `X-Api-Key: <api_key from config.php>`

## Link to Ultitech

In Ultitech admin ? Email settings:

- Bridge URL: `https://roadmasterspares.com/staff/roadmaster`
- API key: `Ultitech_Roadmaster_Bridge_9mKp2xR7vN4wQ6tY`
- Brand key: `roadmaster`

## Security

- Never commit real `config.php` passwords.
- Prefer HTTPS only.
- Rotate `api_key` if it leaks.
- `.htaccess` blocks direct `config.php` access.
