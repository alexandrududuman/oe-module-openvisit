# Changelog

All notable changes to OpenVisit are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
