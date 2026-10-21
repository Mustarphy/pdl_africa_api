#!/usr/bin/env bash
# Container entrypoint for the PDL Africa API (Apache + mod_php).
set -euo pipefail

cd /var/www/html

# On Render, default APP_URL to the service's public URL (used for image
# URLs and links) unless it is set explicitly.
if [[ -z "${APP_URL:-}" && -n "${RENDER_EXTERNAL_URL:-}" ]]; then
  export APP_URL="$RENDER_EXTERNAL_URL"
fi

if [[ $# -gt 0 ]]; then
  # One-off commands, e.g. `docker compose run --rm api php artisan migrate`
  # or `php artisan key:generate --show` (prints a key; changes nothing).
  exec "$@"
fi

# Serving checks.
if [[ "${APP_ENV:-production}" == "production" ]]; then
  # Never generate or replace a key here: sessions and encrypted data depend
  # on it. Create one once (see DEPLOYMENT.md) and set it as APP_KEY.
  if [[ -z "${APP_KEY:-}" ]]; then
    echo "entrypoint: APP_KEY is not set. Refusing to start in production." >&2
    exit 1
  fi
  if [[ "${APP_DEBUG:-false}" == "true" ]]; then
    echo "entrypoint: APP_DEBUG=true is not allowed in production. Refusing to start." >&2
    exit 1
  fi
fi

# Build the config/route/event caches from the environment of *this*
# container start, so a changed variable never leaves a stale cache behind.
# (No view:cache: this API renders JSON only and has no Blade views; an
# empty resources/views folder is not stored in Git.)
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan event:cache --no-interaction

# Opt-in only. `migrate --force` applies pending migrations and never drops
# data; destructive commands (migrate:fresh, db:wipe) stay blocked in
# production by AppServiceProvider.
if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  php artisan migrate --force --no-interaction
fi

# Render (and `docker stop`) send SIGTERM. Apache treats SIGTERM as an
# immediate stop, so translate it into a graceful stop (SIGWINCH) that lets
# in-flight requests finish.
apache2-foreground &
apache_pid=$!
trap 'kill -WINCH "$apache_pid" 2>/dev/null || true' TERM INT
while kill -0 "$apache_pid" 2>/dev/null; do
  wait "$apache_pid" || true
done
