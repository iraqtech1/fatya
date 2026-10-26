# فاتيا — التطبيق ولوحة الإدارة

Vue 3 + Vite PWA with a Laravel 13 API and MariaDB database. The customer application and dashboard retain their existing Vue components, design, API routes and response shapes. `/admin` opens administration on the connected deployment; the static GitHub Pages preview retains `#/admin`.

لإكمال العمل من جهاز الشركة، ابدأ بـ[دليل التنصيب والتشغيل على Windows](docs/company-setup-ar.txt)، ثم اقرأ [ملف المتابعة: المنجز والباقي ونتائج الفحوصات](docs/project-handoff-ar.txt). أمثلة كلمات المرور في الدليل نصوص بديلة فقط؛ أنشئ بيانات بيئتك محلياً.

## Requirements and development

The browser storefront uses the responsive Fatya Premium layout and allows visitors to browse the shared catalog and product details without an account. The default is light with the shared scenic theme control offering dark mode; web, PWA and administrator theme preferences are saved separately. Arabic, Sorani Kurdish (`ckb`, RTL) and English (LTR) are available from the browser header and sign-in screens. The content fills the viewport with responsive gutters and 2/3/4/6/8 product columns. Purchase, account and order access require the existing customer login or registration. Login resumes the selected denomination, quantity and payment method without submitting an order automatically. Both surfaces use the same `/api` endpoints, customer session and MariaDB database. Installed PWAs and `/app` keep the application interface; `/admin` keeps administration.

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

Checkout now reserves actual imported inventory for 15 minutes by default. Reservations atomically remove the held quantity from available stock. Cancellation/expiry releases unpaid holds; verified payment permanently assigns the held codes to the order. Delivery is idempotent and an inventory card can appear in the delivery ledger only once. The existing copy button retrieves codes only for the active owner of a paid, delivered order; neither shared state nor administrator state exposes codes.

The connected catalog exposes only per-denomination availability booleans, while exact quantities remain private to administration. Out-of-stock denominations cannot start checkout. Orders show reservation expiry and payment/delivery state, enable code copying only after confirmed delivery, and allow the active owner to cancel a still-pending reservation through `POST /api/orders/{id}/cancel`. Cancellation is idempotent and serialized against payment settlement; payments under review or already delivered cannot be cancelled through this action. Before the real gateway is connected, the checkout button explicitly creates a reservation and the inactive payment-detail inputs collect no card information.

Administration reads available/reserved/delivered inventory counts and payment receipts from the backend. Serial lookup includes inventory state and its assigned order without returning redemption codes. Payment movements and review reasons come from receipts, while confirmed sales require payment and delivery proof; a legacy `completed` label alone is excluded. These are read views, not a manual payment-confirmation or refund mechanism. Administrative projections are cleared when leaving the dashboard and are absent from public/customer snapshots.

Real Qi/ZainCash collection and authenticated payment webhooks are **not connected yet**. Browser requests and administrator status changes cannot confirm payment. Until a gateway adapter is implemented, use only the explicitly enabled local/test CLI simulator documented below. Supplier API fulfillment is also deferred. A reset/legacy OTP delivery provider must be configured with `OTP_WEBHOOK_URL` and `OTP_WEBHOOK_TOKEN`; ordinary phone/password registration and login do not need it.

## Reservation and delivery lifecycle

