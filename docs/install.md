# Installing OpenVisit

OpenVisit is an OpenEMR custom module. It installs like every other Composer module and is then
registered, installed and enabled from the Module Manager.

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
they found them.
