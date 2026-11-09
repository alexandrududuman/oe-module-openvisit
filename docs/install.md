# Installing OpenVisit

OpenVisit is an OpenEMR custom module. It installs like every other Composer module and is then
registered, installed and enabled from the Module Manager.

## Whole clinic in one command

`docker/` holds a reference stack: OpenEMR with the module, MariaDB, Jitsi Meet with JWT auth
and lobby, coturn and Caddy with automatic TLS. See [docker/README.md](../docker/README.md).

## Requirements

- OpenEMR 8.5.0 or newer, PHP 8.3 or newer
- Shell access to the OpenEMR server, with Composer
- A Jitsi Meet server with JWT authentication (see the reference stack in `docker/` once it ships)

## 1. Install the package

```sh
cd /path/to/openemr
composer require alexandrududuman/oe-module-openvisit
```

Composer places the module in `interface/modules/custom_modules/oe-module-openvisit`.

## 2. Register, install, enable

In OpenEMR: Administration > Modules > Manage Modules.

1. The list shows "OpenVisit Telehealth" as unregistered. Click **Register**.
2. Click **Install**. This creates the module's tables.
3. Click **Enable**.

Equivalent from the command line, inside the OpenEMR directory:

```sh
php bin/console openemr:zfc-module --modaction=discover
php bin/console openemr:zfc-module --modaction=install --modname=oe-module-openvisit
php bin/console openemr:zfc-module --modaction=enable --modname=oe-module-openvisit
```

## 3. Configure

After saving the settings, open Manage Modules and click the gear icon next to OpenVisit: the
setup check tells you whether the Jitsi server, the IFrame API, the JWT secret, the public
address, the mailer and the cleanup service are in order, and what to do about each red line.

Also enable the hourly cleanup: Administration > System > Services shows **OpenVisit: close
abandoned visits and purge old links**; it is switched on automatically when the module is
enabled through the Module Manager. When the module is enabled from the command line, set it
active there.

Administration > Globals > **OpenVisit** tab. Set the Jitsi domain, the JWT app id and the JWT
app secret to the values configured on the Jitsi server. The other settings have sensible
defaults.

## Developing against the dev stack

The OpenEMR `development-easy-light` docker stack bind-mounts the OpenEMR checkout. Mount this
repository on top of it with a compose override next to `docker-compose.yml`:

```yaml
services:
  openemr:
    volumes:
      - /path/to/oe-module-openvisit:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-openvisit:rw
```

Then run the module's own tooling inside the container:

```sh
docker compose exec -u 1000 -w /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-openvisit openemr composer install
docker compose exec -u 1000 -w /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-openvisit openemr composer test
docker compose exec -u 1000 -w /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-openvisit -e OPENEMR_ROOT=/var/www/localhost/htdocs/openemr openemr composer phpstan
```

### Browser journeys

Every phase ships a Playwright journey in `tests/e2e/` that walks through OpenEMR the way a
user does: log in, use the menus, fill forms, read the result. They run from the host against
the dev stack:

```sh
npm install
npx playwright install chromium
npm run e2e            # headless
npm run e2e:headed     # watch it click
```

Set `OPENEMR_URL` if the stack is not on `https://localhost:9300`. The journeys use the demo
users (`admin/pass`, `physician/physician`, `accountant/accountant`) and leave the settings as
they found them. The provider-room journey needs a Jitsi server with JWT auth and the
`token_affiliation` module; pass `JITSI_DOMAIN` and `JITSI_JWT_SECRET`.

### The same stack CI uses

`tests/e2e/stack/` holds a throwaway compose stack: official `openemr/openemr:8.5.0`, MariaDB,
and the official Jitsi images with JWT auth. `up.sh` boots it, loads the OpenEMR demo data,
installs and enables the module.

```sh
npm run stack:up        # OpenEMR on http://localhost:8300, Jitsi on https://localhost:8443
npm run e2e:stack       # journeys against that stack
npm run stack:down      # remove containers and volumes
```

Override ports with `OPENEMR_HTTP_PORT`, `OPENEMR_HTTPS_PORT`, `JITSI_HTTPS_PORT`, `JVB_PORT`
when the development stack is already using the defaults.

### Jitsi server requirements

Any Jitsi Meet with JWT authentication works. With `docker-jitsi-meet` set at least:

```
ENABLE_AUTH=1
AUTH_TYPE=jwt
JWT_APP_ID=<app id you enter in OpenEMR>
JWT_APP_SECRET=<secret you enter in OpenEMR>
ENABLE_LOBBY=1
XMPP_MUC_MODULES=token_affiliation
```

`token_affiliation` makes the provider a moderator from the JWT claim and keeps patients and
guests in the lobby until admitted.
