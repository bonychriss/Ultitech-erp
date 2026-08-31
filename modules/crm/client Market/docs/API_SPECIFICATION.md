# API Specification

Base: `/api/v1`  
Auth: JWT Bearer + refresh cookie/body.

## Auth

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/login` | email/password |
| POST | `/auth/logout` | revoke refresh |
| POST | `/auth/refresh` | |
| POST | `/auth/forgot-password` | |
| POST | `/auth/reset-password` | |
| POST | `/auth/change-password` | authenticated |

## Dashboard / analytics

| Method | Path |
| --- | --- |
| GET | `/analytics/summary?range=` |
| GET | `/analytics/timeseries` |
| GET | `/health` |
| GET | `/health/components` |

## Leads

| Method | Path |
| --- | --- |
| GET | `/leads` | server-side page/filter |
| GET | `/leads/{id}` |
| POST | `/leads/export` |

## Collection

| Method | Path |
| --- | --- |
| POST | `/jobs/start` |
| POST | `/jobs/{id}/pause` |
| POST | `/jobs/{id}/resume` |
| POST | `/jobs/{id}/stop` |
| GET | `/jobs/active` |

## Integrations (Super Admin)

| Method | Path |
| --- | --- |
| GET | `/integrations/apify` | masked token |
| PUT | `/integrations/apify` | actorId + token |
| POST | `/integrations/apify/test` | never echo token |

## Email

| Method | Path |
| --- | --- |
| GET/PUT | `/settings/email` |
| POST | `/settings/email/test` |
| GET/PUT | `/settings/email/templates` |
| POST | `/outreach/preview` |
| POST | `/outreach/send` |

## SignalR hub

`/hubs/platform`

Events: `LeadFound`, `LeadProcessed`, `HotLeadDetected`, `CollectionProgress`, `CollectionPaused`, `CollectionResumed`, `CollectionStopped`, `CollectionCompleted`, `AnalyticsUpdated`, `OutreachStatusUpdated`, `IntegrationStatusChanged`.
