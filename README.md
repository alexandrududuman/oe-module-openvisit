# OpenVisit

Self-hosted video visits for [OpenEMR](https://www.open-emr.org) 8.5+, powered by [Jitsi Meet](https://jitsi.org).

[![License: GPL-3.0-or-later](https://img.shields.io/badge/license-GPL--3.0--or--later-blue.svg)](LICENSE)

> **Status: early development.** The module installs and registers its settings, but video
> visits are not there yet. Follow the [implementation plan](docs/implementation-plan.md).
> The design lives in [docs/design.md](docs/design.md). Issues and comments on it are welcome.

## Why

OpenEMR has no free, self-hosted telehealth option that installs and just works. Clinics either
pay a per-provider monthly fee to a closed vendor, or paste Zoom and Jitsi links into appointment
notes by hand.

OpenVisit is a native OpenEMR module that talks to a Jitsi Meet server the clinic runs itself.

- No monthly fee. The clinic pays for its own server and nothing else.
- Patient data and video stay on infrastructure the clinic controls.
- Built for the OpenEMR 8.x module architecture: Composer install, event hooks, no core patches.

## What v1 will do

- **Start from the calendar.** The provider opens the visit from the appointment. The room is
  created on demand and an encounter is opened automatically, with the real start and end time.
- **Patients join from a link.** Sent by email, no portal account, no app. A browser is enough,
  on desktop or phone.
- **Waiting room.** The patient waits until the provider admits them.
- **Locked rooms.** Every session is protected by a short-lived JWT and a random room name.
  Nobody can guess their way into a visit.
- **Guest links** for an interpreter, a family member or a second clinician.
- **Self-check settings page.** It tests the Jitsi server, the JWT setup and TURN reachability,
  and tells you what is wrong.
- **Reference stack.** A `docker compose` file with OpenEMR, the module, Jitsi Meet, a TURN
  server and a reverse proxy, for a working setup in one command.

Planned after v1: recording into Documents with in-session consent, SMS invitations through the
Fax/SMS module, REST endpoints.

## Requirements

| Component   | Version                                      |
|-------------|----------------------------------------------|
| OpenEMR     | 8.5.0 or newer                               |
| PHP         | 8.3 or newer                                 |
| Jitsi Meet  | self-hosted, JWT authentication enabled      |
| TURN server | coturn, included in the reference stack      |

## Install

Not yet. Once the first release is tagged:

```sh
cd /path/to/openemr
composer require alexandrududuman/oe-module-openvisit
```

Then in OpenEMR go to Administration > Modules > Manage Modules and click Register, Install,
Enable. See [docs/install.md](docs/install.md) for the full steps, including the command line
path and the developer setup.

## Compatibility

| OpenVisit  | OpenEMR | Jitsi Meet            |
|------------|---------|-----------------------|
| unreleased | 8.5.0   | set at first release  |

## Project

- [Design document](docs/design.md): scope, decisions, what is out of v1, open questions.
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)

Contributions are welcome once the first release is out. Until then, open an issue to discuss
the design.

## License

GPL-3.0-or-later, the same license as OpenEMR. See [LICENSE](LICENSE).
