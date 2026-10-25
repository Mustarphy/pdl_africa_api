# PDL Africa — API (Laravel)

REST API for the PDL Africa storefront and its catalogue admin dashboard. The
React frontend is a separate project (`../pdl_africa_frontend`).

> **Status:** catalogue (collections, categories, products, variants, images,
> search), administrator authentication, customer accounts and the order
> lifecycle (checkout, Paystack payments, order tracking, admin fulfilment)
> are implemented. Saved addresses, discounts and refunds are not.

## Stack

- Laravel 13 (PHP 8.3 – 8.5; developed on PHP 8.5)
- MariaDB/MySQL via `pdo_mysql` (local: XAMPP MariaDB 10.4)
- Laravel Sanctum 4 (SPA cookie authentication for administrators)
- GD for image processing (JPEG/PNG/WebP in, WebP out)
- PHPUnit 12, Laravel Pint

## Windows: use PHP 8.5 for this project

This machine has several PHP versions. The one on `PATH` is XAMPP's **8.2**,
which Laravel 13 does not support. PHP 8.5 lives in `C:\php85` and is not on
the global `PATH` (so other projects are unaffected). Put it first for the
current terminal session only:

```bash
# Git Bash
export PATH="/c/php85:$PATH"
# PowerShell
$env:Path = "C:\php85;$env:Path"
```

Check with `php -v` (must show 8.5) before running `php artisan` or
`composer`. Required extensions (enabled in `C:\php85\php.ini`): ctype, curl,
dom, fileinfo, filter, hash, mbstring, openssl, pcre, pdo, pdo_mysql,
session, tokenizer, xml, intl, **gd** (image processing) and **exif** (photo
orientation).

## Setup

```bash
composer install
cp .env.example .env          # then fill in DB credentials
php artisan key:generate      # new environments only; never rotate casually
php artisan migrate           # additive; never use migrate:fresh on real data
php artisan storage:link      # serves uploaded images from public/storage
php artisan pdl:create-admin owner@example.com   # first administrator
```

Use a dedicated database user with privileges on this database only; never
`root`.

### Provisioning administrators

There is **no registration endpoint** and no default administrator. Create
or promote one from the server's command line:

```bash
php artisan pdl:create-admin <email>
```

The command prompts (hidden input, typed twice) for a password that must meet
`Password::defaults()` — at least 12 characters with upper and lower case, a
number and a symbol. If the email already belongs to a user it asks before
promoting them. `role` is not mass-assignable, so no HTTP request can make
someone an administrator.

To remove access, change `role` back to `customer` (or delete the user) with
`php artisan tinker`; their next request is rejected.

## Docker and Render

`Dockerfile` builds a production image (PHP 8.4 + Apache, port `$PORT`,
default 10000); `render.yaml` is the Render Blueprint. See
[DEPLOYMENT.md](DEPLOYMENT.md) for local Docker testing, environment
variables, database setup, migrations and limitations.

## Everyday commands

