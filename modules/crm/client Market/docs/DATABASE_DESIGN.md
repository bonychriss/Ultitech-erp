# Database Design

PostgreSQL is the system of record. Sensitive fields are encrypted at rest in application code before insert.

## ERD (logical)

```text
Users ──< UserRoles >── Roles ──< RolePermissions >── Permissions

DataSources ──< IntegrationConfigurations
ApifyConfigurations (1:1 IntegrationConfigurations, encrypted token)

ConstructionKeywords
ConstructionCategories

CollectionJobs ──< CollectionJobEvents
                └──< LeadSearchSources >── Leads

Leads ──< LeadContacts
      ──< LeadLocations
      ──< LeadIntentSignals
      ──< LeadScores
      ── unique (Platform, PlatformProfileId)
      ── unique (Platform, Username) fallback

EmailConfigurations (encrypted secrets)
EmailTemplates
EmailCampaigns ──< EmailCampaignRecipients
EmailDeliveryLogs
SuppressionList

Exports
Notifications
AuditLogs
Workers ──< WorkerExecutions
SystemSettings
```

## Lead uniqueness

Prefer `Platform + PlatformProfileId`. Fallback `Platform + Username`.

## Lead levels (derived from score)

| Score | Level |
| --- | --- |
| 80–100 | HOT |
| 60–79 | WARM |
| 40–59 | POTENTIAL |
| 0–39 | LOW |

## Secrets

Never store plaintext Apify tokens or SMTP passwords. Store ciphertext + key id. API responses return masked values only (`apify_api_****************`).
