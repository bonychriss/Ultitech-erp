# Google Search Console: Validate Fix (Safe Browsing)

Use this checklist to confirm cleanup and request a re-review to remove the Chrome “Dangerous/Deceptive site” warning.

## 1) Verify the domain in Search Console

- Sign in: https://search.google.com/search-console
- Add property (Domain preferred) for your domain.
- Verification options:
  - DNS TXT (recommended), or
  - HTML file upload (see `google-verification.html` placeholder in this repo), or
  - Meta tag on your root page.

## 2) Confirm the fixes (evidence to include in the request)

- Codebase clean: Admin → Dashboard → Run Security Scan → "No suspicious files detected".
- Uploads locked: `.htaccess` in `assets/uploads/` denies PHP execution.
- HTTPS enforced + HSTS:
  - Response header: `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`.
- Strong CSP in root `.htaccess`:
  - `Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; upgrade-insecure-requests`.
- Session/Logout hardened: logout clears cookies and redirects to login; no auto-entry.

Optional evidence:
- Screenshot of Response Headers from browser dev tools.
- List of removed files (if any were found).

## 3) Request a review (Validate Fix)

- Open Security Issues in Search Console.
- Click “Validate Fix” or "Request Review".
- Use this sample message:

```
We identified and resolved the issue triggering the Safe Browsing warning.

Actions taken:
- Removed any suspicious files; current integrity scan shows no suspicious files.
- Blocked PHP execution in uploads and signatures via .htaccess.
- Enforced HTTPS and added HSTS.
- Applied a strict Content-Security-Policy limiting resources to same-origin.
- Hardened session and logout behavior.

Please re-evaluate the site. We believe it no longer poses any risk.
```

## 4) After submitting

- Recheck the Safe Browsing status: https://transparencyreport.google.com/safe-browsing/search
- Review usually completes within hours up to 48 hours.

## Troubleshooting

- If the review fails, re-run the Admin security scan, check uploads for rogue files, and test pages with an external scanner (VirusTotal URL or Sucuri SiteCheck). Then resubmit.
