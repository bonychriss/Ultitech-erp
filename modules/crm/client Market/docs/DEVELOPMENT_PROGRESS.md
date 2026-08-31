# Development Progress

## Completed

- Phase 1 architecture documents
- Product root: ULTIMATE ONLINE PLATFORM
- Next.js portal UI matching eesti.ee wording/layout
- Language switch ENG / EST / SWA
- Apache ECharts intraday-with-breaks chart
- ASP.NET API skeleton (health, login mock, Apify config masked)
- Python worker skeleton + Apify connector stub
- docker-compose (Postgres, Redis)
- .env.example (empty secrets)

## Pending

- Install .NET SDK to compile API
- PostgreSQL migrations
- Real JWT, Redis queues, Apify actor runs
- Full collection engine and scoring
- Outreach send pipeline

## Known Issues

- .NET SDK not installed on this machine yet
- Apify test currently checks token presence only (no live Apify call until token is saved)

## New APIs

- GET /api/v1/health
- POST /api/v1/auth/login
- GET/PUT /api/v1/integrations/apify
- POST /api/v1/integrations/apify/test
- SignalR /hubs/platform

## Database Changes

- Designed, not migrated

## Tests

- Frontend typecheck next

## Next Step

- `cd frontend && npm run dev`
- Install .NET 8 SDK, then `dotnet run` in Platform.Api
- Admin pastes Apify token in Settings → Apify (never in git)
