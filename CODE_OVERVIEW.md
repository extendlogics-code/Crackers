# Crackers Code Overview

This document drills into the implementation details behind the Crackers ordering portal. It complements the project README by outlining architecture, key modules, and the execution flow for both customer-facing and back-office features.

## Architecture Summary
- **Presentation layer**: Standalone PHP templates (`shop.php`, `checkout.php`, `invoice_preview.php`, etc.) render views and post data to server endpoints.
- **API layer**: `/api/save_order.php` and `/api/order_pdf.php` expose JSON/form endpoints that power checkout and invoice downloads.
- **Library layer** (`lib/`): Shared utilities for database access (`db.php`), authentication (`auth.php`), routing helpers (`routes.php`), category data, and the custom PDF engine (`pdf.php`).
- **Inventory portal** (`/inventory`): A lightweight admin area for managing catalogue data and manual orders, sharing the same libraries and schema as the storefront.
- **Storage** (`/storage`): Holds generated invoices, email audits, and notification payloads; ensure this directory is writable and not publicly listed.

## Directory Reference
| Path              | Purpose                                                                                                      |
|-------------------|--------------------------------------------------------------------------------------------------------------|
| `shop.php`        | Product catalogue and cart UI.                                                                               |
| `checkout.php`    | Collects customer details; posts order payloads to the save API.                                             |
| `api/save_order.php` | Validates orders, persists customer/order/item records, and orchestrates PDF generation + notifications. |
| `api/order_pdf.php`  | Serves stored invoices or rebuilds previews for admins.                                                   |
| `lib/pdf.php`     | Custom PDF renderer (A4 invoices) with support for multi-page item tables.                                   |
| `lib/auth.php`    | Session management, role-based access, and CSRF helpers for the inventory portal.                            |
| `lib/db.php`      | Lazy PDO connector that auto-creates the database if missing.                                               |
| `inventory/`      | Admin UI with dashboards, product and order editors, and user management.                                    |
| `storage/`        | Generated invoices (`storage/invoices`), email `.eml` archives, and queued notifications.                    |

### Key Modules (Deep Dive)
- **`lib/db.php`**: Wraps PDO with lazy instantiation and auto-creation of the configured database. Connections are cached per request. When “Unknown database” errors surface, it falls back to creating the schema automatically to simplify first-time deployment.
- **`lib/pdf.php`**: Low-level PDF assembler. It builds objects manually instead of depending on external libraries, making it easy to audit. Pagination logic slices item arrays, so adjustments to `itemsPerPage` or column layout are centralised.
- **`lib/auth.php`**: Manages admin sessions with hardened cookie settings, rate-limited login attempts, and CSRF utilities. Configuration-based logins are elevated to `superadmin` for emergency access.
- **`api/save_order.php`**: All-in-one pipeline for checkout—input validation, schema migrations, transactional persistence, PDF rendering, and notification queueing. Most business rules live here.
- **`api/order_pdf.php`**: Serves stored invoices or rehydrates a preview when an order is not finalised. It respects authentication when called from the inventory portal.

## Order Placement Flow (Code Perspective)
1. **Checkout submit** (`checkout.php`): Posts cart + customer JSON to `/api/save_order.php`.
2. **Input parsing**: `json_input()` in the API handles JSON and form submissions safely.
3. **Schema guard**: `ensure_schema()` creates or migrates tables (`customers`, `orders`, `order_items`, `products`, `admin_users`) to avoid deployment friction.
4. **Pricing enforcement**: For each item, the API fetches canonical price/discount data from `products`, recomputes totals, and rejects invalid quantities.
5. **Customer upsert**: Customer records are inserted or updated (by email) and linked to the order.
6. **Order persistence**: Order + items are stored inside a transaction to guarantee consistency.
7. **Invoice rendering**: The payload is passed to `build_order_pdf()` (`lib/pdf.php`), which now supports multi-page content:
   - Items are chunked into 18-line groups.
   - Each chunk yields a dedicated PDF page object with headers, tables, and continuation notices.
   - Totals, amount-in-words, and signature blocks appear only on the final page.
8. **File storage**: Generated PDF bytes are written to `storage/invoices/order-<id>.pdf`; the path is saved on the order row.
9. **Notifications**: Email and notification stubs are queued (`storage/emails`, `storage/notifications`) for external integration.
10. **Response**: The API returns JSON with the order ID, totals, and invoice URL.

