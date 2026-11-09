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
| Waiting room | Jitsi lobby. Provider is moderator, patient waits until admitted. |
| Locked rooms | Per-session JWT, random room name, short expiry. |
| Guest links | Interpreter, family member, second clinician. Same token mechanism, separate role. |
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
  sql/table.sql               idempotent, #IfNotTable guarded
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
| Mark telehealth appointments | `openemr_postcalendar_categories` row inserted by `table.sql`, as other modules do | One "Telehealth" category. Clinics can rename it. |
| Session creation | `AppointmentSetEvent` fires only from the staff calendar dialog, not from REST or the portal | Do not rely on it. Create the session row lazily on the first join request, keyed by appointment id. |
| Encounter creation | No "encounter created" event in core | Call `AppointmentService::createEncounterForAppointment` when the provider joins, if the appointment has no encounter yet. Record start and end on the session, update the encounter on end. |
| Calendar decoration | `CalendarUserGetEventsFilter` | Add a camera icon to telehealth events. |
| Settings | `GlobalsInitializedEvent`, `GlobalsService::createSection` | Section "OpenVisit". Secret stored with `DATA_TYPE_ENCRYPTED`. |
| Menu entry | `MenuEvent::MENU_UPDATE` | Under Modules: "OpenVisit sessions" (today's sessions, audit) guarded by the module ACO. |
| Portal Join button | `PatientPortal\AppointmentFilterEvent` plus Twig override of `portal/appointment-item.html.twig` via `TwigEnvironmentEvent` `prependPath` | The filter alone cannot add a link; the template only prints four fields. |
| Portal page assets | `fireEvent` hooks `home.scripts.render.pre`, `home.dashboard.render.scripts` | Listeners receive a `GenericEvent`. |
| Scripts on core pages | `ScriptFilterEvent` only accepts local files under `interface/modules` | The Jitsi IFrame API is loaded only on module-owned pages, from the clinic's Jitsi server. Never injected into core pages. |
| ACL | No ACL installer for custom modules in core | Create ACO `openvisit` under section `modules` in the `install` hook with `AclExtended::addObjectAcl`. Default: granted to Physicians and Clinicians groups. |
| Templates | `TwigContainer` with module template dir | Autoescape is off in core Twig. Every variable goes through `text`, `attr`, `js_escape` or `attr_url`. |
| Database access | `QueryUtils` | No raw `sqlStatement` in module code. |
| Request input | Symfony `Request` | No superglobals, matching core's PHPStan rules. |
| Background cleanup | `background_services` row | Purge expired tokens and close abandoned sessions hourly. Row toggled in enable/disable hooks. |

Module lifecycle hooks (`ModuleManagerListener`) run only from the web Module Manager, not from
the `openemr:zfc-module` CLI. Everything the hooks do must therefore also be safe to re-run and
`table.sql` must be idempotent.

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
2. The patient receives an email with `https://<openemr>/interface/modules/custom_modules/oe-module-openvisit/public/join.php?t=<token>`.
   The email contains the clinic name, the date and time, and the link. No diagnosis, no
   provider notes.
3. `join.php` validates the token: exists, not expired, not revoked, within the join window
   (default: 30 minutes before the appointment to 2 hours after). It shows a pre-join page with
   the patient's first name, camera and microphone check, and the Join button.
4. On Join the server mints a Jitsi JWT for that room and role and renders the IFrame.

Guest links work the same with role `guest` and no patient name shown. Tokens are revocable from
the session page. The join window and expiry are globals.

`join.php` runs outside the staff session. It includes `globals.php` with `$ignoreAuth = true`,
authenticates by token only, and exposes no other action. It is rate limited per IP and per
token.

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

All tables are created from `sql/table.sql` with `#IfNotTable` guards. Schema changes ship as
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
- Manual E2E checklist per release: provider join, patient join on a phone, lobby admit, guest
  join, end visit, encounter duration.

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

## Open questions

1. **Encounter form**: should the module open a plain encounter, or also attach a short
   "Telehealth visit" form with modality, consent and connection quality? Proposal: plain
   encounter in v1, form in 1.x.
2. **Email sender**: core `MyMailer` with the practice return address, or a module-specific
   address? Proposal: core settings, nothing new to configure.
3. **Ad-hoc visits**: allow a provider to start a visit with a patient who has no appointment
   today? Proposal: yes, the module creates the appointment in the telehealth category first.
4. **Patient display name**: first name only, or first name and last initial? Proposal: first
   name only.
5. **Multi-site**: OpenEMR supports several sites per install. v1 assumes one Jitsi server per
   site, configured in that site's globals. Confirm this is acceptable.
