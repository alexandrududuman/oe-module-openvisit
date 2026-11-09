<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\SettingsSource;
use OpenVisit\OpenEMR\Modules\OpenVisit\Settings;
use PHPUnit\Framework\TestCase;

final class ModuleConfigTest extends TestCase
{
    /** @param array<string, string> $values */
    private function config(array $values): ModuleConfig
    {
        $source = new class ($values) implements SettingsSource {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values) {}

            public function get(string $key): ?string
            {
                return $this->values[$key] ?? null;
            }
        };
        return new ModuleConfig($source, static fn(string $v): string => 'decrypted:' . $v);
    }

    public function testDefaultsApplyWhenNothingWasSaved(): void
    {
        $config = $this->config([]);

        self::assertFalse($config->isConfigured());
        self::assertSame(30, $config->joinBeforeMinutes());
        self::assertSame(2, $config->joinAfterHours());
        self::assertNull($config->calendarCategoryId());
        self::assertFalse($config->showPatientLastInitial());
    }

    public function testConfiguredWhenDomainAppIdAndSecretExist(): void
    {
        $config = $this->config([
            Settings::JITSI_DOMAIN => 'Meet.Clinic.Example',
            Settings::JWT_APP_ID => ' openvisit ',
            Settings::JWT_APP_SECRET => 'blob',
        ]);

        self::assertTrue($config->isConfigured());
        self::assertSame('meet.clinic.example', $config->jitsiDomain());
        self::assertSame('openvisit', $config->jwtAppId());
        self::assertSame('decrypted:blob', $config->jwtAppSecret());
    }

    public function testDomainAcceptsPortAndStripsScheme(): void
    {
        self::assertSame('localhost:8443', $this->config([Settings::JITSI_DOMAIN => 'https://localhost:8443/'])->jitsiDomain());
        self::assertSame('', $this->config([Settings::JITSI_DOMAIN => 'meet.example/path'])->jitsiDomain());
        self::assertSame('', $this->config([Settings::JITSI_DOMAIN => 'bad host name'])->jitsiDomain());
    }

    public function testCategoryIsOnlyAPositiveInteger(): void
    {
        self::assertSame(11, $this->config([Settings::CALENDAR_CATEGORY => '11'])->calendarCategoryId());
        self::assertNull($this->config([Settings::CALENDAR_CATEGORY => '_blank'])->calendarCategoryId());
        self::assertNull($this->config([Settings::CALENDAR_CATEGORY => '0'])->calendarCategoryId());
    }
}
