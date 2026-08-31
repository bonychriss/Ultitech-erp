# Ultimate.co.tz Mail Bridge

Deploy on the **ultimate.co.tz** cPanel so Ultitech can pull/send `sales@ultimate.co.tz` mail over HTTPS APIs.

## Your cPanel path

Server path:

`/home/ultimate/public_html/staff`

Public URL base:

`https://ultimate.co.tz/staff`

## Recommended layout (important)

If `staff` already has a website / portal, **do not overwrite** its `index.php`.

Create a subfolder and put the bridge files there:

`/home/ultimate/public_html/staff/mail-bridge/`

Then the public base URL becomes:

`https://ultimate.co.tz/staff/mail-bridge`

If `staff` is empty / unused, you can place the bridge files directly in `staff/`.

## cPanel setup

1. Upload the contents of this `ultimate/` folder into:
   - `public_html/staff/mail-bridge/` (recommended), or
   - `public_html/staff/` (only if that folder is free)
2. Copy `config.sample.php` ? `config.php`.
3. Edit `config.php`:
   - set a long random `api_key`
   - set IMAP/SMTP host (cPanel ? Email Accounts ? Connect Devices)
   - set mailbox password for `sales@ultimate.co.tz`
4. Enable PHP **imap** (Select PHP Version ? Extensions ? `imap`).
5. Open in browser (should return JSON):
   - `https://ultimate.co.tz/staff/mail-bridge/`
6. Test health:

```bash
curl -H "X-Api-Key: YOUR_KEY" https://ultimate.co.tz/staff/mail-bridge/api/health.php
curl -H "X-Api-Key: YOUR_KEY" "https://ultimate.co.tz/staff/mail-bridge/api/messages.php?limit=10"
```

If you deployed directly into `staff/` (not `mail-bridge/`), use:

- `https://ultimate.co.tz/staff/api/health.php`

## API

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/health.php` | Test IMAP + SMTP |
| GET | `/api/messages.php?limit=50&since=YYYY-MM-DD` | List emails |
| GET | `/api/message.php?uid=12` | One email |
| POST | `/api/send.php` | Send `{ "to","subject","body" }` |

All endpoints require header: `X-Api-Key: <api_key from config.php>`

## Link to Ultitech

In Ultitech admin ? Email settings ? Remote Bridges:

- Enable Ultimate bridge sync
- Bridge URL: `https://ultimate.co.tz/staff/mail-bridge`  
  (or `https://ultimate.co.tz/staff` if files are in the staff root)
- API key: same as Ultimate `config.php`

Then Sync in the Ultitech mail module. Messages import with `recipient_email = sales@ultimate.co.tz`.

## Security

- Never commit real `config.php` passwords.
- Prefer HTTPS only.
- Rotate `api_key` if it leaks.
- `.htaccess` blocks direct web access to `config.php`.
