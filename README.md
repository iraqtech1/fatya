# فاتيا — التطبيق ولوحة الإدارة

Vue 3 + Vite PWA with a Laravel 13 API and MariaDB database. The customer application and dashboard retain their existing Vue components, design, API routes and response shapes. `#/admin` opens administration.

## Requirements and development

- PHP 8.3+ with the Laravel-required extensions, PDO MySQL and Argon2id password support. Sodium and PDO SQLite are needed only when importing old SQLite accounts.
- Composer 2, Node.js 22.13+ for frontend builds, and MariaDB 12.3.3 (the version verified for this migration).
- No Node.js server is required on production hosting.

1. Run `npm ci` at the repository root and `composer install --working-dir=backend`.
2. Copy `backend/.env.example` to `backend/.env`. Create a MariaDB database and set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Set a private `ADMIN_PASSWORD` of at least 12 characters. The default administrator name is `masal`.
3. From `backend/`, run `php artisan key:generate`, `php artisan migrate`, then `php artisan fatya:seed`.
4. Run `npm run dev` from the root and visit `http://127.0.0.1:5173`. Set `PHP_BIN` to the PHP executable if the default `php` is older than 8.3.

`fatya:seed` imports the established frontend catalog once and never overwrites an existing store. If source catalog defaults change, regenerate the checked-in seed with `node scripts/export-catalog.mjs`. Changing defaults does not replace live products.

## Data and security

Products, categories, companies, customers, orders, tickets, suppliers, imports, slides and events are persisted in separate MariaDB tables. JSON payloads retain the established Vue contract and product denomination arrays. Customer identities and order references also have indexed relational columns and uniqueness constraints. Inventory cards have separate indexed records, encrypted redemption codes, code fingerprints and exact, case-sensitive serials. Codes are never included in public or administrator state responses.

Customer registration and login use Iraqi phone numbers and passwords of 8–128 characters, hashed with Laravel Argon2id. Administrator credentials are configured only in the backend environment. Random session tokens are stored as hashes; cookies are HttpOnly and SameSite=Strict, with Secure enabled on production. API writes require same-origin JSON requests and the custom request header emitted by `src/api.js`. Rate limiting and OTP attempts persist across PHP restarts. Production never returns development OTP codes.

MariaDB transactions lock the store revision across workers; stale administrator edits receive HTTP 409. Public snapshots reveal only that customer's orders and tickets. Price and account identity are determined by the server, and repeat order references are idempotent. The frontend keeps its existing three-second foreground refresh.

## Existing functionality

- Product images and per-denomination images, category rename/visibility, company assignments and supplier records.
- Top/middle sliders, shared support contacts, privacy/about content and stock thresholds.
- Customer profile edits, suspension, administrator password changes, support replies and order status changes.
- Excel inventory import, duplicate code/serial checks and exact serial lookup, including leading zeroes.
- Guest sessions and the existing phone/password and legacy OTP API contracts.
- Customer notifications for orders, status changes, tickets and replies, with private read/unread status stored in MariaDB.

Qi/ZainCash payment collection, payment webhooks, supplier API fulfillment and actual delivery of redemption codes remain unimplemented integrations from the original project. Checkout remains a simulation: it does not charge or decrement real stock. A reset/legacy OTP delivery provider must be configured with `OTP_WEBHOOK_URL` and `OTP_WEBHOOK_TOKEN`; ordinary phone/password registration and login do not need it.

## Verification

- `npm run check` validates JavaScript and Vue templates.
- `npm test` runs frontend logic and preview tests.
- `npm run build:host` builds the connected Vue PWA and copies the build into `backend/public/`.
- From `backend/`, `php artisan test` runs PHP account normalization and legacy password compatibility tests.
- `npm run test:api` exercises a real Laravel HTTP server against MariaDB, including restart persistence, authorization, pricing, conflicts, imports, OTP, origin protection, hashed sessions, encrypted inventory and SQLite migration.
- The same suite also mounts the real Vue dashboard/store against Laravel to check settings conflicts, role cleanup, account suspension, failed background refreshes, notification isolation and persistence of every store collection.

For API tests, create a dedicated disposable database whose name ends in `_test`, set `FATYA_TEST_DATABASE`, then run the command. The runner resets **only that named test database**. It uses connection settings from `backend/.env`, optionally overridden by `FATYA_TEST_DB_HOST`, `FATYA_TEST_DB_PORT`, `FATYA_TEST_DB_USERNAME`, `FATYA_TEST_DB_PASSWORD`, and `PHP_BIN`. GitHub Actions also defines this MariaDB integration suite.

## Deployment and legacy migration

See [DEPLOYMENT.md](DEPLOYMENT.md). GitHub Pages remains an explicit static preview (`VITE_DATA_MODE=preview`) with browser-local data and preview credentials `iraq` / `iraq`. It cannot run Laravel or MariaDB. Production uses the connected build and server-side administrator credentials.

The old Node API and Render SQLite blueprint have been removed from the active application. Git history retains them for reference. A read-only, one-time import command preserves actual legacy accounts and inventory; it does not transfer browser-local GitHub Pages demo accounts.
