# Changelog

All notable changes to OpenVisit are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.5.0] - 2026-11-09

Hardening after an independent security review, and the reference stack proven on a public
domain.

### Security
- Staff actions on a visit (end, invite, revoke, participant events, opening the room) now
  require the appointment's provider or an administrator; other staff get the same answer as
  for a missing visit.
- Links of an ended visit are refused and an ended visit is no longer reopened by reloading
  the room; the link use cap is enforced atomically.
- Rate limiting counts only failed attempts, also per link, and honours a configurable list of
  trusted proxy addresses so patients behind a reverse proxy are not lumped together.
- Join events are accepted only from live links, once per link, and a link can only close its
  own participant row.
- The public join page sends no-store, nosniff, frame-ancestors none and a content security
  policy; links are never built from the Host header.
- Patient search requires the demographics permission, at least two characters, escapes
  wildcards and returns only the birth year; the room subject no longer names the patient.
- Portal joins are throttled per patient and get short-lived, few-use links.

## [0.4.0] - 2026-11-09

Portal patients can join, providers can start a visit on the spot, and the history page shows
what happened.

### Added
- Telehealth appointments carry a camera icon in the staff calendar.
- Unscheduled visits: on the Telehealth page a provider searches a patient and starts a visit
  right away; the module books the appointment in the Telehealth Visit category first, so the
  encounter and audit work exactly as for scheduled visits. Governed by the Allow ad-hoc visits
  setting.
- Visit history page: the last 30 days of telehealth visits with duration, participants and
  encounter number.
- Patient portal: telehealth appointments show a Join video visit button. The portal session
  identifies the patient; the module issues a regular join link and sends them to it.

## [0.3.0] - 2026-11-09

An administrator can see what is missing before the first visit, and a clinic can stand up the
whole stack with one command.

### Added
- Setup check in the Module Manager config page: settings, Jitsi reachability, IFrame API,
  authentication configuration, JWT signing, public address, mailer and cleanup service, each
  with a plain-language fix.
- Reference docker stack in `docker/`: OpenEMR with the module, MariaDB, Jitsi Meet with JWT,
  coturn and Caddy with Let's Encrypt.

## [0.2.0] - 2026-11-09

Patients and guests can join from a link, with a lobby. Nothing to install on their side.

### Added
- Patient and guest access: the provider creates join links from an Invite panel in the room;
  the link opens a public page with no login, shows the patient's first name only, and puts
  them in the Jitsi lobby until the provider admits them.
- Links are random, stored hashed, valid only in a window around the appointment, revocable,
  limited in uses, and the join page is rate limited per client.
- Optional email invitation through OpenEMR's own mailer, off by default.
- Participant audit: who joined and left each visit; the Telehealth page shows how many joined.
- Hourly background service that closes abandoned visits and purges old links.
- Browser journey with a phone-emulated patient: lobby, admission, revoked and wrong links.

### Changed
- Schema 0.2.0: `openvisit_participant.token_id`, new `openvisit_join_attempt` table, the
  `OpenVisitCleanup` background service row. Applied by `sql/0_1_0-to-0_2_0_upgrade.sql`.

## [0.1.0] - 2026-11-09

First installable release: a provider can run a video visit from the calendar.

### Added
- Provider video room: Start video visit from a Telehealth Visit appointment, opens a Jitsi room
  in a new tab with a per-session HS256 JWT, provider as moderator with lobby on, real start and
  end time written to the encounter.
- Telehealth Visit calendar category installed by the module; Telehealth page lists today's
  visits with their state and a Start or Rejoin button.
- Browser journey for the whole provider flow and a GitHub Actions job that boots OpenEMR 8.5,
  demo data and Jitsi in containers and runs every journey on each PR.
- Project README, GPL-3.0-or-later license and design document.
- Implementation plan with phased releases.
- Installable module skeleton: Composer package, Module Manager lifecycle hooks, idempotent
  `table.sql` with the session, token and participant tables, an OpenVisit tab in Globals, a
  menu entry under Modules and placeholder pages.
- Unit tests, PHPStan at level max against OpenEMR 8.5, PHP-CS-Fixer and GitHub Actions CI.
- Install guide in `docs/install.md`.
- Playwright browser journeys in `tests/e2e/` covering admin, physician and non-clinical users.

[Unreleased]: https://github.com/alexandrududuman/oe-module-openvisit/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/alexandrududuman/oe-module-openvisit/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/alexandrududuman/oe-module-openvisit/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/alexandrududuman/oe-module-openvisit/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/alexandrududuman/oe-module-openvisit/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/alexandrududuman/oe-module-openvisit/releases/tag/v0.1.0
