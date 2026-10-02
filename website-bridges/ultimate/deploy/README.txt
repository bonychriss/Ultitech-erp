Ultimate.co.tz local-first quotes
================================

Delete /home/ultimate/public_html/ultitech/inspect.php in cPanel File Manager after you have copied its report. It is a live structure dump.

This package does not replace the homepage, the theme, or the Laravel app. Checkout already saves orders in the shop database. These files add a quote list that is saved on the shop first, then copied to UltiTech when ultitech.io answers.

Copy these files
---------------

From website-bridges/ultimate/ to /home/ultimate/public_html/ultitech/
  quote.php
  quote-button.js
  bridge-lib.php
  cron.php
  sync.php

From website-bridges/ultimate/deploy/ into /home/ultimate/public_html/ keeping the same folders:
  app/Http/Controllers/Admin/QuoteAdminController.php
  app/Models/Quote.php
  app/Models/QuoteItem.php
  database/migrations/2026_10_02_000001_create_ultitech_quote_tables.php
  resources/views/backend/quotes/index.blade.php
  resources/views/backend/quotes/show.blade.php
  resources/views/backend/quotes/print.blade.php
  routes/ultitech_quotes.php

One line in routes/admin.php
----------------------------

Inside the existing admin group (prefix admin, middleware auth, admin, prevent-back-history), add:

    require base_path('routes/ultitech_quotes.php');

That makes the pages /admin/quotes. Staff already signed into the shop admin can open them. There is no second login.

Sidebar
-------

Open resources/views/backend/inc/admin_sidenav.blade.php and paste the contents of sidenav-snippet.blade.php into the Sales section. Do not replace the whole sidebar file.

Database
--------

In cPanel Terminal, from /home/ultimate/public_html:

    /usr/local/bin/ea-php82 artisan migrate --force

The migration only creates quotes, quote_items, and sync_queue when they are missing. quote.php also creates those tables if a customer submits before migrate runs. Down migration drops only those three tables.

Then open once, while logged out of the public site is fine:

    https://ultimate.co.tz/ultitech/sync.php?key=ugt7k-sync-4m2p

That refreshes the footer script so the new quote button is loaded. Add to cart and checkout stay.

Cron
----

cPanel ? Cron Jobs, every 15 minutes. Do not put > in the command.

    /usr/local/bin/ea-php82 /home/ultimate/public_html/ultitech/cron.php

If the UltiTech catalog is down, the job still sends pending website orders and queued quotes. Customers still browse, add to cart, and request quotes from the shop database.

Customer flow
-------------

On a product page, Add to quote stores the product in the browser. Quantity is not limited by the cart maximum. The Quote button stays on every page. Request quotation saves the list in the shop database and shows: Our sales person will contact you shortly. The quote number looks like QT-ULT-2026-000045. If UltiTech is offline, the quote stays in the shop and the cron retries the same number, so UltiTech does not create a second copy.

Admin flow
----------

/admin/quotes lists number, customer, phone, products, status, and sync state. Open a quote to change status (Pending, Contacted, Quoted, Accepted, Rejected), write internal notes, print, or retry sync. Requested quantities are not edited there. Customers never see internal notes or sync errors.

Rollback
--------

1. Remove the require line from routes/admin.php.
2. Remove the Quotes sidebar snippet.
3. Stop the cron job.
4. In phpMyAdmin, delete the footer script row only if you want the button gone: business_settings.type = footer_script, value containing ultitech/quote-button.js. Clear storage/framework/cache/data afterwards.
5. Leave quotes, quote_items, and sync_queue in place unless you intentionally run the migration down.

Do not delete products, orders, ultitech_links, or ultitech_orders as part of this rollback.
