# Dashboard Design

Admin UI clones the **eesti.ee / Riigiportaal** layout, colors, and wording from the provided screenshots.

## Visual language

- Top bar: dark navy (`#003366` family)
- Links: Home, Accessibility
- Center role toggle: **Citizen** | **Entrepreneur** (triangle under active)
- Right: **Language: ENG** (dropdown; also Swahili / Estonian)
- White secondary bar: coat-of-arms style mark + **RIIGIPORTAAL EESTI.EE** branding area (product subtitle: ULTIMATE ONLINE PLATFORM)
- Center search: **Enter a search term** + circular blue search button
- Right CTA: **Log into self-service.**
- Left sidebar navy: **SELF-SERVICE**, login copy, **Enter**, **Service catalogue**, **ARTICLES**, **General information**
- Hero: forest image + **Welcome!**
- Two columns: **E-services for citizen** / **E-services for entrepreneurs**
- White service cards with line icons
- Footer links: **Log in to the self-service for citizen →** / **entrepreneurs →**
- Scroll-to-top FAB

## Construction mapping (same chrome, live data after login)

Citizen column surfaces lead/analytics services. Entrepreneur column surfaces search/outreach/ops.

| Screenshot card | Platform feature |
| --- | --- |
| My prescriptions | Live Prospects |
| Ordering the European Health Insurance Card | Lead Analytics |
| Account number and personal data… | Contact Information |
| Dental care benefit… | Lead Scoring |
| Certificates of temporary incapacity for work | Construction Search |
| My identity documents and photo | System Settings |
| Notarised documents | Audit Logs |
| Traffic insurance history | Export |
| Entrepreneur's dashboard | Collector Control |
| Mailbox | Outreach |
| Vacation pay… | Email Configuration |
| Authorisations | Users & Roles |
| Management of certificates… | Keywords |
| Health insurance of employees | Apify / Instagram |
| Population Register queries | System Health |

## Charts

Apache ECharts **intraday chart with breaks** (provided example): time axis, area line, dataZoom slider, UTC, breaks between sessions. Series data = construction leads discovered over time (mock in Phase 4, live later).

## Responsive

Desktop: sidebar + two card grids. Tablet/phone: stacked cards, table → mobile lead cards.
