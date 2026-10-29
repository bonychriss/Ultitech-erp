# Profile Settings (`employee/account.php`)

The signed-in user's own profile page. Any logged-in user (employee, finance, admin) can open it from any module; the `module` query string only controls which module's header/breadcrumb context is shown.

| Environment | URL |
|---|---|
| Local | http://localhost/public_html/ultimate/employee/account.php?module=accounting |
| Live | https://ultitech.io/ultimate/employee/account.php?module=accounting |

## Files

| File | Role |
|---|---|
| `employee/account.php` | Controller + page: POST handlers, data loading, hero, tabs, signature, email settings, sidebar cards, styles and scripts |
| `employee/includes/account-settings-view.inc.php` | Profile Photo, Personal Details and Security & Password sections |
| `ultimate/employee/account.php` | Company stub; loads the shared page for the `ultimate` tenant via `_tenant_require.php` |
| `includes/header_employee.php` | Top bar; receives `$employeeHeaderTitle` and the breadcrumb HTML in `$employeeHeaderSubtitle` |
| `api/save_user_email_settings.php` | Saves personal IMAP/SMTP settings (AJAX) |
| `api/test_user_email_connection.php` | Tests IMAP/SMTP settings before saving (AJAX) |

## Page layout

1. **Hero** (purple gradient): avatar with camera button (instant upload), full name, `@username`, role and status badges, email, joined month, signature status, **Log out** button.
2. **Tabs**: Profile, Details, Security, Signature, Email. Each tab shows only its own section and hides the rest. The open tab is kept in the URL hash (for example `#digital-signature`) and in `sessionStorage`, so the same tab stays open after a form saves and the page reloads.
3. **Two-column body**
   - Main column: the five sections below.
   - Sidebar: **Account Status** card (role, active/inactive, joined, last login).
4. Breadcrumb in the header: Home / Account / Profile Settings.

The layout collapses to a single column on small screens and has dark-mode overrides.

## Sections and what they save

Every form posts back to `account.php` itself, except the email settings which use AJAX. The result is shown as a SweetAlert toast: green if the message contains "success", red otherwise.

| Section | Anchor | POST field(s) | Effect |
|---|---|---|---|
| Profile Photo | `#profile-photo` | `profile_photo` (file) | JPG/PNG/GIF/WEBP checked by MIME type; saved to `assets/uploads/profiles/profile_{userId}_{time}.{ext}`; path stored in `users.profile_photo` |
| | | `remove_profile_photo=1` | Sets `users.profile_photo` to NULL and deletes the old file |
| Personal Details | `#personal-details` | `new_full_name` | Minimum 2 characters. Updates `users.full_name` and the session, and renames the user in legacy voucher text columns (`applicant`, `prepared_by`, `checked_by`, `department_manager`, `general_manager`) |
| | | `new_username` | Minimum 3 characters, must be unique. Updates the session and the company user index (`syncUserCompanyIndex`) |
| | | `new_email` | Valid and unique email (normalized via `normalizeLoginEmail`). Updates the session and the company user index |
| | | `whatsapp_number` | Free text saved to `users.whatsapp_number` |
| Security & Password | `#change-password` | `action=change_password`, `new_password`, `confirm_password` | Passwords must match and pass `evaluatePasswordStrength` (8+ characters, mixed case, a number or symbol). The current password is **not** asked for because the user is already signed in. A live strength meter and match check run in the browser. Also links to Forgot password |
| Digital Signature | `#digital-signature` | `signatureData` (PNG data URI from the canvas) | Draw with pen size/colour, undo/redo, clear. Saved by `handleUserSignatureUpload` (max 500 KB, max 1600ù800) and used on new vouchers |
| | | `delete_signature=1` | Removes the signature (`deleteUserSignature`) |
| Personal Email Account | `#personal-email` | IMAP and SMTP host, port, user, password, SSL/TLS | **Test Connection** calls `api/test_user_email_connection.php`; **Save** calls `api/save_user_email_settings.php`. Stored in `module_email_user_settings` (email module database) and used by the Email module |

### Handler order

`account.php` checks POST fields in this order and stops at the first match:

1. `remove_profile_photo`, handled before everything else.
2. `profile_photo` upload. When a file is present, none of the other handlers run.
3. `delete_signature`, `whatsapp_number`, `new_email`, `new_full_name`, `new_username`, `action=change_password`.
4. Anything else is treated as a signature save.

If you add a new form, give it its own POST key and add an `elseif` **before** the final `else`. Otherwise the request falls through to the signature handler and shows a signature error.

## Data used

- `users`: `id, username, full_name, email, role, department, is_active, created_at, updated_at, profile_photo, whatsapp_number`, plus the signature column.
- The `profile_photo`, `whatsapp_number` and signature columns are created on demand (`ensureProfilePhotoColumn`, `ensureWhatsAppColumn`, `ensureUserSignatureColumn`).
- **Last login** in the Account Status card comes from `users.updated_at` (shown as Today, Yesterday or a full date). This column also changes on any profile edit, so it shows the last activity rather than a true login time.
- If the user row can't be loaded, the page redirects to `dashboard.php`.

## Known gaps

- The forms have no CSRF token.
- The photo section says "max 2MB", but the server doesn't check the file size; only PHP's upload limit applies.

## Deploying

Follow the workspace's local-first rule: change and check on localhost first, and deploy only when asked. The page lives on the parent app root, so upload changed files to `/public_html/employee/...` (the same path mapping as `scripts/cpanel-deploy-changed.ps1`). The `ultimate/employee/account.php` stub doesn't normally need to change.
