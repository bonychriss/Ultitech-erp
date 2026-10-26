# Email advertisement (website campaigns)

Local product email campaigns for the **Website** module. Staff pick products, write a short message, choose customers, and send (or schedule) an Alibaba-style product email.

**Page URL (Ultimate, localhost):**  
`http://localhost/public_html/ultimate/website/advertisements/email`

**Sidebar:** Website ? Advertisements ? Email advertisement

---

## Layout (matches campaign mock)

```
????????????????????????????????????????????????????????????????
? ? Email advertisement          [Save draft] [Send email now] ?
? Create a product email in 4 steps�                           ?
????????????????????????????????????????????????????????????????
? MAIN                       ? ASIDE (sticky preview)          ?
? ? Write the email          ? Email preview  [Desktop|Mobile] ?
? ? Choose products          ? brand � orange hero � products  ?
? ? Choose who receives it   ?                                 ?
? ? Send                     ?                                 ?
????????????????????????????????????????????????????????????????
? Previous campaigns (table)                                   ?
????????????????????????????????????????????????????????????????
```

On narrow screens the **preview moves above** the steps.

### Theme

- White cards, soft slate borders, purple step numbers.
- Primary actions use **purple** (`#7c3aed`): Send button, email CTA, hero banner.
- Page title comes from the Website shell only (not repeated in the React page).
- Live preview toggles **Desktop** / **Mobile** width.

---

## Step-by-step

### Step 1 � Write the email

| Field | Role |
| --- | --- |
| Email subject | Inbox title customers see |
| Big headline | Banner text in the email |
| Short message | Intro under the banner (max 500 chars) |

Button text defaults to **Inquire Now**. Campaign name is taken from the subject when saving.

Header actions: **Save draft** and **Send email now** (or **Schedule email** when step 4 is set to later).

### Step 2 � Choose products

- Selected products show as compact removable chips (**Clear all** available).
- **View & select catalogue** opens the same **Sales Catalogue** page used for quotes/invoices.
- After **Add selected**, you return here with those products applied to the email.

### Step 3 � Choose who receives it

- **All customers with email** � every active customer with an email on file.
- **Pick customers** � checkbox list of named contacts.

### Step 4 � Send

- **Send now** � save and send immediately.
- **Schedule for later** � date/time picker; due campaigns process on page load (and via process action).

Send stays disabled until there is at least one product, a subject, and at least one recipient.

### Previous campaigns

Full-width table under the composer: `#`, Name, Subject, Recipients, Status, Scheduled, Sent, Actions. Drafts / scheduled / failed rows can be sent again.

---

## What the customer email looks like

1. Company name header + tagline  
2. Orange headline banner  
3. Short intro message  
4. **Two-column product grid** � photo, name, code, price, inquire button  
5. Footer / catalogue link  

Product links open the shop search for that product (e.g. ultimate.co.tz).

---

## How to use (quick)

1. Open **Advertisements ? Email advertisement**.  
2. Fill subject and headline.  
3. Select products (step 2).  
4. Confirm audience (step 3).  
5. Check **Email preview** (Desktop / Mobile).  
6. Click **Send email now** (or schedule / save draft).

---

## Technical map

| Piece | Location |
| --- | --- |
| React page | `stock/stock-ui/src/pages/EmailAdvertisement.jsx` |
| Styles | `stock/stock-ui/src/pages/email-advertisement.css` |
| App route key | `email-advertisement` in `stock/stock-ui/src/App.jsx` |
| Backend / API | `stock/includes/web-advertisements-lib.php` |
| AJAX entry | `website.php?ajax=ad-email` |
| Page controller | `erp-laravel/app/Http/Controllers/WebsitePageController.php` (view `advertisements-email`) |
| Pretty URLs | `.htaccess` / `ultimate/.htaccess` ? `website/advertisements/email` |
| Sidebar | `sidebar.php` and `includes/sidebar.php` (Advertisements parent) |

### API actions (`POST` JSON to `?ajax=ad-email`)

| `action` | Purpose |
| --- | --- |
| `search_products` | Product picker search (supports category) |
| `search_customers` | Customer search |
| `save` | Draft or scheduled campaign |
| `save_and_send` | Save then send now |
| `send` | Send an existing campaign by `id` |
| `list` | Recent campaigns |
| `process_due` | Run scheduled campaigns that are due |

### Database (auto-created)

- `website_email_campaigns` � campaign content, audience, schedule, status  
- `website_email_campaign_logs` � per-recipient send result  

Mail goes through the shared ERP mailer (`includes/mailer.php`). SMTP must be configured for real delivery.

---

## Build note

After editing the React page or CSS:

```bash
cd stock/stock-ui
npm run build
```

This updates `stock/stock-ui/dist/` used by the Website module shell.

---

## Scope

Built and tested for **localhost** first. Do not assume it is deployed to production until that is done separately.
