# ULTIMATE ONLINE PLATFORM

Construction Lead Intelligence & Outreach Platform.

## Where to put APIs and secrets

Do **not** paste tokens into source code or Git.

1. Copy `.env.example` to `.env` (local Docker / worker). Leave `APIFY_API_TOKEN` empty if you prefer the dashboard.
2. After the app is running, sign in as Super Administrator and open:

**Settings → Data Sources → Instagram → Apify**

- Actor ID (pre-filled): `apify/instagram-scraper`
- Apify API Token: paste from [Apify Console](https://console.apify.com/account/integrations) — Test Connection → Save

**Settings → Email Configuration**

- Sender: `systemconfiguration2026@gmail.com`
- SMTP host/port + app password or OAuth (never the normal Gmail password in git)

## Local run (after Docker Desktop is available)

```bash
docker compose up -d postgres redis
cd backend/src/Platform.Api && dotnet run
cd frontend && npm run dev
cd worker && python -m app.main
```

Frontend default: http://localhost:3000  
API default: http://localhost:5080

## ERP integration (CRM Market)

This app is wired into the main ERP under **CRM → CRM Market**:

1. Double-click `modules/crm/start-client-market.bat` (or run `npm run dev` in `frontend/`) so Client Market is on http://localhost:3000
2. In the ERP, open **CRM Market** — discover leads here, then **Add to CRM** / **Import selected** to push them into **My Customers**
3. Client Market MySQL database: `ultimate_online_platform` (created automatically on first use)

Do not put Apify tokens or mailbox passwords in git; configure them inside Client Market settings.
