# Worker Architecture

Python workers consume Redis queues published by ASP.NET.

```text
worker/
  app/
    collectors/base/
    collectors/instagram/apify_connector.py
    collectors/instagram/instagram_collector.py
    construction_intelligence/
    processors/
    integrations/apify/
    queue/
    models/
    services/
    utils/
    tests/
  main.py
```

Jobs: start collection, pause/resume/stop (cooperative), heartbeat, email send.

Instagram logic only under `collectors/instagram`. Apify HTTP only under `integrations/apify`.
