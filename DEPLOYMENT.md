# Deploying PDL Africa to Render (Docker)

This guide covers the client-demo deployment of the two repositories:

| Repository | Folder on your PC | Render service |
| --- | --- | --- |
| `Mustarphy/pdl_africa_api` | `pdl_africa_backend` | `pdl-africa-api` (Laravel API) |
| `Mustarphy/pdl_africa` | `pdl_africa_frontend` | `pdl-africa-web` (React storefront + admin, Nginx) |

The Blueprint (`render.yaml`) lives in this repository and creates both
services. The frontend service is built from the other repository.

## How the pieces fit together

```
Browser
  ├─ storefront pages, JS, images ──► pdl-africa-web  (Nginx, static Vite build)
  ├─ catalogue API (no cookies) ────► pdl-africa-api  /api/v1/...   (CORS)
  └─ admin API (session cookies) ───► pdl-africa-web  /api, /sanctum
                                          └─ proxied ─► pdl-africa-api
pdl-africa-api ──TLS──► managed MySQL 8 (external, e.g. Aiven free tier)
```

Why the admin goes through the storefront: the admin dashboard uses Laravel
Sanctum session cookies and a CSRF cookie that the browser must treat as
first-party. Every Render service has its own `*.onrender.com` address, and
browsers treat those as separate sites, so cookies set by the API would be
third-party (and the frontend could not read the CSRF cookie). Proxying admin
calls through the storefront's own origin keeps cookies first-party without
weakening CSRF or cookie security. The public catalogue calls the API
directly, so each visitor keeps their own rate-limit bucket.

## 1. Files used for deployment

Backend (`pdl_africa_backend`):

- `Dockerfile`: PHP 8.4 + Apache (mod_php), production Composer install,
  runs as `www-data`, listens on `$PORT`.
- `docker/entrypoint.sh`: start-up checks (refuses to run in production
  without `APP_KEY` or with `APP_DEBUG=true`), builds Laravel caches from the
  current environment, optional migrations, graceful shutdown.
- `docker/apache/*.conf`, `docker/php/production.ini`: web server and PHP
  settings.
- `.dockerignore`: keeps `.env`, certificates, `vendor`, logs and local
  uploads out of the image.
- `render.yaml`: the Blueprint for both services.
- `bootstrap/app.php`: trusts Render's load balancer (correct client IPs for
  rate limiting and HTTPS detection), and answers unauthenticated requests
  with a JSON 401 even without an `Accept: application/json` header.
- `database/migrations/2026_10_20_000002_create_catalog_tables.php`:
  `product_images.primary_product_id` is now a VIRTUAL generated column
  (was STORED). MySQL 8 rejects the original on a fresh database
  (`Cannot add foreign key constraint`); MariaDB accepted it. Behaviour is
  unchanged: still at most one primary image per product. Your existing
  XAMPP database keeps its stored column and needs no change.

Frontend (`pdl_africa_frontend`):

- `Dockerfile`: `npm ci` + `npm run build`, served by unprivileged Nginx.
- `docker/nginx/default.conf.template`: SPA routing (refreshing
  `/products/...` or `/admin/...` works), caching, `/healthz`, and the
  `/api` + `/sanctum` proxy.
- `.dockerignore`.
- `src/config/env.ts`, `src/admin/api/adminClient.ts`: new optional
  `VITE_ADMIN_API_BASE_URL` (defaults to `VITE_API_BASE_URL`).

Parent folder (`C:\Users\HP\Desktop\pdl_africa`, not in either repository):

- `compose.yaml`, `compose.env.example`: local Docker verification.

## 2. Test locally with Docker

Run from Git Bash in `C:\Users\HP\Desktop\pdl_africa`:

```bash
cp compose.env.example .env
# 1. Put a long random value in DB_PASSWORD (local container only).
# 2. Generate an APP_KEY for local use and paste it into .env:
docker build -t pdl-africa-api ./pdl_africa_backend
docker run --rm pdl-africa-api php artisan key:generate --show

docker compose up --build -d
docker compose run --rm api php artisan migrate
```

Create a local administrator (interactive; in Git Bash prefix with `winpty`):

```bash
winpty docker compose run --rm api php artisan pdl:create-admin you@example.com
```

Check:

| What | How | Expected |
| --- | --- | --- |
| API health | `curl http://localhost:8001/api/v1/health` | `{"status":"ok"}` |
| Catalogue | `curl http://localhost:8001/api/v1/collections` | `{"data":[...]}` |
| Storefront | open http://localhost:8080 | home page; refresh any page works |
| Admin | http://localhost:8080/admin | sign in with the admin you created |
| Proxy | `curl http://localhost:8080/api/v1/health` | `{"status":"ok"}` |
| Logs | `docker compose logs -f api web` | no errors |

