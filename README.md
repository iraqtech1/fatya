# فاتيا — التطبيق ولوحة الإدارة

Vue 3 / Vite PWA with a shared Node.js API and persistent SQLite database.
Use Node.js 22.13 or newer.

## Local development

1. Run `npm ci`, then `npm run dev`.
2. Open `http://127.0.0.1:5173`. Administration is at `#/admin`.
3. The development launcher prints a temporary admin password if `ADMIN_PASSWORD` is absent. Username defaults to `masal`. Copy `.env.example` to `.env` to configure credentials.

Customers register and sign in with an Iraqi phone number and a password of 8–128 characters. Passwords are stored as salted scrypt hashes in SQLite. Existing accounts without a password need an administrator to set one in the customer editor. Local development also enables preview OTP codes for the legacy API unless `DEV_OTP=false` is configured.

## Shared data

- Products, visibility, denominations, prices, card images and denomination images are managed by the dashboard.
- The cards dashboard has separate card-denomination and company tabs. Every denomination displays its own image, value and price; missing denomination images are shown explicitly. The editor uploads and previews each denomination image directly.
- Manage manufacturer and supplying-company names in the Companies tab, then assign each card to those companies. Names, roles and assignments persist on the server. Existing catalogs start with unassigned companies; renaming a company preserves its links, and roles used by cards cannot be removed.
- Top and middle slider uploads, ordering and removals persist and appear on other devices using the same server.
- Accounts, profile changes and suspension are shared. Guests keep their own orders and tickets through their server session.
- Checkout creates an idempotent **simulation order**, priced by the server and initially marked `قيد المراجعة`. Admin status changes appear in the customer's orders.
- Support tickets and admin replies persist and synchronize.
- Store name, support contacts and low-stock settings are shared. Support contacts appear on the support screen.
- In the GitHub Pages preview, these settings persist in browser localStorage and synchronize between tabs of the same origin. They are local to that browser; cross-device settings use the shared backend deployment.
- Excel imports update persistent stock. The server revalidates rows and rejects duplicate codes after a restart. Imported codes stay private to the server and are never delivered by simulation checkout.
- Supplier records persist. Supplier API calls and provider secrets are not implemented.

The foreground app refreshes every three seconds. Admin writes include a database revision; stale edits are rejected instead of silently overwriting concurrent changes. Errors appear in the interface. Customer APIs return only that customer's orders and tickets. Administration requires a server-validated session. Credentials stay out of the frontend bundle; sessions use HttpOnly, SameSite cookies.

## Deployment

For shared phone/password login across devices, use the ready-to-deploy Render Blueprint in `render.yaml`. Follow [DEPLOYMENT.md](DEPLOYMENT.md). It creates a paid service with a persistent disk; review costs in Render before applying. Preview accounts on GitHub Pages do not transfer to the new server.

GitHub Pages serves static files and cannot run this API. The existing Pages workflow explicitly builds **preview mode** to preserve the static demo. That preview uses in-memory data and browser-local sliders. Uploading source to GitHub alone does not enable cross-device data.

For shared operation, deploy the Node server and frontend together on a host with persistent disk:

1. Run `npm ci` and `npm run build` without `VITE_DATA_MODE=preview`.
2. Set a long random `ADMIN_PASSWORD`, `ADMIN_USER`, `DEV_OTP=false`, and `NODE_ENV=production` on the server.
3. Run `npm start`. Configure an HTTPS reverse proxy to forward the same origin to this service. Set `HOST` and `PORT` to suit the host; the default bind is localhost and the port is 3001.
4. Persist and back up `server/data/`, including SQLite WAL files when taking a live backup. The database, `.env` and credentials must never be committed or exposed as static files. Only `dist/` is served.
5. Use a single Node process initially. OTP challenges and rate limits are process-local and require shared storage before deploying multiple instances.

For legacy OTP verification, configure `OTP_WEBHOOK_URL` (HTTPS) and `OTP_WEBHOOK_TOKEN`. The endpoint receives `{"phone":"077...","code":"123456"}` with a Bearer token and must deliver the code through your WhatsApp provider, returning a successful HTTP status. Provider credentials belong on the delivery service or server. With preview OTP disabled and delivery unconfigured, legacy OTP requests fail explicitly. Phone/password registration and login do not require this webhook.

**Qi/ZainCash collection, payment webhooks, supplier fulfillment and real card-code issuance remain integrations to implement with the providers.** No real charge occurs. Simulation orders do not decrement real inventory or expose imported codes. Production financial checkout must use verified provider webhooks and transactional stock allocation.

## Verification

- `npm test`: UI logic plus API integration tests for persistence, sessions, customer isolation, admin permissions, server pricing, idempotent orders, support replies, images, settings, imports, conflicts, OTP and restart.
- `npm run check`: JavaScript syntax and Vue templates.
- `npm run build`: production PWA build.

Static PWA assets can work offline. Shared accounts, orders, administration and support require the server. API responses are not precached. Build static preview mode explicitly with `VITE_DATA_MODE=preview`.

Dashboard card lookup: the Cards section searches an exact inventory serial through an admin-only endpoint. XLSX imports support an optional `serial` column (store leading-zero identifiers as text); duplicate nonempty serials are rejected. Legacy imports without serials can be found using their code. Lookup returns card identity and denomination, never the redemption code when a separate serial is present. Preview mode searches inventory imported in the current session.