| Task | Command |
| --- | --- |
| Dev server (http://localhost:8000) | `composer dev` or `php artisan serve` |
| Tests | `composer test` (or `php artisan test`) |
| Format check / fix | `vendor/bin/pint --test` / `vendor/bin/pint` |
| Migrations | `php artisan migrate` (status: `php artisan migrate:status`) |
| Routes | `php artisan route:list --path=api` |
| Create administrator | `php artisan pdl:create-admin <email>` |
| Import legacy catalogue as drafts | `php artisan catalog:import-legacy <manifest> --dry-run` |
| Apply official collections + draft occasions | `php artisan catalog:sync-taxonomy --dry-run` (then without `--dry-run`) |
| Dependency audit | `composer audit` |

Tests use in-memory SQLite and a fake disk (see `phpunit.xml`) and never touch
the MariaDB database or real uploads. Model factories exist for tests only;
there is **no catalogue seeder** — real products are entered by an
administrator (or imported as drafts, see below).

## Environment variables

Beyond Laravel's defaults (`.env.example` lists all of them):

| Variable | Purpose | Local value |
| --- | --- | --- |
| `FRONTEND_URLS` | Exact browser origins allowed by CORS (comma-separated) | `http://localhost:5173` |
| `SANCTUM_STATEFUL_DOMAINS` | Hosts whose requests get cookie sessions (admin and customer accounts) | `localhost:5173` |
| `FRONTEND_URL` | Storefront origin used in account email links (defaults to the first `FRONTEND_URLS` entry) | `http://localhost:5173` |
| `ACCOUNT_LINK_EXPIRE_MINUTES` | Lifetime of verification and email-change links | `60` |
| `MAIL_*` | Mail transport for account and order emails (`log` locally; SMTP in production) | `MAIL_MAILER=log` |
| `PAYSTACK_SECRET_KEY` | Paystack secret key (server only; also verifies webhooks) | `sk_test_…` |
| `PAYSTACK_CURRENCY` | Must equal the catalogue currency | `NGN` |
| `SHIPPING_FEE_*`, `SHIPPING_ESTIMATE_*` | Per zone: fee in whole naira, `collect` (customer pays the rider on delivery), or blank (zone off); optional estimate | e.g. `collect` |
| `SESSION_DOMAIN` | Cookie domain; set to the shared parent domain in production | `null` |
| `SESSION_SECURE_COOKIE` | HTTPS-only cookies; **must be `true` in production** | `false` |
| `CATALOG_IMAGE_DISK` | Filesystem disk for catalogue images | `public` |

Catalogue limits (upload size, minimum dimensions, generated widths, WebP
quality, images per product, page sizes) live in `config/catalog.php`.

## Catalogue data model

All prices are integers in **minor units (kobo)** with a `currency`
(`NGN`); ₦150,000 is stored as `15000000`. Nothing uses floats.

| Table | Purpose / key rules |
| --- | --- |
| `categories` | Garment classification: Agbada, Suits, Kaftans, Pants. `slug` unique. Cannot be deleted while products use it. |
| `collections` | Merchandising groups shown on the storefront. `slug` unique, `status`, cover and banner image (+ alt text), SEO fields, `legacy_key` unique. |
| `products` | `sku` and `slug` unique, `category_id` (restrict on delete), optional base `price`, descriptions, material, care, `status`, `is_featured`, `display_order`, SEO, `legacy_key` unique, `search_text` (denormalised, indexed for search). |
| `collection_product` | Many-to-many with `position`; composite primary key. |
| `occasions` | What customers dress for (Weddings, Formal Events…): `slug` unique, description, image (+ alt), `display_order`, `status`. |
| `occasion_product` | Explicit product ↔ occasion assignments (many-to-many, `position`). Deleting either side only removes the link. |
| `product_variants` | Size/colour options: `sku` unique, optional `price` (overrides the product's), `stock_quantity`, `availability` (`available`, `made_to_order`, `out_of_stock`), `is_active`. `(product_id, size, color)` unique. |
| `product_images` | Generated image set per upload: `storage_key` unique, dimensions, generated widths, `alt_text`, `display_order`, `is_primary`, optional `product_variant_id`. A generated column + unique index guarantees **at most one primary image per product** at the database level. |
| `activity_logs` | Who created/updated/published/archived/deleted what, and which **field names** changed (values are not logged). |
| `users.role` | `customer` (default) or `admin`. |

### Status and publication rules

Every collection, product and category has a `status`: `draft`,
`published` or `archived`. Public endpoints return **published records
only**; drafts and archived records answer 404 and never appear in lists,
search, suggestions, counts or the legacy map.

Publishing is refused (HTTP 422, `errors.publication`) until the record is
complete (`App\Services\Catalog\PublicationRules`):

- **Product:** a category, at least one collection, a primary image, a short
  or full description, at least one active variant if it has variants, and a
  price (on the product, or on every active variant).
- **Collection:** a banner or cover image.

Once a product or collection has been published its **slug is locked** so
shared links never break. Only never-published drafts can be deleted;
anything that has been public is archived instead. All multi-table writes run
in database transactions.

## API

Base URL (local): `http://localhost:8000/api/v1`. Errors are JSON; validation
errors use Laravel's `{ "message", "errors": { field: [messages] } }` shape.
Public catalogue responses send `Cache-Control: public, max-age=60` with an
ETag; admin responses send `no-store, private`.

### Public (no authentication)

| Method & path | Description |
| --- | --- |
| `GET /health` | `{"status":"ok"}` |
| `GET /collections` | Published collections with published-product counts |
| `GET /collections/{slug}` | One published collection |
| `GET /categories` | Published categories |
| `GET /products` | Search / browse published products (see query below) |
| `GET /products/{slug}` | Product detail with variants and images |
| `GET /search/suggestions?q=` | Top products and collections for the search box |
| `GET /catalog/legacy-map` | Which legacy keys/slugs published records replace (see migration) |
| `GET /occasions` | Published occasions with published-product counts |
| `GET /occasions/{slug}` | One published occasion |

`GET /products` query parameters: `q`, `collection[]`, `category[]`, `occasion[]`,
`garment[]`, `size[]`, `color[]` (slugs/values; OR within a filter, AND
across filters), `min_price`/`max_price` (kobo), `featured`, `sort`
(`relevance`, `featured`, `newest`, `name`, `price_asc`, `price_desc`),
`page`, `per_page` (max 48) and `facets=1` to include filter counts and the
price range. Each facet's counts ignore that facet's own selection so options
stay selectable.

Example `GET /products/olive-senator` (abridged):

```json
{
  "data": {
    "id": 1,
    "slug": "olive-senator",
    "name": "Olive Embroidered Senator",
    "sku": "PDL-SEN-OLIVE",
    "short_description": "Embroidered senator with matching trousers.",
    "material": "Cotton blend",
    "price": null,
    "price_range": {
      "min": { "amount": 8500000, "currency": "NGN" },
      "max": { "amount": 9000000, "currency": "NGN" }
    },
    "category": { "slug": "senator", "name": "Senator" },
    "collections": [{ "slug": "senator", "name": "Senator Wear" }],
    "images": [{
      "id": 3, "alt": "Olive senator tunic on a gold mannequin",
      "src": "http://localhost:8000/storage/catalog/products/<uuid>-751.webp",
      "srcset": [{ "url": "…-480.webp", "width": 480 }, { "url": "…-751.webp", "width": 751 }],
      "width": 751, "height": 1080
    }],
    "variants": [
      { "id": 7, "sku": "PDL-SEN-OLIVE-M", "size": "M", "color": null,
        "price": { "amount": 8500000, "currency": "NGN" },
        "availability": "available", "is_available": true, "image_id": null }
    ],
    "legacy_key": "dev-senator-olive-embroidered"
  }
}
```

### Admin (`/admin/*`, administrators only)

| Method & path | Description |
| --- | --- |
| `POST /admin/login` | Email + password → session cookie (rate limited) |
| `POST /admin/logout` | Ends and invalidates the session |
| `GET /admin/me` | Current administrator (401 when signed out) |
| `GET /admin/dashboard` | Real catalogue counts, items needing attention, recent activity |
| `GET/POST /admin/categories`, `PUT/DELETE /admin/categories/{id}` | Category CRUD |
| `GET/POST /admin/collections`, `GET/PUT/DELETE /admin/collections/{id}` | Collection CRUD (list filters: `q`, `status`) |
| `POST /admin/collections/{id}/status` | `{"status": "draft" \| "published" \| "archived"}` |
| `POST/DELETE /admin/collections/{id}/images/{cover\|banner}` | Upload (`image`, `alt`) or remove a collection image |
| `GET/POST /admin/products`, `GET/PUT/DELETE /admin/products/{id}` | Product CRUD incl. variants, collection and occasion (`occasion_ids`) assignment (list filters: `q`, `status`, `collection_id`, `category_id`, `missing_images`, `sort`, `direction`, `per_page`) |
| `POST /admin/products/{id}/status` | Publish / unpublish / archive |
| `GET/POST /admin/occasions`, `GET/PUT/DELETE /admin/occasions/{id}` | Occasion CRUD (delete only if never published) |
| `POST /admin/occasions/{id}/status` | Publish / unpublish / archive |
| `PUT /admin/occasions/{id}/products` | Replace the occasion's products (`product_ids`) |
| `POST/DELETE /admin/occasions/{id}/image` | Upload (`image`, `alt`) or remove the card image |
| `POST /admin/products/{id}/images` | Upload `images[]` (+ `alt_texts[]`) |
| `PUT /admin/products/{id}/images/order` | Reorder (`ids`) |
| `PATCH /admin/products/{id}/images/{image}` | Alt text, primary flag, linked variant |
| `DELETE /admin/products/{id}/images/{image}` | Delete image and its files |

Admin responses include drafts plus `publication_issues` (why a record can't
be published yet). Image routes use scoped bindings, so an image ID from
another product returns 404.

## Administrator authentication

Sanctum **SPA cookie authentication**: the admin dashboard calls
`GET /sanctum/csrf-cookie`, then `POST /api/v1/admin/login`. Laravel issues
an HttpOnly session cookie; every state-changing request must echo the
`XSRF-TOKEN` cookie in the `X-XSRF-TOKEN` header (Axios does this). **No
token is stored in the browser.**

- Every admin route requires `auth:sanctum` **and** the `manage-catalog`
  gate (`role = admin`). Hiding buttons in React is never relied on.
- Login failures (wrong password, unknown email, non-admin) return the same
  generic message. Throttled at 5/min per email+IP and 20/min per IP.
- The session ID is regenerated on login and invalidated on logout.
- Requests from origins not in `SANCTUM_STATEFUL_DOMAINS` get no session.

## Customer accounts

Customers use the same Sanctum cookie session as the admin (the storefront
calls `/api/v1/account/*` through its own origin, `VITE_ADMIN_API_BASE_URL`).
Only `role = customer` accounts can sign in here (gate
`use-customer-account`); administrators keep `/admin`, and `role` is never
taken from request input.

| Method | Path (`/api/v1/account/…`) | Auth | Notes |
| --- | --- | --- | --- |
| POST | `register` | guest | Creates the customer, signs them in (unverified) and emails a verification link. 10/min, 60/h per IP |
| POST | `login` | guest | `email`, `password`, `remember`. Same message for every failure. 5/min per email+IP, 30/min per IP |
| POST | `logout` | customer | Invalidates the session and rotates the remember token |
| GET | `me` | customer | Own account only (no ids, role or hashes) |
| POST | `email/verification-notification` | customer | Resend the link. 2/min, 6/h |
| POST | `email/verify/{id}/{hash}?expires&signature` | link | Signed, expiring link (60 min) |
| POST | `forgot-password` | guest | Always the same response (no account enumeration). 3/min per email+IP, 30/h per IP; the broker also sends at most one email per address per 60 s |
| POST | `reset-password` | token | Laravel password broker: hashed single-use token, 60 min. Signs out every device |
| PATCH | `profile` | verified | `name` |
| PUT | `password` | verified | Needs `current_password`; signs out other devices. 6/min |
| POST / DELETE | `email` | verified | Request (needs `current_password`) or cancel an email change |
| POST | `email/confirm/{id}/{hash}?expires&signature` | link | Applies the change; the old address is notified |

- Email links open the storefront (`FRONTEND_URL`), which posts the signed
  parameters back. Signatures are relative (path + query), so they stay
  valid through the storefront proxy. Changing the address invalidates
  older links (the hash covers the email).
- The reset token travels in the URL **fragment** (`#token=…`), which is
  never sent to a server, logged or leaked through `Referer`.
- Passwords follow `Password::defaults()` (12+ characters, mixed case,
  number, symbol) and are hashed by the model's `hashed` cast.
- Unverified customers can view their account and request a new link;
  profile, password and email changes require the `verified` middleware.
- After a password change or reset, other sessions are deleted (database
  driver) and Sanctum's AuthenticateSession rejects any session that still
  holds the old password hash.
- Emails are sent synchronously (no queue worker); a delivery failure is
  logged and reported to the customer, never shown as a success.

## Orders and payments

Checkout uses the customer session (storefront proxy, like accounts) and
Paystack's hosted payment page. Nothing about money comes from the browser:
it sends product ids, variant ids and quantities only.

1. `POST /checkout/quote`: prices the cart from published products and
   variants (`CartPricer`) and the delivery fee from `config/shipping.php`
   (`ShippingCalculator`). Problems (unpublished, inactive variant, price on
   request, out of stock, not enough stock) are returned as `issues`.
2. `POST /checkout`: re-prices; a changed total (`expected_total`) answers
   409 `price_changed` with the new quote. Creates the order, its line
   snapshots and status history in one transaction (`OrderPlacer`), grants
   this browser session access to it, starts a Paystack transaction with a
   unique per-attempt reference and returns `authorization_url`. The
   `idempotency_key` makes double clicks and retries return the same order.
3. Paystack sends the customer to `FRONTEND_URL/checkout/complete?order=…`;
   `GET /orders/{reference}` then asks Paystack (`/transaction/verify`).
4. `POST /payments/paystack/webhook` (HMAC-SHA512 signature) is a second
   trigger for the same verification.

An order is paid only when Paystack's verify API reports `success` with the
attempt's exact reference, amount and currency (`PaymentProcessor::apply`,
under row locks and idempotent: no double confirmation, stock deduction or
email). Stock is deducted at payment; a paid order whose variant sold out
in the meantime is flagged `stock_issue` for the team. Retrying an unpaid
order reuses the open Paystack transaction for 30 minutes, then starts a new
attempt; a second successful payment on one order is flagged
`duplicate_payment` (refund it in the Paystack dashboard).

| Method | Path (`/api/v1/…`) | Access |
| --- | --- | --- |
| POST | `checkout/quote`, `checkout` | anyone (rate limited) |
| GET | `orders/{reference}` | owner (signed-in customer) or a session granted access |
| POST | `orders/{reference}/pay` | same; 409 if paid or cancelled |
| POST | `orders/{reference}/access?expires&signature` | signed link from an order email |
| POST | `orders/lookup` | `reference` + `email`: emails a 24-hour access link; same answer either way |
| GET | `account/orders` | signed-in customer's own orders |
| POST | `payments/paystack/webhook` | Paystack (signature) |
| GET | `admin/orders` (`q`, `status`, `payment_status`, `from`, `to`, `page`) + `summary` counts | admin |
| GET | `admin/orders/{reference}` | admin |
| POST | `admin/orders/{reference}/status` (`status`, `note`, tracking fields) | admin |
| PUT | `admin/orders/{reference}/tracking`, `…/notes` | admin |
| POST | `admin/orders/{reference}/verify-payment` | admin |

**Fulfilment lifecycle** (`App\Enums\OrderStatus`, enforced server side):
`pending_payment` → `confirmed` (only by a verified payment) →
`processing` → `ready_for_dispatch` (optional) → `dispatched` → `delivered`;
`cancelled` from any status before dispatch. Every change is recorded in
`order_status_histories` with who made it; customers are emailed at
dispatch, delivery and cancellation. Cancelling never refunds: refunds are
made in the Paystack dashboard.

`php artisan orders:reconcile-payments` re-checks unfinished payments (for
example webhooks missed while the free instance slept). Nothing runs it
automatically; run it when needed.

## Images

Uploads are validated (JPEG/PNG/WebP only, ≤ 10 MB, 600–6000 px per side,
checked by decoding the file, not by trusting its name or MIME header), then
**re-encoded** to WebP at 480/960/1600 px (never wider than the original)
with metadata stripped and EXIF orientation applied. Files get generated names
(`catalog/{products|collections}/{uuid}-{width}.webp`); the original upload
and its filename are never stored. Deleting an image removes all its files
after the database change commits.

**Local:** the `public` disk (`storage/app/public`) served through the
`public/storage` symlink created by `php artisan storage:link`.

**Production:** set `CATALOG_IMAGE_DISK` to an S3-compatible disk (configure
`AWS_*` in the host environment) and put a CDN in front of the bucket; image
URLs are generated by the disk, so no code changes are needed. Keep the bucket
read-only to the public and writable only by the API's credentials.

## Legacy catalogue migration

Until real products are entered, the storefront still shows its bundled
development catalogue (see the frontend README). Migration is **per record**
and never empties the site:

1. An administrator creates (or imports) the product in the dashboard and
   sets **Legacy key** to the legacy product's ID (e.g.
   `dev-senator-olive-embroidered`; collections use `collection:<slug>`).
