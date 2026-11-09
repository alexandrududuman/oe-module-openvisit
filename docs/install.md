# Installing OpenVisit

OpenVisit is an OpenEMR custom module. It installs like every other Composer module and is then
registered, installed and enabled from the Module Manager.

## Requirements

- OpenEMR 8.2.0 or newer, PHP 8.3 or newer. On an older OpenEMR the module installs but
  stays inactive, and its setup check says which version it needs.
- Shell access to the OpenEMR server, to run Composer or copy the module folder
- A Jitsi Meet server with JWT authentication (the reference stack in `docker/` has one)

## Whole clinic in one command

`docker/` holds a reference stack: OpenEMR with the module, MariaDB, Jitsi Meet with JWT auth
and lobby, coturn and Caddy with automatic TLS. See [docker/README.md](../docker/README.md).

## 1. Put the module in place

Either with Composer, from the OpenEMR directory:

```sh
cd /path/to/openemr
composer require alexandrududuman/oe-module-openvisit
```

Or by hand: unpack the release archive so that its files sit in
`interface/modules/custom_modules/oe-module-openvisit`. The folder name must be exactly that.

Either way the files must belong to the web server user. The official `openemr/openemr` docker
image keeps the code read-only for that user, so run Composer or the copy as root inside the
container, then hand the folder over:

```sh
chown -R apache:apache interface/modules/custom_modules/oe-module-openvisit
```

## 2. Install and enable

In OpenEMR: Administration > Modules > Manage Modules.

1. "OpenVisit Telehealth" is listed once its files are in place. On releases that list it as
   unregistered, click **Register** first.
2. Click **Install**. This creates the module's tables.
3. Click **Enable**.

## 3. Configure

1. Administration > Globals > **OpenVisit**: set the Jitsi domain, the JWT app id and the JWT
   app secret to the values configured on the Jitsi server. The other settings have sensible
   defaults.
2. Administration > Globals > **Connectors**: set *Site Address* to the address patients reach
   OpenEMR at, for example `https://emr.clinic.example`. Patient links are built from it.
3. Manage Modules > the gear icon next to OpenVisit: the setup check tells you whether the Jitsi
   server, the IFrame API, the JWT secret, the public address, the mailer, the cleanup service
   and, when it is on, recording are in order, and what to do about each red line.

The hourly cleanup (Administration > System > Services, **OpenVisit: close abandoned visits and
purge old links**) is switched on when the module is enabled through the Module Manager. When the
module is enabled from the command line, set it active there.

Then read the [user guide](user-guide.md) for running a first visit.

## 4. Jitsi server

Any Jitsi Meet with JWT authentication works. With `docker-jitsi-meet` set at least:

```
ENABLE_AUTH=1
AUTH_TYPE=jwt
JWT_APP_ID=<app id you enter in OpenEMR>
JWT_APP_SECRET=<secret you enter in OpenEMR>
ENABLE_LOBBY=1
XMPP_MUC_MODULES=token_affiliation,openvisit_lobby_bypass
```

and mount `docker/prosody/mod_openvisit_lobby_bypass.lua` from this repository into prosody's
custom plugin folder (`/prosody-plugins-custom/` in docker-jitsi-meet). Without it a provider who
reloads the room, or rejoins after closing the tab, lands in the lobby of their own visit with
nobody allowed to admit them.

`token_affiliation` makes the provider a moderator from the JWT claim and keeps patients and
guests in the lobby until admitted. Two more settings make sure nobody else gets that role:

```
JICOFO_ENABLE_AUTH=0
ENABLE_AUTO_OWNER=0
```

With jicofo's own JWT authentication on, jicofo makes every participant who holds a valid token
a moderator, patients and guests included; with auto-owner on, whoever joins first becomes one.
Prosody still refuses anyone without a valid token. End visit closes the call for everyone
through Jitsi's end-conference component, which docker-jitsi-meet enables by default
(`ENABLE_END_CONFERENCE`).

## Recording visits

A provider can record a visit once at least two people are in the call: the provider and the
patient or a guest. Recording is off until an administrator turns it on, and it needs a Jibri
recorder next to the Jitsi server.

How it works:

1. The provider clicks **Record** in the room. The button stays disabled while the provider is
   alone, and the server refuses the request too.
