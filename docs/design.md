# OpenVisit design

Version 0.1, 2026-11-09. Target: OpenEMR v8_5_0.

This document fixes the scope and the technical decisions for the first release. It is short on
purpose. Anything not decided here is listed under [Open questions](#open-questions).

## Goal

A clinic running OpenEMR installs one Composer package, points it at a Jitsi Meet server it
controls, and runs video visits from the calendar. No per-provider fee, no third-party cloud, no
core patches.

Target users: small and mid-size clinics, community health centres, and the integrators who
support them. The module must be installable by an administrator following the README, without
reading PHP.

## Scope

### In v1

| Feature | Notes |
|---------|-------|
| Start a visit from an appointment | Button in the appointment dialog and on the calendar event. Room is created on first join. |
| Encounter opened automatically | When the provider joins. Real start and end time recorded on the session and written to the encounter. |
| Patient joins from an emailed link | No portal account needed. Single-use, time-limited join token. Works on phone and desktop browsers. |
| Waiting room | Jitsi lobby. Provider is moderator (JWT claim `context.user.moderator`, read by the `token_affiliation` prosody module), patient waits until admitted. |
| Locked rooms | Per-session JWT, random room name, short expiry. |
| Guest links | Interpreter, family member, second clinician. Same token mechanism, separate role. |
| Invitations | Default: the provider copies the join link and sends it through the clinic's own channel. Optional: email through OpenEMR's configured mailer, one toggle, off by default. |
| Ad-hoc visits | A provider can start a visit with a patient who has no appointment. The module creates the appointment in the telehealth category first. |
| Telehealth visit form (optional) | Off by default. When enabled, the auto-created encounter gets a short form: modality, patient consent, connection quality. |
| Portal integration (optional) | Patients with a portal account see a Join button on upcoming appointments. |
| Settings page with self-check | Tests Jitsi reachability, JWT validity, TURN reachability. Reports what is wrong in plain language. |
| Reference stack | `docker compose` with OpenEMR, module, Jitsi Meet, coturn, reverse proxy. |
| Audit trail | Who joined which session and when, in module tables and in the OpenEMR log. |

### Not in v1

- Recording (needs Jibri, a separate service). Planned for 1.x with in-session consent.
- SMS invitations. Email only in v1. SMS via the Fax/SMS module in 1.x.
- REST or FHIR endpoints.
- Scheduling from the portal.
- Group visits beyond provider, patient and guests.
- Billing helpers (telehealth modifiers, place-of-service codes).
- Any hosted or SaaS component. The module only ever talks to the Jitsi server the clinic configures.

## Architecture

```
Browser (provider)            Browser (patient / guest)
  OpenEMR UI                    public join page (module)
  Jitsi IFrame API  ---------   Jitsi IFrame API
        |                              |
        v                              v
  Jitsi Meet server (clinic-hosted, JWT auth, lobby)  <--> coturn
        ^
        | JWT signed with shared secret
  OpenEMR + OpenVisit module (PHP, MariaDB)
```

The module never proxies media. It issues tokens, records sessions and renders pages. Jitsi
handles video. TURN handles NAT traversal.

### Package

- Composer name `alexandrududuman/oe-module-openvisit`, type `openemr-module`, requires
  `openemr/oe-module-installer-plugin ^0.1`. The plugin relocates the package into
  `interface/modules/custom_modules/oe-module-openvisit`.
- PHP namespace `OpenVisit\OpenEMR\Modules\OpenVisit\`, PSR-4 from `src/`. Vendor-prefixed, as
  core's module boundary rules expect.
- `conflict` with `openemr/openemr < 8.5.0`.

### Layout

```
oe-module-openvisit/
  composer.json
  info.txt                    display name, read by the Module Manager
  version.php                 $v_major/$v_minor/$v_patch for sql_version tracking
  openemr.bootstrap.php       registers namespace, subscribes listeners
  ModuleManagerListener.php   install/enable/disable hooks (global namespace, core requirement)
  moduleConfig.php            settings page shown in the Module Manager iframe
  sql/install.sql             idempotent, #IfNotTable guarded
  sql/<from>-to-<to>_upgrade.sql
  src/
    Bootstrap.php             wiring only
    Listener/                 one class per core event
    Controller/               request handlers for public/ pages
    Service/                  JWT, session, token, encounter, notification, self-check
    Repository/               SQL via QueryUtils
    Model/                    readonly value objects
  public/
    index.php                 provider-side actions (join, end, invite)
    join.php                  patient and guest entry, token only
    portal.php                portal-side actions
    assets/                   module CSS and JS; no Jitsi code vendored
  templates/                  Twig
  tests/
    Unit/                     no database
    Integration/              runs against the dev docker stack
  docker/                     reference compose stack
  docs/
```

## Integration with OpenEMR core

All integration is through events and core services. No core file is modified.

| Need | Core seam | Decision |
|------|-----------|----------|
| Button in appointment dialog | `AppointmentRenderEvent::RENDER_BELOW_PATIENT`, `RENDER_JAVASCRIPT` | Render a Start/Join button when the appointment category is marked as telehealth. |
| Mark telehealth appointments | `openemr_postcalendar_categories` row inserted by `sql/install.sql`, as other modules do | One "Telehealth" category. Clinics can rename it. |
| Session creation | `AppointmentSetEvent` fires only from the staff calendar dialog, not from REST or the portal | Do not rely on it. Create the session row lazily on the first join request, keyed by appointment id. |
| Encounter creation | No "encounter created" event in core | Call `AppointmentService::createEncounterForAppointment` when the provider joins, if the appointment has no encounter yet. Core stamps the encounter at midnight, so the module sets `form_encounter.date` to the real start and `date_end` when the visit ends. |
| Calendar decoration | `CalendarUserGetEventsFilter` | Add a camera icon to telehealth events. |
| Settings | `GlobalsInitializedEvent`, `GlobalsService::createSection` | Section "OpenVisit". Secret stored with `DATA_TYPE_ENCRYPTED`. |
| Menu entry | `MenuEvent::MENU_UPDATE` | Top-level "Telehealth" right after Calendar, visible to anyone with `patients/appt`. The core Modules menu is hidden from clinicians, so nothing user-facing goes under it. |
| Portal Join button | `PatientPortal\AppointmentFilterEvent` plus Twig override of `portal/appointment-item.html.twig` via `TwigEnvironmentEvent` `prependPath` | The filter alone cannot add a link; the template only prints four fields. |
| Portal page assets | `fireEvent` hooks `home.scripts.render.pre`, `home.dashboard.render.scripts` | Listeners receive a `GenericEvent`. |
| Scripts on core pages | `ScriptFilterEvent` only accepts local files under `interface/modules` | The Jitsi IFrame API is loaded only on module-owned pages, from the clinic's Jitsi server. Never injected into core pages. |
| ACL | No ACL installer for custom modules in core, and no `modules` ACL section exists | No new ACO in v1. Session actions require `patients/appt`, the settings page requires `admin/super`, the same permissions the appointment dialog and Globals already use. |
| Templates | `TwigContainer` with module template dir | Autoescape is off in core Twig. Every variable goes through `text`, `attr`, `js_escape` or `attr_url`. |
| Database access | `QueryUtils` | No raw `sqlStatement` in module code. |
| Request input | Symfony `Request` | No superglobals, matching core's PHPStan rules. |
| Background cleanup | `background_services` row | Purge expired tokens and close abandoned sessions hourly. Row toggled in enable/disable hooks. |

Module lifecycle hooks (`ModuleManagerListener`) run only from the web Module Manager, not from
the `openemr:zfc-module` CLI. Everything the hooks do must therefore also be safe to re-run and
`sql/install.sql` must be idempotent.

## Jitsi integration

- **Server**: self-hosted `docker-jitsi-meet` with `ENABLE_AUTH=1`, `AUTH_TYPE=jwt`,
  `ENABLE_LOBBY=1`. The reference stack ships this configuration. Any Jitsi deployment with JWT
  enabled works.
- **Tokens**: HS256 JWT signed with the shared `JWT_APP_SECRET`. Claims: `aud`, `iss`, `sub`
  (Jitsi domain), `room` (exact room name, never `*`), `exp`, `nbf`, `context.user` with display
  name and role. `moderator: true` only for the provider. Expiry: appointment end plus 2 hours.
  Tokens are minted per page load and never stored.
- **Room names**: `ov-` plus 22 characters from a CSPRNG, generated when the session row is
  created. Stored on the session. Never derived from patient or appointment data.
- **Lobby**: enabled per room by the provider's moderator token on join. Patients and guests land
  in the lobby and are admitted by the provider.
- **Client**: Jitsi IFrame API, loaded from `https://<jitsi-domain>/external_api.js` on module
  pages so that client and server versions always match. Hidden toolbar buttons: invite, record,
  livestream, security dialog.
- **End of visit**: provider clicks End. The module records the end time, updates the encounter,
  and the Jitsi room closes when the last participant leaves. The audit row keeps join and leave
  timestamps from the IFrame API events, confirmed server-side.

## Patient and guest access

Patients do not need a portal account.

1. The provider clicks Invite on the appointment. The module creates a join token for the patient:
   32 random bytes, stored as a SHA-256 hash with the session id, role, expiry and use count.
2. The module shows the link `https://<openemr>/interface/modules/custom_modules/oe-module-openvisit/public/join.php?t=<token>`
   with a Copy button. The provider sends it through whatever channel the clinic already uses.
   If "Send invitations by email" is enabled and the patient has an email address, the module
   also sends it through OpenEMR's configured mailer. The email contains the clinic name, the
   date and time, and the link. No diagnosis, no provider notes. The module never adds its own
   mail configuration, so existing mail setups are untouched.
3. `join.php` validates the token: exists, not expired, not revoked, within the join window
   (default: 30 minutes before the appointment to 2 hours after). It shows a pre-join page with
   the patient's first name, camera and microphone check, and the Join button.
4. On Join the server mints a Jitsi JWT for that room and role and renders the IFrame.

Guest links work the same with role `guest` and no patient name shown. Tokens are revocable from
the session page. The join window and expiry are globals.

`join.php` runs outside the staff session. It includes `globals.php` with `$ignoreAuth = true`,
authenticates by token only, and exposes no other action. Because there is no session yet the
link carries `site=<site id>`. The page is rate limited per client address (10 attempts per 10
minutes, counted in `openvisit_join_attempt`) and every link has a use limit of 20 opens. Join and
leave events from the patient's browser go to `join-events.php`, again authenticated by the link,
and are confirmed server-side into `openvisit_participant`.

## Data model

```sql
openvisit_session
  id, pc_eid (appointment), encounter (nullable), room_name, provider_id,
  status (scheduled|active|ended|cancelled), started_at, ended_at, created_at

openvisit_token
  id, session_id, role (patient|guest), token_hash, label, expires_at,
  revoked_at, uses, max_uses, created_by, created_at

openvisit_participant
  id, session_id, role, display_name, joined_at, left_at, user_agent
```

All tables are created from `sql/install.sql` with `#IfNotTable` guards. Schema changes ship as
`sql/<from>-to-<to>_upgrade.sql` and bump `version.php`.

## Settings

Section "OpenVisit" in Administration > Globals:

| Setting | Type | Default |
|---------|------|---------|
| Jitsi domain | text | empty, required |
| JWT app id | text | empty, required |
| JWT app secret | encrypted | empty, required |
| Join window before appointment (minutes) | number | 30 |
| Join window after appointment (hours) | number | 2 |
| Telehealth calendar category | select | the category installed by the module |
| Default appointment duration for ad-hoc visits (minutes) | number | 15 |
| Allow ad-hoc visits | checkbox | on |
| Send invitations by email | checkbox | off |
| Attach telehealth visit form to encounters | checkbox | off |
| Show patient last initial in the room | checkbox | off |

The module settings page (Module Manager iframe) runs the self-check: DNS and HTTPS to the Jitsi
domain, a signed test token accepted by the server, TURN host and port reachable, OpenEMR email
configured. Each check reports pass or fail with a one-line fix.

## Security

- Secrets in the `globals` table only, encrypted with core's `CryptoGen`. Never in the repo,
  never in logs.
- Join tokens stored hashed. Comparison in constant time.
- Every staff-side action checks the module ACO and a CSRF token.
- Room names, tokens and JWTs are unguessable and short-lived.
- Patient pages show the minimum: first name, date, time, clinic name.
- All module queries are parameterised through `QueryUtils`.
- Audit: session start and end, token creation and revocation, every join, logged with the core
  `EventAuditLogger`.
- Dependencies pinned in `composer.json`, `composer audit` in CI.

## Quality

- `declare(strict_types=1)` and full native types everywhere. PHPStan level max against the
  OpenEMR 8.5 codebase.
- Unit tests without a database for token, JWT, join window and self-check logic.
- Integration tests against the `development-easy-light` stack, run in CI with a service
  container.
- GitHub Actions on every PR: PHPStan, PHPUnit, `composer audit`, PHP-CS-Fixer with the PSR-12
  ruleset.
- Browser journeys in `tests/e2e/` (Playwright) drive the real OpenEMR UI as each role would:
  menus, forms, dialogs, portal, the Jitsi pre-join screen. Every phase adds its journey. They
  run on every pull request in GitHub Actions against `tests/e2e/stack/`: the official
  `openemr/openemr:8.5.0` image with the module mounted, MariaDB with the OpenEMR demo data,
  and the official Jitsi images with JWT auth. A journey that only passes on a developer's
  machine does not count.
- Manual checklist per release on real devices: patient join on an Android and an iOS phone,
  audio and video quality, lobby admit, end visit.

## Release process

- Semantic Versioning. `0.x` while the schema and settings may still change. `1.0.0` when the v1
  table above is complete and a clinic runs it in production.
- Each release: annotated git tag `vX.Y.Z`, `CHANGELOG.md` entry in Keep a Changelog format,
  GitHub Release with the same notes, Packagist picks up the tag automatically.
- `main` is always installable. Work happens on short-lived branches merged by pull request.
- The README compatibility table is updated on every release with the OpenEMR and Jitsi versions
  tested.
- The repository is private until the first release is installable and the documentation is
  complete. Then it goes public and is submitted to Packagist and announced on the OpenEMR forum.

## Decisions

Resolved on 2026-11-09.

1. **Encounter form.** A plain encounter by default. The optional "Telehealth visit" form ships
   in v1 behind a setting that is off by default, so a clinic that wants it turns on one checkbox.
2. **Email.** Off by default. The module never sends mail unless the administrator enables it,
   and then only through OpenEMR's existing mailer settings. Nothing new to configure and no
   risk of interfering with a customised installation.
3. **Ad-hoc visits.** Allowed. The module creates the appointment first, in the telehealth
   category, so the session is always tied to an appointment and an encounter.
4. **Patient display name.** First name only by default, with a checkbox to add the last
   initial.
5. **Multi-site.** Each OpenEMR site has its own database and its own globals, so the Jitsi
   configuration is per site with no extra work. Several sites may share one Jitsi server;
   random room names prevent collisions. No multi-site code in the module.