2. While it is a draft, the legacy entry keeps showing.
3. When it is **published**, `GET /catalog/legacy-map` lists the claim; the
   storefront hides the legacy entry and redirects its old URL to the new
   product. A published record with the **same slug** as a legacy entry also
   replaces it.
4. If the API is unreachable, the storefront falls back to the legacy entry.

Bulk start (optional) — create **drafts** from the legacy data:

```bash
# in ../pdl_africa_frontend
npm run catalog:export-legacy          # writes .cache/legacy-catalog-manifest.json
# here
php artisan catalog:import-legacy ../pdl_africa_frontend/.cache/legacy-catalog-manifest.json --dry-run
php artisan catalog:import-legacy ../pdl_africa_frontend/.cache/legacy-catalog-manifest.json
```

The import is idempotent (existing legacy keys or slugs are skipped, never
overwritten), never publishes or deletes, and skips licensed stock photos
unless `--include-stock-images` is passed (they are not PDL products).
Placeholder sizes and prices are not imported; an administrator enters real
ones before publishing. `--only=<legacy-key>` imports selected entries.

## Official collections and occasions

PDL Africa's collections and categories are **Agbada, Suits, Kaftans and
Pants**. There is no Senator collection: senator styles are Kaftans. Apply
this to a database once (safe to repeat; never deletes anything):

