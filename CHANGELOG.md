# Changelog

All notable changes to OpenVisit are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Project README, GPL-3.0-or-later license and design document.
- Implementation plan with phased releases.
- Installable module skeleton: Composer package, Module Manager lifecycle hooks, idempotent
  `table.sql` with the session, token and participant tables, an OpenVisit tab in Globals, a
  menu entry under Modules and placeholder pages.
- Unit tests, PHPStan at level max against OpenEMR 8.5, PHP-CS-Fixer and GitHub Actions CI.
- Install guide in `docs/install.md`.
- Playwright browser journeys in `tests/e2e/` covering admin, physician and non-clinical users.
