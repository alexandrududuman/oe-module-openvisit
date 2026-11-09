# Implementation plan

Derived from [design.md](design.md). Each phase ends in a working, installable state. Phases 1
to 5 each end with a tagged release so that the compatibility table and changelog grow with the
code. Work happens on a branch per phase, merged to `main` by pull request.

## Phase 0: skeleton

Installable module that does nothing visible except register itself.

- `composer.json` (type `openemr-module`), `info.txt`, `version.php`, `openemr.bootstrap.php`
- `ModuleManagerListener.php` with install, enable, disable hooks and ACO creation
- `sql/table.sql` with the three tables, `#IfNotTable` guarded
- Globals section "OpenVisit" with all settings from the design
- Menu entry under Modules, gated by the ACO, pointing to a placeholder page
- `moduleConfig.php` placeholder
- PHPUnit and PHPStan configured, GitHub Actions running both on every PR
- `docs/install.md` first draft

Done when: Register, Install, Enable, Disable all succeed through the Module Manager UI on the
8.5 dev stack, the settings tab and menu entry render, the Playwright journey in `tests/e2e/`
passes for admin, physician and a non-clinical user, PHPStan passes at level max, CI is green.

## Phase 1: provider room (v0.1.0)

A provider can open a video room from an appointment.

- `SessionService`: find or create the session row for an appointment, random room name
- `JwtService`: mint Jitsi tokens, moderator flag by role
- Calendar: telehealth category, Start button in the appointment dialog, camera icon on events
- `public/index.php` room page with the Jitsi IFrame API loaded from the configured domain
- Encounter created on provider join through `AppointmentService::createEncounterForAppointment`
- End visit: records end time, updates the encounter, closes the session
- Audit rows for session start and end
- Unit tests for `JwtService`, `SessionService`, room name generation

Done when: the browser journey books a Telehealth Visit appointment as a physician, starts the
visit from the appointment dialog, joins the Jitsi room as moderator, ends it, sees it as ended
on the Telehealth page, and the encounter carries the real start and end time. The journey runs
in GitHub Actions against a containerised OpenEMR 8.5 with demo data and Jitsi.

Moved to phase 4: the camera icon on calendar events (cosmetic, needs the calendar filter event).

## Phase 2: patient and guest access (v0.2.0)

Patients join from a link without an account.

- `TokenService`: create, validate, revoke; hashed storage; join window
- `public/join.php`: token-only entry, pre-join page, device check, lobby
- Invite dialog for the provider: copy link, revoke, guest links with a label
- Optional email invitation through the core mailer, behind the setting
- Rate limiting on `join.php`
- Background service: purge expired tokens, close abandoned sessions
- Participant audit from IFrame API join and leave events, confirmed server-side
- Unit tests for token lifecycle and join window edge cases

Done when: the browser journey, with a phone-emulated patient, opens the link without a login,
sees only the first name, waits in the lobby, is admitted by the provider, a revoked guest link
and a wrong link are refused with a plain message, and the Telehealth page shows who joined.

## Phase 3: setup experience (v0.3.0)

A clinic administrator can get a working stack without help.

- Settings page self-check: Jitsi HTTPS, JWT accepted, TURN reachable, mailer configured
- Reference `docker/` stack: OpenEMR, module, Jitsi Meet with JWT and lobby, coturn, reverse
  proxy with TLS
- `docs/install.md` complete, with the self-check output explained
- `docs/jitsi.md`: how to configure an existing Jitsi server for the module

Done when: the reference stack validates and documents a one-command clinic install, and the
browser journey opens the setup check as an administrator and reads a ready verdict with every
check explained.

## Phase 4: portal and clinical polish (v0.4.0)

Delivered in slices: 4a portal Join button; 4b ad-hoc visits and the sessions page; 4c the
optional telehealth visit form and the calendar icon.

- Portal: Join button on upcoming telehealth appointments, template override, scripts via the
  dashboard hooks
- Optional "Telehealth visit" encounter form, off by default
- Ad-hoc visit: start with a patient without an appointment
- Sessions page: today's sessions, audit, revoke tokens
- Patient display name setting

Done when: the demo portal patient sees and uses the Join button, and an enabled telehealth
form appears on the encounter.

## Phase 5: release hardening (v1.0.0)

- Integration tests against the dev stack in CI
- Manual E2E checklist executed on Chrome, Firefox, Safari and one Android and one iOS phone
- Security review of token flow, JWT claims, CSRF, ACL, rate limiting
- Documentation pass: README, install, Jitsi, troubleshooting, FAQ
- Repository goes public, Packagist submission, OpenEMR forum announcement

Done when: a clinic runs it in production for real visits.

## After 1.0

Recording with consent (Jibri), SMS invitations through the Fax/SMS module, REST endpoints,
billing helpers, a hosted Jitsi option for clinics that do not want to run their own server.
