# Reference stack

One `docker compose up -d` gives a clinic OpenEMR 8.5 with OpenVisit, MariaDB, Jitsi Meet with
JWT authentication and lobby, coturn, and Caddy terminating TLS with Let's Encrypt.

## Before you start

- A host with a public IP, ports 80, 443, 3478, 5349, 10000/udp and 49160-49200/udp open.
- DNS A records for `EMR_DOMAIN`, `MEET_DOMAIN` and `TURN_DOMAIN` pointing at that IP.
- `cp .env.example .env` and replace every `change-me`. Secrets: `openssl rand -hex 32`.

## Start

```sh
docker compose up -d
docker compose logs -f openemr   # first boot takes a few minutes
```

Then in OpenEMR:

1. Administration > Modules > Manage Modules: Register, Install, Enable **OpenVisit Telehealth**.
2. Administration > Globals > OpenVisit: Jitsi domain = `MEET_DOMAIN`, JWT app id and secret
   as in `.env`.
3. Manage Modules > the gear icon next to OpenVisit runs the setup check. Everything green
   means a provider can start a visit from the calendar.

## Notes

- Jitsi runs behind Caddy on plain HTTP inside the network (`DISABLE_HTTPS=1`); Caddy provides
  the certificate for `MEET_DOMAIN`.
- coturn uses host networking because TURN relays need a wide UDP range. `TURN_SECRET` is
  shared with Jitsi, which hands out short-lived credentials to each participant.
- `JVB_ADVERTISE_IPS` must be the public IP, or participants outside the clinic get no media.
- Upgrades: change `OPENEMR_VERSION` or `JITSI_VERSION` in `.env`, then `docker compose pull`
  and `docker compose up -d`.

## Recording

Off by default. To let providers record visits, set in `.env`:

```sh
ENABLE_RECORDING=1
COMPOSE_PROFILES=recording
JIBRI_XMPP_PASSWORD=<openssl rand -hex 32>
JIBRI_RECORDER_PASSWORD=<openssl rand -hex 32>
```

then `docker compose up -d` and turn on **Allow recording** in Administration > Globals >
OpenVisit. The `jitsi-jibri` container records into the `recordings` volume, which OpenEMR sees
as `/var/openvisit/recordings`, the default inbox. The recorder needs about 2 GB of RAM while it
records, and it records one visit at a time. No kernel module is needed; this Jibri captures
audio through PulseAudio.