## PDF Engine Highlights (`lib/pdf.php`)
- Implements a self-contained PDF writer to avoid third-party dependencies.
- Works with JPEG-based QR codes; dimensions are read via the `jpeg_dims()` helper.
- Uses reusable rendering callbacks (`$drawRect`, `$drawLine`, `$addText`) to build each page.
- Page numbering (`Page X/Y`) and “continued on next page” markers improve readability for long orders.
- Objects are assembled manually (font, XObject, content streams, page dictionaries) before writing the xref table and trailer.

## Inventory Portal Flow
1. **Authentication**: `inventory/login.php` uses `admin_login()`; credentials come from the database or fallback to `config.php`.
2. **Dashboard** (`inventory/dashboard.php`): Summarises orders, provides quick links to edit forms, and exposes invoice downloads through `api/order_pdf.php`.
3. **Product management** (`inventory/product.php`):
   - Allows CRUD on catalogue entries, including image uploads.
   - Uses CSRF tokens from `admin_csrf_token()` and applies role checks to prevent unauthorised edits.
   - Deletes associated media files to avoid orphans.
4. **Order adjustments** (`inventory/order_edit.php` / `order_delete.php`):
   - Support for manual entry or correction of orders.
   - Updates cascade to order items; deletions run through CSRF validation before removal.
5. **User management** (`inventory/users.php`, `inventory/register.php`, `inventory/change_password.php`): Maintains `admin_users` with role distinctions (`admin`, `co_worker`, etc.).

## Security Touchpoints
- Sessions use secure cookie settings and regenerate IDs post-login to mitigate fixation.
- API endpoints send security headers (`nosniff`, `DENY`) and recompute pricing server-side to prevent tampering.
- CSRF tokens are required for inventory-side POST actions.
- Sensitive directories (`storage/`, `config.php`) must be protected at the web server level; `.htaccess` rules help hide private assets.
- Login attempts are rate-limited per session. Consider augmenting with IP-based throttling and captcha for public deployments.
- Passwords for admin users are stored as Bcrypt hashes (`password_hash`), and role checks guard destructive actions (delete, user management).
- For outbound notifications and emails, redact customer data when storing audit `.eml` files if regulatory compliance requires it.

## Caching & Performance Considerations
- **Database caching**: The code currently hits MySQL for every save. Implement a thin caching layer (Redis/Memcached) if catalogue reads dominate and updates are infrequent.
- **Generated invoices**: PDFs are stored on disk (`storage/invoices`). Reuse existing files unless the order has changed to avoid regenerating on each download.
- **HTTP-level caching**: Add `Cache-Control` headers on static assets (images, CSS) via server configuration. Dynamic endpoints intentionally disable caching.
- **Opcode caching**: Enable PHP OPcache in production to speed up script execution and reduce CPU usage.
- **Session storage**: PHP’s default file-based sessions can become a bottleneck on high load—consider shared stores (Redis, Memcached) when scaling to multiple nodes.
- **Schema guard**: Because `ensure_schema()` runs per request, you might cache the schema version (e.g., in a `settings` table) and skip the DDL once the migration is applied.

## Working with Git
- Keep large generated files (e.g., `storage/invoices/*.pdf`) out of version control; use `.gitignore`.
- Feature development should follow the workflow outlined in `README.md` (branch → commit → PR).
- Before pushing, run a smoke test: place a sample order through checkout and ensure the PDF splits correctly across pages.
- When merging changes that affect schema, double-check `ensure_schema()` to keep migrations idempotent.

## Extending the Codebase
- **Adding payment gateways**: Wrap external calls inside `api/save_order.php` after the transaction but before notifications to ensure the invoice reflects final status.
- **Improving notifications**: Replace the file-based queue in `queue_notifications()` with a real message broker or SMS provider integration.
- **Testing**: Introduce PHPUnit or Pest tests targeting library functions (e.g., `build_order_pdf`) and API endpoints using a temporary SQLite/MySQL instance.
- **Front-end refresh**: Consider extracting UI components into a templating engine or modern framework; current pages are plain PHP/HTML mixes.

For any deep dive into a specific module, cross-reference the file paths noted above. Let the maintainers know if additional diagrams or doc sections would clarify future enhancements.
