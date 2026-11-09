<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit;

use OpenEMR\Services\Globals\GlobalSetting;

/**
 * Single source of truth for the module's globals: keys, labels, types, defaults.
 *
 * @phpstan-type SettingDefinition array{label: literal-string, type: string, default: string|int, description: literal-string}
 */
final class Settings
{
    public const SECTION = 'OpenVisit';

    public const JITSI_DOMAIN = 'openvisit_jitsi_domain';
    public const JWT_APP_ID = 'openvisit_jwt_app_id';
    public const JWT_APP_SECRET = 'openvisit_jwt_app_secret';
    public const JOIN_BEFORE_MINUTES = 'openvisit_join_before_minutes';
    public const JOIN_AFTER_HOURS = 'openvisit_join_after_hours';
    public const ADHOC_DURATION_MINUTES = 'openvisit_adhoc_duration_minutes';
    public const ALLOW_ADHOC = 'openvisit_allow_adhoc';
    public const SEND_EMAIL = 'openvisit_send_email';
    public const ATTACH_VISIT_FORM = 'openvisit_attach_visit_form';
    public const SHOW_PATIENT_LAST_INITIAL = 'openvisit_show_patient_last_initial';
    public const CALENDAR_CATEGORY = 'openvisit_calendar_category';
    public const TRUSTED_PROXIES = 'openvisit_trusted_proxies';
    public const ALLOW_RECORDING = 'openvisit_allow_recording';
    public const RECORDINGS_INBOX = 'openvisit_recordings_inbox';

    /**
     * Labels and descriptions are English source strings; callers translate them.
     *
     * @return array<string, SettingDefinition>
     */
    public static function definitions(): array
    {
        return [
            self::JITSI_DOMAIN => [
                'label' => 'Jitsi domain',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
                'description' => 'Host name of the Jitsi Meet server, without https://, for example meet.clinic.example.',
            ],
            self::JWT_APP_ID => [
                'label' => 'JWT app id',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
                'description' => 'JWT_APP_ID configured on the Jitsi server.',
            ],
            self::JWT_APP_SECRET => [
                'label' => 'JWT app secret',
                'type' => GlobalSetting::DATA_TYPE_ENCRYPTED,
                'default' => '',
                'description' => 'JWT_APP_SECRET configured on the Jitsi server. Stored encrypted.',
            ],
            self::CALENDAR_CATEGORY => [
                'label' => 'Telehealth calendar category',
                'type' => GlobalSetting::DATA_TYPE_DEFAULT_VISIT_CATEGORY,
                'default' => '',
                'description' => 'Appointments in this category get the Start video visit button. Leave empty to use the Telehealth Visit category installed by the module.',
            ],
            self::TRUSTED_PROXIES => [
                'label' => 'Trusted proxy addresses',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
                'description' => 'Comma-separated IPs or CIDR ranges of the reverse proxy in front of OpenEMR, so the join page sees real patient addresses for rate limiting. Leave empty without a proxy.',
            ],
            self::JOIN_BEFORE_MINUTES => [
                'label' => 'Join window before appointment (minutes)',
                'type' => GlobalSetting::DATA_TYPE_NUMBER,
                'default' => 30,
                'description' => 'How early a patient link starts working.',
            ],
            self::JOIN_AFTER_HOURS => [
                'label' => 'Join window after appointment (hours)',
                'type' => GlobalSetting::DATA_TYPE_NUMBER,
                'default' => 2,
                'description' => 'How long after the scheduled time a link keeps working.',
            ],
            self::ADHOC_DURATION_MINUTES => [
                'label' => 'Default duration for ad-hoc visits (minutes)',
                'type' => GlobalSetting::DATA_TYPE_NUMBER,
                'default' => 15,
                'description' => 'Length of the appointment created when a visit starts without one.',
            ],
            self::ALLOW_ADHOC => [
                'label' => 'Allow ad-hoc visits',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => 1,
                'description' => 'Let a provider start a visit with a patient who has no appointment.',
            ],
            self::SEND_EMAIL => [
                'label' => 'Send invitations by email',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => 0,
                'description' => 'Uses the mail settings OpenEMR already has. Off: the provider copies the link.',
            ],
            self::ATTACH_VISIT_FORM => [
                'label' => 'Attach telehealth visit form to encounters',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => 0,
                'description' => 'Adds a short form with modality, consent and connection quality.',
            ],
            self::ALLOW_RECORDING => [
                'label' => 'Allow recording',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => 0,
                'description' => 'Lets the provider record a visit once at least two people are in the call. Needs Jibri on the Jitsi server.',
            ],
            self::RECORDINGS_INBOX => [
                'label' => 'Recordings inbox folder',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '/var/openvisit/recordings',
                'description' => 'Folder on the OpenEMR server where Jibri leaves finished recordings. OpenVisit moves them into the site documents folder.',
            ],
            self::SHOW_PATIENT_LAST_INITIAL => [
                'label' => 'Show patient last initial in the room',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => 0,
                'description' => 'Off: other participants see the first name only.',
            ],
        ];
    }
}