```bash
php artisan catalog:sync-taxonomy --dry-run   # shows the plan
php artisan catalog:sync-taxonomy
```

- A Senator collection/category becomes **Kaftans** when no Kaftans record
  exists (same ID, products, images and published state); otherwise its
  products move to Kaftans and Senator is **archived**. The storefront
  redirects `/collections/senator` to `/collections/kaftans`.
- Missing official collections are created as drafts (publish them after
  uploading a banner); missing official categories are created published.
- The five suggested occasions (Weddings, Traditional Ceremonies, Formal
  Events, Business & Corporate, Celebrations & Special Occasions) are created
  as **drafts with no products** (`--skip-occasions` to skip). An
  administrator assigns products (product form or occasion page), optionally
  uploads an image, and publishes each one. Nothing is assigned
  automatically.

## CORS

Configured in `config/cors.php`: `api/*` and `sanctum/csrf-cookie`, exact
origins from `FRONTEND_URLS` (no wildcards), explicit methods and headers,
`supports_credentials: true` (required for the admin session cookie).

## Security baseline

- `.env` and every `.env.*` except `.env.example` are git-ignored.
- Rate limits: `/api` 120 requests/minute per user or IP; admin login as
  above.
- API responses add `X-Content-Type-Options: nosniff`, `X-Frame-Options:
  DENY`, `Referrer-Policy: no-referrer` and a deny-all CSP.
