#!/usr/bin/env bash
# Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later.
# Boots the e2e stack, loads the OpenEMR demo data, installs and enables the module.
# Usage: tests/e2e/stack/up.sh   (env: OPENEMR_HTTP_PORT, JITSI_HTTPS_PORT, JITSI_JWT_SECRET)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE=(docker compose -f "$HERE/docker-compose.yml")
HTTP_PORT="${OPENEMR_HTTP_PORT:-8300}"
DEMO_SHA="441d7b3db5b8033822e0e3da462e7553a2330477"
DEMO_URL="https://raw.githubusercontent.com/openemr/openemr-devops/${DEMO_SHA}/docker/openemr/flex/utilities/demo_5_0_0_5.sql"
DEMO_SHA256="5d418c838446f3bdd4aa17d1276578106928a3ebcb27b40f4ab421694cc013d7"
CORE_TAG="v8_5_0"
declare -A UPGRADE_SHA256=(
  [sql_upgrade.php]="98629a07cb7971522f7ebb6f3ff67b319c6bf20057e43004e38242375b3fc6d7"
  [acl_upgrade.php]="0d9908aec1a330bc11414b44fab96b5fb1e836021d15a772d9e571bbfbd8cc3d"
)

oe() { "${COMPOSE[@]}" exec -T openemr "$@"; }
oe_php() { "${COMPOSE[@]}" exec -T -w /var/www/localhost/htdocs/openemr openemr su -s /bin/sh apache -c "php $*"; }
db() { "${COMPOSE[@]}" exec -T mysql mariadb -uroot -proot openemr "$@"; }

echo "==> starting containers"
"${COMPOSE[@]}" up -d

echo "==> waiting for OpenEMR to finish its first-boot setup"
for i in $(seq 1 120); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/interface/login/login.php?site=default" || true)
  if [ "$code" = "200" ] && db -N -e "SELECT 1 FROM users LIMIT 1" >/dev/null 2>&1; then
    echo "    ready after $((i * 5))s"; break
  fi
  if [ "$i" = "120" ]; then echo "OpenEMR did not come up"; "${COMPOSE[@]}" logs --tail 50 openemr; exit 1; fi
  sleep 5
done

echo "==> loading demo data (same dump the OpenEMR dev tools use)"
tmp="$(mktemp)"
curl -fsSL -o "$tmp" "$DEMO_URL"
echo "${DEMO_SHA256}  ${tmp}" | sha256sum -c - >/dev/null
db -N -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema='openemr'" \
  | { echo 'SET FOREIGN_KEY_CHECKS=0;'; cat; echo 'SET FOREIGN_KEY_CHECKS=1;'; } | db
db < "$tmp"
rm -f "$tmp"
# The production image strips the setup and upgrade scripts; borrow them from the matching core
# tag for the one-off schema upgrade of the demo dump, then remove them again.
for script in sql_upgrade.php acl_upgrade.php; do
  tmpf="$(mktemp)"
  curl -fsSL -o "$tmpf" "https://raw.githubusercontent.com/openemr/openemr/${CORE_TAG}/${script}"
  echo "${UPGRADE_SHA256[$script]}  ${tmpf}" | sha256sum -c - >/dev/null
  "${COMPOSE[@]}" cp "$tmpf" "openemr:/var/www/localhost/htdocs/openemr/${script}"
  rm -f "$tmpf"
  oe chown apache:apache "/var/www/localhost/htdocs/openemr/${script}"
done
oe_php "-f sql_upgrade.php -- --from=5.0.0" >/dev/null
oe rm -f /var/www/localhost/htdocs/openemr/sql_upgrade.php /var/www/localhost/htdocs/openemr/acl_upgrade.php
# The dump replaced the keys table, so the key on disk no longer matches the key in the
# database. Clear both; core regenerates a matching pair on first use.
db -e "DELETE FROM \`keys\`"
oe sh -c 'find /var/www/localhost/htdocs/openemr/sites/default/documents/logs_and_misc/methods -type f ! -name README.md -delete'
# Keep the API/portal settings the dev stack has; harmless otherwise.
db -e "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('portal_onsite_two_enable', 0, '1') ON DUPLICATE KEY UPDATE gl_value='1'"

echo "==> registering, installing and enabling the module"
oe_php "bin/console openemr:zfc-module --modaction=discover" >/dev/null
oe_php "bin/console openemr:zfc-module --modaction=install --modname=oe-module-openvisit" >/dev/null
oe_php "bin/console openemr:zfc-module --modaction=install_sql --modname=oe-module-openvisit" >/dev/null 2>&1 || true
oe_php "bin/console openemr:zfc-module --modaction=enable --modname=oe-module-openvisit" >/dev/null
db -N -e "SELECT CONCAT('    module active=', mod_active) FROM modules WHERE mod_directory='oe-module-openvisit'"

echo "==> waiting for Jitsi"
JITSI_PORT="${JITSI_HTTPS_PORT:-8443}"
for i in $(seq 1 60); do
  if curl -sk -o /dev/null -w '%{http_code}' "https://localhost:${JITSI_PORT}/external_api.js" | grep -q 200; then echo "    jitsi ready"; break; fi
  if [ "$i" = "60" ]; then echo "Jitsi did not come up"; "${COMPOSE[@]}" logs --tail 30 jitsi-web jitsi-prosody; exit 1; fi
  sleep 5
done
echo "==> stack is up: OpenEMR http://localhost:${HTTP_PORT} (admin/pass), Jitsi https://localhost:${JITSI_PORT}"