Stop with `docker compose down` (keeps the database and uploads). Only
`docker compose down -v` deletes the local Docker volumes.

The local stack uses its own MySQL 8.4 container, not your XAMPP MariaDB.
To copy data from XAMPP, see section 9.

## 3. Before committing

Review the changes in each repository:

```bash
cd /c/Users/HP/Desktop/pdl_africa/pdl_africa_backend && git status && git diff
cd /c/Users/HP/Desktop/pdl_africa/pdl_africa_frontend && git status && git diff
```

The frontend's earlier work (responsive pages, cart, logo, hero) is also
uncommitted. Make sure these new files are included, or the deployed navbar
and favicon break: `src/assets/images/pdl_log0_black.png`,
`src/assets/images/pdl_logo_white.png`, `src/assets/brand/`, `public/favicon*`,
`public/apple-touch-icon.png`, `scripts/brand/`,
`src/features/cart/CartContents.tsx`.

Never commit:

- any `.env` file (both repositories already ignore them; the parent folder's
  `.env` is outside both repositories),
- database CA certificates (`*.pem`) or any file with passwords or keys,
- `vendor/`, `node_modules/`, `dist/`, `storage/logs`, uploaded images.

Confirm nothing sensitive is staged:

```bash
git status --short | grep -Ei '\.env|\.pem|\.key' || echo "nothing sensitive staged"
```

## 4. Commit and push (you do this)

```bash
cd /c/Users/HP/Desktop/pdl_africa/pdl_africa_backend
git add -A
git commit -m "Add Docker and Render deployment configuration"
git push origin main

cd /c/Users/HP/Desktop/pdl_africa/pdl_africa_frontend
git add -A
git commit -m "Docker/Nginx deployment, admin API proxy support, responsive and branding updates"
git push origin main
```

## 5. Create the database (external MySQL)

Render has no managed MySQL, and its free Postgres expires after 30 days
(using it would also mean switching database engines). The app keeps MySQL;
use a free external MySQL 8 service. Recommended: **Aiven for MySQL, free
plan** (1 GB storage, no credit card).

1. Create an Aiven account and a **MySQL** service on the **Free** plan.
   Pick a European region if offered (the Render services use Frankfurt).
2. When it is running, open the service **Overview** and note: Host, Port,
   User (`avnadmin`), Password, Database (`defaultdb`).
3. Download the **CA certificate** (`ca.pem`). Keep it out of both
   repositories.

Aiven requires TLS; the API connects with the CA certificate
(`MYSQL_ATTR_SSL_CA`). Aiven also enforces primary keys on every table; all
migrations were verified on MySQL 8.4.10 with `sql_require_primary_key=ON`
(the local Docker setup uses the same settings).

## 6. Create the Render services

1. Push both repositories (section 4).
2. Render Dashboard → **New** → **Blueprint** → connect GitHub and pick
   `Mustarphy/pdl_africa_api` (branch `main`). Render reads `render.yaml` and
   lists `pdl-africa-api` and `pdl-africa-web`. Allow Render's GitHub app
   access to **both** repositories.
3. Render asks for every `sync: false` value. On this first pass the
   service URLs are not known yet; enter what you can (section 7) and use
   the placeholders shown there for the rest.
4. Add the database certificate: `pdl-africa-api` → **Environment** →
   **Secret Files** → filename `ca.pem`, contents of the downloaded file.
5. After creation, note the real URLs (e.g.
   `https://pdl-africa-api.onrender.com`, `https://pdl-africa-web.onrender.com`;
   Render adds a suffix if a name is taken). Fill in or correct the URL
   variables in section 7, then **Manual Deploy → Deploy latest commit** on
   both services (the frontend must be rebuilt for `VITE_*` changes).

Settings, if you create the services by hand instead of the Blueprint:

| Setting | `pdl-africa-api` | `pdl-africa-web` |
| --- | --- | --- |
| Repository | `Mustarphy/pdl_africa_api` | `Mustarphy/pdl_africa` |
| Branch | `main` | `main` |
| Language / runtime | Docker | Docker |
| Root directory | (blank: repository root) | (blank: repository root) |
| Dockerfile path | `./Dockerfile` | `./Dockerfile` |
| Instance type | Free | Free |
| Region | Frankfurt (same for both) | Frankfurt |
| Health check path | `/api/v1/health` | `/healthz` |

## 7. Environment variables

### `pdl-africa-api` (read by the running container)

