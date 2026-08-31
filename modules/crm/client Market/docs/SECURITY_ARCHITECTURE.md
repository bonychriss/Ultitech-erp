# Security Architecture

- HTTPS in production
- JWT access + rotating refresh tokens
- ASP.NET Identity password hashing
- RBAC: Super Administrator, Administrator, Sales/Analyst, Viewer
- Super Admin only: Apify, email secrets, users, scoring, data sources
- Validation, rate limiting, CORS, security headers
- Audit logs without secret values
- Encrypted secret columns
- Never commit `.env`, tokens, passwords, JWT keys
