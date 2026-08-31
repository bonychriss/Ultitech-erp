# Application Security Hardening

This document outlines recent security hardening steps applied to mitigate browser security warnings and reduce attack surface.

## Added Measures

1. Enforced HTTPS redirects via root `.htaccess`.
2. Disabled directory indexing across the site.
3. Blocked direct web access to sensitive internal folders (`includes/`, `scripts/`, `tasks/`).
4. Added restrictive `.htaccess` inside asset and upload directories to prevent PHP execution.
5. Introduced core security headers:
   - X-Frame-Options: SAMEORIGIN
   - X-Content-Type-Options: nosniff
   - Referrer-Policy: strict-origin-when-cross-origin
   - Permissions-Policy: disabled high-risk APIs
   - X-XSS-Protection: 1; mode=block
   - Content-Security-Policy: upgrade-insecure-requests
6. Published `robots.txt` to discourage indexing of administrative paths.
7. Created `security.txt` for standardized security contact information.

## Recommended Next Steps

- Implement a stricter Content Security Policy specifying allowed script/style origins.
- Add server-side rate limiting for authentication and sensitive endpoints.
- Validate and sanitize all user input points (file uploads, forms, query parameters).
- Introduce CSRF tokens on all state-changing POST requests.
- Rotate and store credentials using environment variables outside web root.

## Incident Response

In case of suspected compromise:
- Invalidate active sessions (truncate session table if using DB-based sessions).
- Force password reset for all users.
- Review server access logs for unusual patterns.
- Verify integrity of core PHP files against version control.

## Contact

For security issues, email `security@example.com`.