| Variable | Value |
| --- | --- |
| `APP_NAME` | `PDL Africa` (Blueprint) |
| `APP_ENV` | `production` (Blueprint) |
| `APP_DEBUG` | `false` (Blueprint; the container refuses `true` in production) |
| `APP_KEY` | generate once: `docker run --rm pdl-africa-api php artisan key:generate --show`; paste the `base64:...` value. Never change it afterwards (it would invalidate sessions). |
| `APP_URL` | optional; defaults to the service's `onrender.com` URL. Used for image URLs. |
| `DB_CONNECTION` | `mysql` (Blueprint) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from the Aiven service overview |
| `MYSQL_ATTR_SSL_CA` | `/etc/secrets/ca.pem` (Blueprint) + the `ca.pem` secret file |
| `FRONTEND_URLS` | storefront URL, e.g. `https://pdl-africa-web.onrender.com` |
| `SANCTUM_STATEFUL_DOMAINS` | storefront host, e.g. `pdl-africa-web.onrender.com` |
| `SESSION_SECURE_COOKIE` | `true` (Blueprint) |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stderr` / `warning` (Blueprint) |
| `CACHE_STORE` | `file` (Blueprint). The cache only holds rate-limit counters; the `database` store deadlocks on MySQL 8 when the admin sends parallel requests. |
| `CATALOG_IMAGE_DISK` | `public` (Blueprint; see section 10) |
| `RUN_MIGRATIONS` | `false` (Blueprint; see section 8) |

Changing an API variable takes effect on the next restart/deploy; caches
are rebuilt at every start, so no stale configuration remains.

### `pdl-africa-web`

Build-time (baked into the JavaScript; **public**; changing one requires a
new deploy of this service):

| Variable | Value |
| --- | --- |
| `VITE_API_BASE_URL` | API URL + `/api/v1`, e.g. `https://pdl-africa-api.onrender.com/api/v1` |
| `VITE_ADMIN_API_BASE_URL` | the storefront's own URL + `/api/v1`, e.g. `https://pdl-africa-web.onrender.com/api/v1` |
| `VITE_PUBLIC_SITE_URL` | storefront URL, e.g. `https://pdl-africa-web.onrender.com` |
| `VITE_WHATSAPP_NUMBER`, `VITE_ORDER_EMAIL` | PDL's official public contact details (leave blank to hide the buttons) |
| `VITE_LEGACY_CATALOG` | `on` (Blueprint) |

Runtime (read by Nginx at start):

| Variable | Value |
| --- | --- |
| `API_UPSTREAM` | API origin without a path, e.g. `https://pdl-africa-api.onrender.com` |

Never put database credentials, `APP_KEY` or any other secret in a `VITE_*`
variable.

## 8. Migrations and the first administrator

Render's free instances have no shell and no pre-deploy command, so run
these once from your PC with the same Docker image, pointed at the Aiven
database. Create a file **outside both repositories**, e.g.
`C:\Users\HP\Desktop\pdl_africa\render-db.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...same value as on Render...
DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=...
DB_DATABASE=defaultdb
DB_USERNAME=avnadmin
DB_PASSWORD=...
MYSQL_ATTR_SSL_CA=/certs/ca.pem
```

Then, in PowerShell from `C:\Users\HP\Desktop\pdl_africa` (interactive
commands work best there; in Git Bash prefix `docker run -it` with
`winpty`):

```powershell
docker build -t pdl-africa-api ./pdl_africa_backend
# Show what would run (no changes):
docker run --rm --env-file render-db.env -v "${PWD}/ca.pem:/certs/ca.pem:ro" pdl-africa-api php artisan migrate --pretend --force
# Apply pending migrations (additive; never drops data):
docker run --rm --env-file render-db.env -v "${PWD}/ca.pem:/certs/ca.pem:ro" pdl-africa-api php artisan migrate --force
# Create the client's administrator (prompts for a password, hidden):
docker run --rm -it --env-file render-db.env -v "${PWD}/ca.pem:/certs/ca.pem:ro" pdl-africa-api php artisan pdl:create-admin owner@example.com
```

Delete `render-db.env` (and keep `ca.pem` private) when you are done.

`migrate:fresh`, `db:wipe` and similar destructive commands are blocked in
production by the application; never run them against this database.

Alternative: set `RUN_MIGRATIONS=true` on `pdl-africa-api`; every start
then applies pending migrations before serving. That is safe for this app
(one instance, additive migrations) but a failing migration stops the
service from starting, so the explicit command above is preferred.

## 9. Moving existing local data (optional)

The local XAMPP database currently holds only test catalogue entries, and
their uploaded images exist only on your PC. The most reliable way to put
the client's real catalogue online is to recreate it in the deployed admin
dashboard (or with `catalog:import-legacy`, see README.md), uploading the
images there.

