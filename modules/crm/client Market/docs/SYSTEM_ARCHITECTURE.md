# System Architecture — ULTIMATE ONLINE PLATFORM

## Purpose

Construction Lead Intelligence & Outreach Platform identifies construction-related prospects from authorized sources (Instagram via Apify first), classifies them, scores leads, stores qualified records, and supports eligible outreach.

Product folder / display name: **ULTIMATE ONLINE PLATFORM**.

## High-level diagram

```text
USER
  │
  ▼
LOGIN PAGE
  │
  ▼
MAIN DASHBOARD (eesti.ee visual language + i18n + Apache ECharts)
  │
  ▼
ASP.NET CORE API
  ├── PostgreSQL
  ├── Redis (queues, cache)
  └── SignalR
        │
        ▼
PYTHON WORKERS
        │
        ▼
SOURCE CONNECTORS → APIFY CONNECTOR (apify/instagram-scraper)
        │
        ▼
CONSTRUCTION INTELLIGENCE (classify / intent / contact)
        │
        ▼
LEAD SCORE → DATABASE → SIGNALR → DASHBOARD
```

## Layers

| Layer | Tech | Responsibility |
| --- | --- | --- |
| Presentation | Next.js, React, TypeScript, Tailwind, TanStack Query/Table, Apache ECharts, SignalR client | One main dashboard, language switch |
| Application API | ASP.NET Core, C# | Thin controllers, JWT, RBAC, orchestration |
| Domain | Platform.Domain | Leads, jobs, scoring rules, keywords |
| Infrastructure | EF Core, Redis, SMTP, encryption | Persistence, queues, secrets |
| Intelligence | Python workers | Collection, classification, scoring signals |
| Data | PostgreSQL, Redis | System of record + queues |

## ASP.NET modules

- Identity (login, refresh, password, roles)
- Leads
- CollectionJobs
- Analytics
- Outreach / Email
- Integrations (Apify)
- Keywords / Scoring rules
- Exports
- Audit / Health / Settings
- Realtime (SignalR)

## Python worker modules

- collectors (base + instagram/apify)
- construction_intelligence
- processors (normalize, contacts, dedupe, score)
- integrations/apify
- queue consumers
- tests

## Non-goals

- Not a general social scraper
- No CAPTCHA bypass, auth evasion, or anti-bot circumvention
- No hardcoded Apify tokens or email passwords

## Connector principle

Instagram/Apify logic stays in connectors. Construction intelligence is source-agnostic.
