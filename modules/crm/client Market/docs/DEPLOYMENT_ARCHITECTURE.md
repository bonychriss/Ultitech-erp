# Deployment Architecture

```text
docker-compose
  frontend (Next.js)
  api (ASP.NET)
  worker (Python)
  postgres
  redis
```

XAMPP is optional for static hosting; recommended local stack is Docker + `dotnet run` + `npm run dev`.

Administrator places production secrets after deploy:

1. Settings → Integrations → Apify / Instagram (Actor ID + token)
2. Settings → Email Configuration (sender + SMTP auth)
