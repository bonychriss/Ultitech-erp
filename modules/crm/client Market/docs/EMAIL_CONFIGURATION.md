# Email Configuration

Default sender display: `systemconfiguration2026@gmail.com`

Admin configures SMTP/Gmail app password or OAuth in Settings → Email Configuration. Credential stored encrypted. Masked in GET responses.

Env template (empty secrets):

```text
EMAIL_SENDER=systemconfiguration2026@gmail.com
EMAIL_PASSWORD=
SMTP_HOST=
SMTP_PORT=
```

TEST EMAIL sends to the logged-in admin address or a provided test recipient. Do not log credentials.
