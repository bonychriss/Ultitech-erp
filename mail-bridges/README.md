# Ultitech Mail Bridges

Self-contained PHP mail bridges you upload to each brand's cPanel. Ultitech pulls mail from them over HTTPS APIs (no shared IMAP across domains).

| Folder | Domain | Mailbox |
|--------|--------|---------|
| `ultimate/` | ultimate.co.tz | sales@ultimate.co.tz |
| `roadmaster/` | roadmasterspares.com | sales@roadmasterspares.com |
| `_shared/` | source template only — do not deploy | — |

## Flow

```
sales@ultimate.co.tz  ??IMAP???  ultimate cPanel /mail-bridge  ??HTTPS API???  Ultitech mail module
sales@roadmaster...   ??IMAP???  roadmaster cPanel /mail-bridge ??HTTPS API???  Ultitech mail module
```

## Quick start

1. Configure and upload `ultimate/` to Ultimate cPanel.
2. Configure and upload `roadmaster/` to Roadmaster cPanel.
3. In Ultitech admin Email settings, save both bridge URLs + API keys.
4. Click Sync in the mail module.

See each folder's `README.md` for cPanel details.
