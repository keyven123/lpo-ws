#!/usr/bin/env bash
# Deploys the latest main branch of the LPO API on the server.
# Called by GitHub Actions over SSH (see .github/workflows/deploy.yml); can also be run by hand:
#   bash /srv/lpo/lpo-ws/scripts/deploy.sh
set -euo pipefail

# Everything runs inside main(): bash reads the whole function before starting, so the
# git reset below can safely replace this file while it runs.
main() {

  APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  BRANCH="${DEPLOY_BRANCH:-main}"
  PHP="${PHP_BIN:-php}"
  cd "$APP_DIR"

  # Files created during the deploy stay writable for PHP-FPM (www-data group).
  umask 002

  # One deploy at a time.
  exec 9>"$APP_DIR/storage/deploy.lock"
  flock -n 9 || { echo "Another deploy is running."; exit 1; }

  step() { printf '\n==> %s\n' "$*"; }

  step "Fetching $BRANCH"
  git fetch --prune origin "$BRANCH"
  OLD_REV="$(git rev-parse HEAD)"
  git reset --hard "origin/$BRANCH"
  NEW_REV="$(git rev-parse HEAD)"
  echo "    $OLD_REV -> $NEW_REV"

  step "Installing dependencies"
  composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

  step "Maintenance mode"
  "$PHP" artisan down --retry=15 || true
  trap '"$PHP" artisan up >/dev/null 2>&1 || true' EXIT

  step "Migrating"
  "$PHP" artisan migrate --force

  step "Caching config, routes and events"
  "$PHP" artisan optimize:clear >/dev/null
  # Not `artisan optimize`: this API has no resources/views, so its view:cache step would fail.
  "$PHP" artisan config:cache
  "$PHP" artisan route:cache
  "$PHP" artisan event:cache
  # Uploaded logos are served from public/storage.
  "$PHP" artisan storage:link >/dev/null 2>&1 || true

  step "Going live"
  "$PHP" artisan up
  trap - EXIT

  step "Restarting queue workers"
  # Workers finish their current job and exit; systemd (lpo-ws-worker.service) starts them on the new code.
  "$PHP" artisan queue:restart

  printf '\nDeployed %s\n' "$(git log -1 --format='%h %s')"
}

main "$@"
exit $?