- Import cards before checkout; insufficient stock returns HTTP 409 without a partial hold or order.
- The server freezes amount, currency, product denomination and quantity when reserving. Reusing an order reference with changed contents is rejected.
- `OrderFulfillment::confirm(VerifiedPayment)` is an internal boundary for a future gateway adapter **after it verifies provider authenticity and successful settled payment**. No HTTP confirmation/simulation endpoint is exposed.
- A matching receipt delivers the precise reserved cards in one MariaDB transaction, marks the order completed and notifies the owner. Transaction IDs and event IDs are deduplicated per provider. Retrying cannot reassign a card or issue extra codes.
- Amount/currency/provider mismatch, late/cancelled reservations, inactive customers or incomplete allocation go to payment review without exposing codes. An additional payment for a delivered order also goes to review and does not deliver more stock. No automatic financial refund is implemented.
- A corrupt encrypted item rolls the entire acceptance/delivery transaction back; no partial delivery is committed.
- Run `php artisan fatya:expire-reservations` from the backend, or use the Laravel scheduler every minute. A new checkout also releases expired holds before allocating. Configure `RESERVATION_MINUTES` (1–60, default 15). Five active holds per customer are allowed.
- Legacy simulation orders have no payment/reservation proof and are never converted automatically into paid orders. Their codes cannot be retrieved. New orders must reserve inventory through the new flow.

For isolated local testing only, set `APP_ENV=local` (or `testing`) and explicitly enable `ALLOW_PAYMENT_SIMULATION=true`. In `backend/`, simulate a receipt using the frozen order amount and provider:

```sh
php artisan fatya:simulate-payment ORDER-UUID --amount=2100 --provider=Qi --transaction=local-receipt-001 --event=local-event-001
```

This command charges nothing. It refuses to run in production even when the enable flag is true, and is disabled by default. Never expose it through a web route. Keep `ALLOW_PAYMENT_SIMULATION=false` on deployed environments. It prints receipt outcome only, never redemption codes. The owner retrieves codes through `GET /api/orders/{id}/codes` only after successful delivery; responses use `Cache-Control: no-store`. Keep code payloads out of logs, analytics, exports and the PWA cache.

## Verification

- `npm run check` validates JavaScript and Vue templates.
- `npm test` runs frontend logic and preview tests.
- `npm run build:host` builds the connected Vue PWA and copies the build into `backend/public/`.
- From `backend/`, `php artisan test` runs PHP account normalization and legacy password compatibility tests.
- `npm run test:api` exercises a real Laravel HTTP server against MariaDB, including restart persistence, authorization, pricing, conflicts, imports, OTP, origin protection, hashed sessions, encrypted inventory and SQLite migration.
- The same suite also mounts the real Vue dashboard/store against Laravel to check settings conflicts, role cleanup, account suspension, failed background refreshes, notification isolation and persistence of every store collection.
- Reservation tests run two separate PHP server processes against one MariaDB database, race for the last card, race cancellation/payment, replay receipts, and cover expiry/reuse, money checks, multi-card atomic rollback, customer isolation and the production simulator guard. Payment receipts in tests are synthetic; no provider network request or charge occurs.

For API tests, create a dedicated disposable database whose name ends in `_test`, set `FATYA_TEST_DATABASE`, then run the command. The runner resets **only that named test database**. It uses connection settings from `backend/.env`, optionally overridden by `FATYA_TEST_DB_HOST`, `FATYA_TEST_DB_PORT`, `FATYA_TEST_DB_USERNAME`, `FATYA_TEST_DB_PASSWORD`, and `PHP_BIN`. GitHub Actions also defines this MariaDB integration suite.

## Deployment and legacy migration

See [DEPLOYMENT.md](DEPLOYMENT.md). GitHub Pages remains an explicit static preview (`VITE_DATA_MODE=preview`) with browser-local data and preview credentials `iraq` / `iraq`. It cannot run Laravel or MariaDB. Production uses the connected build and server-side administrator credentials.

Pushes and pull requests run frontend and backend checks without publishing either the production site or the Pages preview. Pages publishing is a separate manual workflow dispatch on `main`; do not dispatch it unless preview publication is requested. cPanel deployment remains a separate, explicitly authorized operation.

The old Node API and Render SQLite blueprint have been removed from the active application. Git history retains them for reference. A read-only, one-time import command preserves actual legacy accounts and inventory; it does not transfer browser-local GitHub Pages demo accounts.
