# Warehouse Management

Warehouse inventory UI connected to the ERP stock module (`products`, `categories`, `stock`, `warehouses`).

## Access

Open via the ERP sidebar: **Stocks → Store Management**

Or directly: `http://localhost/public_html/store-management-system/index.php`

## Build

```bash
cd store-management-system
npm install
npm run build
```

Then reload `index.php` in the browser.

## Development

For UI development with hot reload, run `npm run dev` and use the Vite proxy — you must be logged into the ERP in the same browser for API session cookies to work when proxying to Apache.
