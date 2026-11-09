#!/usr/bin/env bash
# Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
# Installs OpenVisit on a pristine OpenEMR the way a clinic would, then runs the install journey.
# Usage: tests/e2e/install/run.sh [copy|composer|upgrade]
#   copy      release files copied into custom_modules (manual download)
#   composer  composer require from a path repository holding the release files
#   upgrade   v0.5.0 installed and enabled first, then replaced by the current files
# Env: OPENEMR_TAG (default 8.5.0), INSTALL_HTTP_PORT (8350), KEEP_STACK=1 to leave it running,
#      EXPECT_UNSUPPORTED=1 for a core older than the module supports,
#      JITSI_DOMAIN, JITSI_APP_ID, JITSI_JWT_SECRET as for the other journeys.
set -euo pipefail

METHOD="${1:-copy}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
PORT="${INSTALL_HTTP_PORT:-8350}"
UPGRADE_FROM="v0.5.0"
OE_DIR=/var/www/localhost/htdocs/openemr
MOD_DIR="$OE_DIR/interface/modules/custom_modules/oe-module-openvisit"

OPENVISIT_DIST="$(mktemp -d)"
export OPENVISIT_DIST
chmod 755 "$OPENVISIT_DIST"
COMPOSE=(docker compose -f "$HERE/docker-compose.yml")
oe() { "${COMPOSE[@]}" exec -T openemr "$@"; }
db() { "${COMPOSE[@]}" exec -T mysql mariadb -uroot -proot openemr "$@"; }

cleanup() {
  if [ "${KEEP_STACK:-0}" != "1" ]; then "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true; fi
  rm -rf "$OPENVISIT_DIST"
}
trap cleanup EXIT

# A release archive holds what git tracks; take the working tree so uncommitted changes count.
echo "==> packing the module"
mkdir -p "$OPENVISIT_DIST/new"
(cd "$ROOT" && git ls-files -z -co --exclude-standard | tar --null -T - --ignore-failed-read -cf - 2>/dev/null) | tar -xf - -C "$OPENVISIT_DIST/new"
rm -rf "$OPENVISIT_DIST/new/tests" "$OPENVISIT_DIST/new/node_modules"
if [ "$METHOD" = "upgrade" ]; then
  mkdir -p "$OPENVISIT_DIST/old"
  git -C "$ROOT" archive "$UPGRADE_FROM" | tar -xf - -C "$OPENVISIT_DIST/old"
fi
chmod -R a+rX "$OPENVISIT_DIST"

echo "==> starting a pristine OpenEMR ${OPENEMR_TAG:-8.5.0}"
"${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
"${COMPOSE[@]}" up -d
for i in $(seq 1 120); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/interface/login/login.php?site=default" || true)
  if [ "$code" = "200" ] && db -N -e "SELECT 1 FROM users LIMIT 1" >/dev/null 2>&1; then echo "    ready after $((i * 5))s"; break; fi
  if [ "$i" = "120" ]; then echo "OpenEMR did not come up"; "${COMPOSE[@]}" logs --tail 50 openemr; exit 1; fi
  sleep 5
done

# The official image keeps the code read-only for the web user; the admin adds the module as root
# and hands it to apache, like any other file in the tree.
place() {
  oe sh -c "rm -rf '$MOD_DIR' && cp -R '/opt/openvisit/$1' '$MOD_DIR' && chown -R apache:apache '$MOD_DIR'"
}

case "$METHOD" in
  copy)
    echo "==> copying the release files into custom_modules"
    place new
    ;;
  composer)
    echo "==> composer require from a path repository"
    oe sh -c "cd '$OE_DIR' && export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer \
      && composer config repositories.openvisit '{\"type\": \"path\", \"url\": \"/opt/openvisit/new\", \"options\": {\"symlink\": false}}' \
      && composer require --no-interaction --no-progress --update-no-dev 'alexandrududuman/oe-module-openvisit:*@dev' \
      && chown -R apache:apache '$MOD_DIR'"
    oe test -f "$MOD_DIR/openemr.bootstrap.php"
    ;;
  upgrade)
    echo "==> installing ${UPGRADE_FROM} first, as a clinic that has used it"
    place old
    php_oe() { oe su -s /bin/sh apache -c "cd '$OE_DIR' && php $*"; }
    php_oe "bin/console openemr:zfc-module --modaction=discover" >/dev/null
    php_oe "bin/console openemr:zfc-module --modaction=install --modname=oe-module-openvisit" >/dev/null
    php_oe "bin/console openemr:zfc-module --modaction=enable --modname=oe-module-openvisit" >/dev/null
    db -N -e "SELECT CONCAT('    ${UPGRADE_FROM} active=', mod_active, ' sql_version=', sql_version) FROM modules WHERE mod_directory='oe-module-openvisit'"
    echo "==> replacing it with the current files"
    place new
    ;;
  *)
    echo "unknown method $METHOD"; exit 2
    ;;
esac

echo "==> install journey (${METHOD})"
(cd "$ROOT" && ADMIN_PASS=pass FRESH_OPENEMR_URL="http://localhost:${PORT}" INSTALL_METHOD="$METHOD" npx playwright test tests/e2e/install/)

if [ "${EXPECT_UNSUPPORTED:-0}" != "1" ]; then
  echo "==> schema: core recorded the module's SQL version and every table exists"
  want="$(sed -n "s/^\$v_\(major\|minor\|patch\) = '\([0-9]*\)';/\2/p" "$ROOT/version.php" | paste -sd.)"
  got="$(db -N -e "SELECT sql_version FROM modules WHERE mod_directory='oe-module-openvisit'")"
  [ "$got" = "$want" ] || { echo "sql_version is '$got', expected '$want'"; exit 1; }
  for table in $(grep -o 'CREATE TABLE IF NOT EXISTS `[a-z_]*`' "$ROOT/sql/install.sql" | tr -d '`' | awk '{print $NF}'); do
    [ "$(db -N -e "SHOW TABLES LIKE '$table'")" = "$table" ] || { echo "table $table is missing"; exit 1; }
  done
  echo "    sql_version $got, tables present"
fi
