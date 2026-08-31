# Apify Integration

Default Actor ID: `apify/instagram-scraper` (single configuration store, not scattered).

API token: entered in dashboard Settings → Data Sources → Instagram → Apify. Never hardcoded. Never returned in full after save.

## Test connection

Dashboard → TEST CONNECTION → API validates → Apify user/me or actor inspect → CONNECTED or CONNECTION FAILED. Errors must not include the token.

## Actor execution flow

START → CollectionJob → load config → validate token → load actor id → build search input → run actor → fetch dataset → normalize → construction check → classify → intent → contacts → dedupe → score → DB → SignalR.