2. The provider confirms that the patient agreed. Everyone in the call sees that the visit is
   recorded: Jitsi shows its recording mark and the patient's page shows a banner.
3. Recording stops when the provider clicks **Stop recording**, when fewer than two people are
   left in the call, or when the visit ends.
4. Jibri writes an MP4 file. OpenEMR moves it into the site's documents folder, which the web
   server never serves directly, and lists it in Visit history with a **Watch** link. Only the
   appointment's provider and administrators can watch it; every viewing is logged.

### Setup

On the Jitsi server (the reference stack does this with `ENABLE_RECORDING=1` and
`COMPOSE_PROFILES=recording` in `docker/.env`):

- `ENABLE_RECORDING=1`, `XMPP_HIDDEN_DOMAIN`, `JIBRI_XMPP_PASSWORD` and
  `JIBRI_RECORDER_PASSWORD` on prosody, jicofo and web
- a Jibri container with `JIBRI_FINALIZE_RECORDING_SCRIPT_PATH` pointing at
  `docker/jibri/finalize.sh` from this repository. The script makes each file streamable, notes
  its duration and marks it ready for OpenEMR.

Jibri runs Chrome and needs about 2 GB of RAM and `shm_size: 2gb` while it records. It records
one visit at a time; run more Jibri containers for parallel recordings.

Share Jibri's recordings folder with the OpenEMR server. In the reference stack both containers
mount the `recordings` volume, Jibri at `/storage` and OpenEMR at `/var/openvisit`, and both run
as uid 1000. On separate hosts, mount the same folder on both (NFS or similar).

In OpenEMR, Administration > Globals > OpenVisit:

- **Allow recording**: on
- **Recordings inbox folder**: where OpenEMR sees Jibri's `recordings` folder, by default
  `/var/openvisit/recordings`

The setup check then confirms that the Jitsi server offers recording and that the inbox and the
documents folder are usable.

Recordings are files with protected health information. They are kept until someone deletes
them from `sites/<site>/documents/openvisit/recordings`; include that folder in backups.

## Patient portal

Patients with a portal account see a **Join video visit** button on their telehealth
appointments. No extra setup: the portal session identifies the patient and the module issues
the same kind of link it would email.

## Upgrading

Replace the files (`composer update alexandrududuman/oe-module-openvisit`, or unpack the new
release over the old folder), then open Manage Modules. When the new release changes the
database, the module's row shows **Upgrade SQL** or **Install SQL**; click it once. Releases
before 0.6.0 did not record their SQL version, so they show **Install SQL**; it is safe to run.

Equivalent from the command line, inside the OpenEMR directory:

```sh
php bin/console openemr:zfc-module --modaction=discover
php bin/console openemr:zfc-module --modaction=install --modname=oe-module-openvisit
php bin/console openemr:zfc-module --modaction=enable --modname=oe-module-openvisit
```

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

`phase6-recording.spec.js` records twice, plays a file back and needs a Jitsi with Jibri; it
runs when `RECORDING=1` is set.

`live-call.spec.js` keeps a provider, a phone patient and a guest in one visit and checks every
few seconds that audio and video still arrive on every side; `CALL_SECONDS` sets how long
(default 60).

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

### Installing from scratch

`tests/e2e/install/run.sh` starts a pristine OpenEMR without demo data or the module, puts the
module in place as a clinic would, and runs a journey in which the administrator installs and
enables it in Manage Modules, fills in the settings, follows the setup check, enters a first
patient and runs a visit with that patient on a phone. It then checks that core recorded the
module's SQL version and that every table exists.

```sh
tests/e2e/install/run.sh copy        # release files copied into custom_modules
tests/e2e/install/run.sh composer    # composer require
tests/e2e/install/run.sh upgrade     # v0.5.0 in use first, then replaced
OPENEMR_TAG=8.6.0 tests/e2e/install/run.sh copy
OPENEMR_TAG=8.0.0 EXPECT_UNSUPPORTED=1 tests/e2e/install/run.sh copy   # stays inactive, core keeps working
```

It needs the same `JITSI_DOMAIN` and `JITSI_JWT_SECRET` as the other journeys, and port 8350
(`INSTALL_HTTP_PORT`).
