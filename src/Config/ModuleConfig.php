<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Config;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Settings;

/** Typed access to the module globals, with the defaults from Settings when a value was never saved. */
final class ModuleConfig
{
    private const DOMAIN_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{2,5})?$/i';

    /** @param \Closure(string): string $decrypt */
    public function __construct(
        private readonly SettingsSource $source,
        private readonly \Closure $decrypt,
    ) {}

    public static function fromGlobals(): self
    {
        $crypto = ServiceContainer::getCrypto();
        return new self(
            new GlobalsSettingsSource(OEGlobalsBag::getInstance()),
            static fn(string $value): string => $crypto->decryptFromDatabase($value),
        );
    }

    public function jitsiDomain(): string
    {
        $domain = strtolower(trim($this->value(Settings::JITSI_DOMAIN)));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        return preg_match(self::DOMAIN_PATTERN, $domain) === 1 ? $domain : '';
    }

    public function jwtAppId(): string
    {
        return trim($this->value(Settings::JWT_APP_ID));
    }

    public function jwtAppSecret(): string
    {
        $stored = $this->value(Settings::JWT_APP_SECRET);
        return $stored === '' ? '' : ($this->decrypt)($stored);
    }

    public function isConfigured(): bool
    {
        return $this->jitsiDomain() !== '' && $this->jwtAppId() !== '' && $this->jwtAppSecret() !== '';
    }

    public function joinBeforeMinutes(): int
    {
        return max(0, (int) $this->value(Settings::JOIN_BEFORE_MINUTES));
    }

    public function joinAfterHours(): int
    {
        return max(0, (int) $this->value(Settings::JOIN_AFTER_HOURS));
    }

    /** Category chosen in Globals, or null to fall back to the category the module installs. */
    public function calendarCategoryId(): ?int
    {
        $raw = $this->value(Settings::CALENDAR_CATEGORY);
        return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    public function showPatientLastInitial(): bool
    {
        return $this->value(Settings::SHOW_PATIENT_LAST_INITIAL) === '1';
    }

    private function value(string $key): string
    {
        $stored = $this->source->get($key);
        if ($stored !== null) {
            return $stored;
        }
        return (string) Settings::definitions()[$key]['default'];
    }
}
