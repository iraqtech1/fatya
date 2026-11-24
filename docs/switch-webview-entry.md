# Switch WebView entry

Launch URL: `https://fatyh.net/api/switch/launch`

Switch opens this URL with a URL-encoded `token` query parameter. The Laravel endpoint stores the opaque, unverified token encrypted in the server cache for ten minutes, sets an opaque HttpOnly cookie, then redirects to the same URL without the query string. The dedicated Vue entry uses `/api/switch/session` to display missing, expired or pending activation states. It does not store tokens in browser storage or return them to JavaScript. Production requires HTTPS. The API prefix bypasses the existing PWA navigation fallback.

This implements the launch transport only. It does not authenticate a Fatya customer, decrypt or validate a Switch identity, initiate a transaction, or confirm payment. `paymentEnabled` stays false. A synthetic token can verify the transport without contacting Switch.

Before activation, obtain the assigned provider credentials, environment URLs, encryption format and test vectors, token validation/expiry contract, and the agreed checkout, retry and reconciliation contract. Configure server/proxy access logs to omit or redact the initial query token before using real customer sessions; redirecting the browser does not remove the initial request from infrastructure logs. Never paste real sessions into test evidence or commits.

Validation includes encrypted cache storage, cookie properties, clean redirects, expiry, replacement, malformed input, throttling and HTTPS enforcement. Browser checks cover refresh persistence, invalid-token clearing, no token in browser storage or subrequest referrers, and mobile layout. No database schema changes are needed.

Deployed and verified on 24 November 2026. Production has additional, existing Switch payment/admin and work-queue routes that are not yet in this checkout. The deployment merged these two entry routes into the actual server route file and preserved all existing routes. Only the new entry HTML and its three hashed assets were uploaded; the main HTML, service worker and manifest were unchanged. The four public file hashes matched, public state stayed identical, health passed, and a synthetic session survived refresh with payment disabled. Rollback files and evidence are outside Git in `output/switch-entry-20261124` beside the checkout.

Checks after integrating latest main: frontend tests, JavaScript/Vue checks, build and bundle budgets passed; new backend tests passed (4 tests, 84 assertions). The complete local backend run had 172 tests and 1204 assertions, with one existing AccountsTest error because Sodium is unavailable in this Windows PHP runtime. No live financial APIs were called.
