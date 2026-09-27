# cargovelo-core

Shipments system for Cargo Velo (bike courier: Gent, Antwerpen, Brussel, Mechelen, Leuven).
One record per shipment from every channel, one status lifecycle, a dispatch desk, a courier
app with proof of delivery, a customer portal, and a priced export for the accounting package.

WordPress mu-plugin on **NTDST Core** (DI container, `ntdst_rest()`), React 19 + Vite frontend on
**@sakaniui/react**. This folder is the whole package: install it, it *is* the mu-plugin.

## What is in the box

| Area | Where | Who uses it |
|---|---|---|
| Website booking form | shortcode `[cargovelo_booking]` | guests, occasional B2B customers |
| Public track & trace | shortcode `[cargovelo_tracking]` on a page, link `?t=<token>` | recipients (no login) |
| Customer portal | shortcode `[cargovelo_app]` on a page, role `cv_customer` | contract customers: book, address book, CSV import, status, POD |
| Courier app | same shortcode, role `cv_courier`, mobile-first | couriers: today's stops, one-tap status, photo + signature, offline queue |
| Dispatch desk | wp-admin > Cargo Velo, capability `cargovelo_dispatch` | dispatchers: list, filters, saved views, board per hub, assign, price override, notes |
| Master data | same, capability `cargovelo_manage` | admin: customers, couriers, price lists, hubs/zones/cut-offs, settings |
| Export | wp-admin > Cargo Velo > Export | finance: delivered shipments with frozen price, CSV per customer per period |

Business rules live in `src/Modules/Shipment/ShipmentService.php`; the lifecycle is
`src/Domain/ShipmentStatus.php` (mirrored in `app/src/domain/status.ts` for the UI only).

## Install into the Bedrock site

Requires `netdust/ntdst-core` ^5.1 already in the site, PHP ≥ 8.2, WordPress ≥ 6.5.

1. Push this folder to its own git repository (e.g. `netdust/cargovelo-core`).
2. In the site's `composer.json`:
   ```json
   "repositories": [{ "type": "vcs", "url": "git@github.com:netdust/cargovelo-core.git", "no-api": true }],
   "require": { "netdust/cargovelo-core": "dev-main" }
   ```
   `composer/installers` puts it in `web/app/mu-plugins/cargovelo-core/`; Bedrock's autoloader loads
   `cargovelo-core.php` from its `Plugin Name` header. The built frontend (`assets/app/`) is committed,
   so the site needs no Node.
3. Load the site once (tables and roles are created on `init`, version-gated), then:
   ```bash
   wp option get cargovelo_schema_version   # 1
   wp eval "echo count(ntdst_get(\CargoVelo\Modules\Settings\SettingsService::class)->hubs());"   # 5
   ```
4. Create three pages and add the shortcodes: booking (`[cargovelo_booking]`), tracking
   (`[cargovelo_tracking]`, set its path under Instellingen → "Pad van de track & trace pagina"),
   portal/courier (`[cargovelo_app]`).
5. Give people roles: dispatchers `cv_dispatcher`, couriers `cv_courier` (then link the WordPress
   user id on the courier in wp-admin > Cargo Velo > Koeriers), customer users `cv_customer` (link
   them on the customer under "WordPress user-id's"). Administrators have dispatch + manage.
6. Mail goes through `wp_mail`; point Fluent SMTP at a real sender. Set the dispatch address under
   Instellingen.

If the site's theme does not run `NTDST_Bootstrap` (so `ntdst/features_ready` never fires), the
plugin boots itself from `init`; REST and admin still work.

## REST surface (`/wp-json/cargovelo/v1`)

| Prefix | Floor | Notes |
|---|---|---|
| `POST /public/quote`, `POST /public/bookings`, `GET /public/tracking/{token}`, `POST /public/tracking/{token}/instructions` | anonymous, rate-limited per IP | the whole anonymous surface; honeypot field `website` on bookings |
| `GET /me` | `read` | app context |
| `/ops/*` | `cargovelo_dispatch` (master-data writes: `cargovelo_manage`) | shipments, counts, dashboard, export, couriers, customers, price lists, settings |
| `/portal/*` | `cargovelo_book` | scoped to the user's customer id by the service, never by the route |
| `/courier/*` | `cargovelo_courier` | scoped to the user's courier id |

Authorization is decided on the route (`permission`), scope inside `ShipmentService` by `Actor`.
`tests/Unit/Handlers/RouteFloorsTest.php` pins every floor. The browser uses `wp.apiFetch`
(cookie auth + `X-WP-Nonce`); only `<img>`/download URLs carry `_wpnonce`.

## Data

Custom tables (prefix `wp_cv_`): `shipments`, `shipment_events` (append-only audit trail incl. proof
of delivery), `customers`, `addresses`, `couriers`, `price_lists`, `price_rules`. Addresses and the
price are **snapshotted** on the shipment at booking. Status changes are optimistic
(`UPDATE … WHERE status = :from`), so two people cannot both win. POD images are stored under
`uploads/cargovelo/pod/` (denied by `.htaccess`) and streamed by the scoped REST routes.

## Develop

```bash
# PHP
composer install
vendor/bin/phpunit                # 57 unit tests, no WordPress needed (tests/Stubs)

# Frontend
cd app && npm install
npm run dev                       # http://localhost:5174/?view=ops  (|portal|courier|booking|tracking, &role=admin)
npm run typecheck && npm test     # tsc + vitest
npm run build                     # -> ../assets/app (commit the result)
```

`npm run dev` runs against an in-memory mock API (`app/src/mock`) with the same rules as the PHP
service, seeded with realistic data. In WP_DEBUG without a build the plugin loads from the Vite dev
server on port 5174.

## Not in this version (deliberately)

Recurring rounds as templates, multi-stop shipments, webshop plugins (the REST API is there),
route optimisation, native apps, invoicing (export only). The default price list holds placeholder
tariffs until Cargo Velo hands over the real structure.
