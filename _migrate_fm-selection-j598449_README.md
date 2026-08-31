# Migration from `fm-selection-j598449` (old cPanel backup)

Merged **uploaded files and media** from:

`fm-selection-j598449/public_html/` ? `public_html/`

using **robocopy** with `/E` (subfolders) and `/XO` (**skip destination files that are newer or same age** — only missing files or newer-on-backup sources are copied).

## Folders merged

| Source (backup) | Destination (live) |
|-----------------|-------------------|
| `uploads/` | `uploads/` |
| `assets/images/` | `assets/images/` |
| `assets/uploads/` | `assets/uploads/` |
| `assets/signatures/` | `assets/signatures/` |
| `stock/uploads/` | `stock/uploads/` |
| `stock/assets/` | `stock/assets/` |
| `signatures/` | `signatures/` |
| `images/` | `images/` |
| `background images/` | `background images/` |
| `voice manuals/` | `voice manuals/` |
| `vid tutorials/` | `vid tutorials/` |
| `employee/images/` | `employee/images/` |

## Logs

- Full robocopy output: `_migrate_fm-selection-j598449_robocopy.log`
- Lines marked `*EXTRA` are files that exist **only on the live site** (not errors).

## After you deploy to new cPanel

1. Upload this **`public_html`** tree (including merged folders above).
2. **Database**: still needs to be migrated separately (SQL dump / phpMyAdmin). Files alone do not replace DB rows that point to paths.
3. When satisfied, you can delete **`fm-selection-j598449`** (and optionally `fm-selection-j598449.zip` if you keep another backup elsewhere).

## Note on `stock/assets`

That folder includes some theme/CSS/JS from the backup. Only files where the backup was **newer** than live were overwritten (`/XO`). If anything looks wrong in the stock UI, compare or restore those files from git/previous backup.
