# OpenVisit

Self-hosted video visits for [OpenEMR](https://www.open-emr.org) 8.2+, powered by [Jitsi Meet](https://jitsi.org).

[![License: GPL-3.0-or-later](https://img.shields.io/badge/license-GPL--3.0--or--later-blue.svg)](LICENSE)

> **Status: before 1.0.** Everything below works against a self-hosted Jitsi and is covered by
> browser journeys on every pull request. Follow the [implementation plan](docs/implementation-plan.md).

## Documentation

| You want to | Read |
|---|---|
| Install it on your OpenEMR | [docs/install.md](docs/install.md) |
| Stand up OpenEMR, Jitsi and TURN in one command | [docker/README.md](docker/README.md) |
| Run video visits, record them, use the floating window | [docs/user-guide.md](docs/user-guide.md) |
| Understand how it is built | [docs/design.md](docs/design.md) |
| See what changed | [CHANGELOG.md](CHANGELOG.md) |

## Why

OpenEMR has no free, self-hosted telehealth option that installs and just works. Clinics either
pay a per-provider monthly fee to a closed vendor, or paste Zoom and Jitsi links into appointment
notes by hand.

OpenVisit is a native OpenEMR module that talks to a Jitsi Meet server the clinic runs itself.

- No monthly fee. The clinic pays for its own server and nothing else.
- Patient data and video stay on infrastructure the clinic controls.
- Built for the OpenEMR 8.x module architecture: Composer install, event hooks, no core patches.

## What it does

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
- **A call that floats.** The visit opens in a window over OpenEMR. Moving elsewhere tucks it
  into a corner, like Google Meet, with mute, camera, admit and leave at hand.
- **Recording**, optional. Once the patient is in the call the provider can record, after
  confirming consent; everyone sees that the visit is recorded, and the file plays back from
  the visit history. Needs Jibri next to Jitsi.

Planned after v1: SMS invitations through the Fax/SMS module, REST endpoints.

## Requirements

| Component   | Version                                      |
|-------------|----------------------------------------------|
| OpenEMR     | 8.2.0 or newer                               |
| PHP         | 8.3 or newer                                 |
| Jitsi Meet  | self-hosted, JWT authentication enabled      |
| TURN server | coturn, included in the reference stack      |

## Install

```sh
cd /path/to/openemr
composer require alexandrududuman/oe-module-openvisit
```

Or unpack the release archive into `interface/modules/custom_modules/oe-module-openvisit`.
Then in OpenEMR go to Administration > Modules > Manage Modules and click Install, then Enable.
See [docs/install.md](docs/install.md) for the full steps, the official docker image, upgrades
and the developer setup.

## Compatibility

| OpenVisit  | OpenEMR                  | Jitsi Meet (docker-jitsi-meet) |
|------------|--------------------------|--------------------------------|
| unreleased | 8.2.0 to 8.6.0 (next)    | stable-11248-3                 |
| 0.5.0      | 8.5.0                    | stable-11248-3                 |

Every pull request runs unit tests, PHPStan at level max against OpenEMR 8.5, browser journeys
that drive a real OpenEMR and Jitsi stack in containers, and an install journey that adds the
module to a pristine OpenEMR, sets it up through the Module Manager and runs a first visit. On
an OpenEMR older than 8.2.0 the module stays inactive and core keeps working.

## Project

- [Design document](docs/design.md): scope, decisions, what is out of v1, open questions.
- [Reference docker stack](docker/README.md): OpenEMR, Jitsi, coturn and TLS in one command.
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)

Contributions are welcome once the first release is out. Until then, open an issue to discuss
the design.

## License

Copyright (c) 2026 Alexandru Duduman. Licensed under GPL-3.0-or-later, the same license as
OpenEMR. See [LICENSE](LICENSE). The bundled Atkinson Hyperlegible font is copyright Braille
Institute of America under the SIL Open Font License, see `public/assets/fonts/OFL.txt`.