- Every model declares its mass-assignable fields; `shouldBeStrict()` is on
  outside production. Input is validated with Form Requests (including
  cross-record rules such as variant SKUs unique across products) and output
  goes through API Resources with explicit fields; storage keys and internal
  columns are never exposed.
- Production: destructive DB commands (`migrate:fresh`, `db:wipe`, …) are
  blocked and generated URLs are forced to HTTPS.

### Production checklist

- `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY`,
  `LOG_LEVEL=warning`, HTTPS `APP_URL`, real `FRONTEND_URLS`.
- Admin sessions: `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=.yourdomain`,
  `SANCTUM_STATEFUL_DOMAINS` = the storefront/admin host. The API and
  frontend must share a parent domain (e.g. `pdlafrica.com` and
  `api.pdlafrica.com`).
- `CATALOG_IMAGE_DISK` on durable storage (S3 + CDN), or run
  `php artisan storage:link` and back up `storage/app/public`.
- Secrets from the host's environment/secret store, not committed files.
- PHP: `expose_php = Off`, OPcache on, `gd` and `exif` enabled; HSTS and TLS
  at the web server/proxy; trusted proxies if behind a load balancer.
- Run `php artisan config:cache route:cache` on deploy and `composer audit`
  in CI.

## Not implemented (out of scope for this phase)

Server-side cart persistence, saved addresses, discounts/coupons, refunds
through the API (made in the Paystack dashboard), live courier tracking APIs,
password reset for administrators (use `pdl:create-admin` to reset), and
multi-currency pricing (orders are charged in NGN).
# pdl_africa_api
