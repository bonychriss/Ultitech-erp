# Outreach Architecture

Public email ≠ automatic bulk-send permission. Manual send is MVP.

```text
SELECT → PREVIEW → CONFIRM → REDIS QUEUE → EMAIL WORKER → PROVIDER → STATUS
```

Statuses: Draft, Eligible, Queued, Sending, Sent, Delivered, Failed, Bounced, Unsubscribed, Suppressed.

Suppression: Unsubscribed, Hard Bounce, Complaint, Do Not Contact, Blocked — never send.

Campaign automation is future (Mode 2).