A raw `mysqldump` from MariaDB 10.4 into MySQL 8 is not recommended: the
`product_images.primary_product_id` generated column cannot be inserted
directly, and the uploaded image files would not come along.

## 10. Uploaded images: limitation for this demo

Images uploaded in the admin are stored on the API container's disk
(`CATALOG_IMAGE_DISK=public`). On Render's free plan that disk is
**ephemeral**: files are lost on every redeploy, restart **and** spin-down
(after 15 minutes without traffic). The database rows remain, so the
storefront would then show products with missing images.

What still always works: the storefront's built-in (legacy) catalogue and
all bundled images, logos and banners, which ship inside the frontend image.

Options for persistent uploads (choose before the client starts uploading):

1. **S3-compatible object storage** (recommended; e.g. Cloudflare R2's free
   allowance or AWS S3). Needs one Composer package
   (`league/flysystem-aws-s3-v3`), an `s3` disk configuration and
   `CATALOG_IMAGE_DISK=s3` + `AWS_*` variables. Not installed yet; ask for it
   to be added.
2. **Paid Render instance with a persistent disk** mounted at
   `/var/www/html/storage/app/public` (disks are not available on free
   instances; adding one disables zero-downtime deploys).
3. For a short review only: upload images shortly before the client looks,
   knowing they disappear after the next spin-down.

## 11. Check the deployment

| Check | Expected |
| --- | --- |
| `https://<api>/api/v1/health` | `{"status":"ok"}` |
| `https://<api>/api/v1/collections` | JSON (`{"data":[]}` until products are published) |
| `https://<web>/healthz` | `ok` |
| `https://<web>/api/v1/health` | `{"status":"ok"}` (proxy works) |
| `https://<web>/` and refresh on `/collections/agbada` | page loads, no 404 |
| `https://<web>/admin` | sign-in page; signing in shows the dashboard |
| Browser dev tools → Network | storefront calls go to `<api>/api/v1/...`; admin calls to `<web>/api/v1/admin/...` |

The first request after 15 idle minutes takes about a minute (spin-up).

## 12. Troubleshooting

| Symptom | Likely cause / fix |
| --- | --- |
| API deploy fails: `APP_KEY is not set` | Set `APP_KEY` (section 7). |
| API returns 500 for catalogue calls; log shows `SQLSTATE[HY000] [2002]` | Wrong `DB_HOST`/`DB_PORT`, Aiven service powered off (power it on in the Aiven console), or missing `ca.pem` secret file. |
| `SSL connection error` / `certificate verify failed` | `ca.pem` missing or wrong; `MYSQL_ATTR_SSL_CA` must be `/etc/secrets/ca.pem`. |
| `Base table or view not found` | Migrations not applied (section 8). |
| `1213 Deadlock found` on the `cache` table | `CACHE_STORE` is not `file` (section 7). |
| `Cannot add foreign key constraint` on `product_images` | The migration fix above is missing from the deployed commit. |
| Storefront loads but shows only the built-in catalogue | Normal until products are published; otherwise check `VITE_API_BASE_URL` and the API's `FRONTEND_URLS` (CORS errors appear in the browser console). |
| Frontend build fails: `Invalid frontend environment configuration` | `VITE_API_BASE_URL` missing or not an `https://` URL. |
| Admin sign-in fails with 419 or returns to the login page | `VITE_ADMIN_API_BASE_URL` must be the **storefront** URL; `SANCTUM_STATEFUL_DOMAINS` must be the storefront host; `API_UPSTREAM` must be the API origin. Redeploy the frontend after changing `VITE_*`. |
| `/api/...` on the storefront returns 503 | `API_UPSTREAM` is not set on `pdl-africa-web`. |
| Uploaded images disappear | Expected on the free plan (section 10). |
| 429 Too Many Requests | Rate limits: 120 API requests/minute per visitor, 5 admin sign-ins/minute per email. |

Logs: Render Dashboard → service → **Logs**. Laravel writes to stderr, so
errors appear there.

## 13. Limitations of this free setup

- Both services spin down after 15 minutes without traffic; the next visit
  waits about a minute. Free instances share 750 hours per month.
- Free instances: 0.1 CPU and 512 MB RAM; very large image uploads are slow.
- Uploaded images are not persistent (section 10).
- No shell or pre-deploy command on free instances (section 8).
- Aiven's free MySQL: 1 GB, single node; Aiven may power it off after a
  period of inactivity (it emails first; power it on in the console).
- Admin sign-ins all reach the API through the storefront proxy, so the
  admin login rate limit (20 attempts/minute) is shared by everyone using the
  admin. Fine for a demo; a custom domain with the API on a subdomain removes
  the need for the proxy.
- Checkout, payments and customer accounts are not part of the application.
