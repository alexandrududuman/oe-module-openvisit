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
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RecordingFiles;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck\CheckResult;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck\Prober;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck\SelfCheck;
use OpenVisit\OpenEMR\Modules\OpenVisit\Settings;
use PHPUnit\Framework\TestCase;

final class SelfCheckTest extends TestCase
{
    /** @var array<string, array{status: int, body: string, error: string}> */
    private array $responses = [];
    private bool $mailer = true;
    private bool $background = true;
    private string $baseUrl = 'https://emr.clinic.example/openemr';
    private ?RecordingFiles $recordingFiles = null;

    /** @param array<string, string> $settings */
    private function check(array $settings): array
    {
        $source = new class ($settings) implements SettingsSource {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values) {}

            public function get(string $key): ?string
            {
                return $this->values[$key] ?? null;
            }
        };
        $test = $this;
        $prober = new class ($test) implements Prober {
            public function __construct(private readonly SelfCheckTest $test) {}

            public function get(string $url, int $timeoutSeconds = 5): array
            {
                return $this->test->response($url);
            }

            public function mailerConfigured(): bool
            {
                return $this->test->mailer();
            }

            public function backgroundServiceActive(string $name): bool
            {
                return $this->test->background();
            }

            public function publicBaseUrl(): string
            {
                return $this->test->baseUrl();
            }
        };
        $config = new ModuleConfig($source, static fn(string $v): string => $v);
        $results = (new SelfCheck($config, $prober, $this->recordingFiles))->run();
        $byId = [];
        foreach ($results as $result) {
            $byId[$result->id] = $result;
        }
        return $byId;
    }

    /** @return array{status: int, body: string, error: string} */
    public function response(string $url): array
    {
        return $this->responses[$url] ?? ['status' => 0, 'body' => '', 'error' => 'no route to host'];
    }

    public function mailer(): bool
    {
        return $this->mailer;
    }

    public function background(): bool
    {
        return $this->background;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @return array<string, string> */
    private function goodSettings(): array
    {
        return [
            Settings::JITSI_DOMAIN => 'meet.clinic.example',
            Settings::JWT_APP_ID => 'openvisit',
            Settings::JWT_APP_SECRET => str_repeat('s', 40),
        ];
    }

    private function jitsiUp(): void
    {
        $this->responses['https://meet.clinic.example/'] = ['status' => 200, 'body' => '<html>', 'error' => ''];
        $this->responses['https://meet.clinic.example/external_api.js'] = ['status' => 200, 'body' => 'window.JitsiMeetExternalAPI = ...', 'error' => ''];
        $this->responses['https://meet.clinic.example/config.js'] = ['status' => 200, 'body' => "hosts: { domain: 'meet.jitsi' }, enableUserRolesBasedOnToken: true", 'error' => ''];
    }

    public function testRecordingIsOnlyCheckedWhenTurnedOn(): void
    {
        $this->jitsiUp();

        self::assertArrayNotHasKey('recording', $this->check($this->goodSettings()));
    }

    public function testRecordingFailsWithoutAUsableInboxAndPassesWithOne(): void
    {
        $root = sys_get_temp_dir() . '/openvisit-check-' . bin2hex(random_bytes(4));
        $this->jitsiUp();
        $settings = $this->goodSettings() + [Settings::ALLOW_RECORDING => '1'];

        $this->recordingFiles = new RecordingFiles($root . '/inbox', $root . '/storage');
        $missing = $this->check($settings)['recording'];
        mkdir($root . '/inbox', 0777, true);
        $ready = $this->check($settings)['recording'];
        exec('rm -rf ' . escapeshellarg($root));

        self::assertSame(CheckResult::FAIL, $missing->status);
        self::assertStringContainsString('Jibri', $missing->fix);
        self::assertSame(CheckResult::PASS, $ready->status, $ready->detail);
    }

    public function testJitsiWithoutRecordingIsAWarningOnlyWhenRecordingIsOn(): void
    {
        $this->jitsiUp();
        self::assertArrayNotHasKey('jitsi-recording', $this->check($this->goodSettings()));

        $settings = $this->goodSettings() + [Settings::ALLOW_RECORDING => '1'];
        self::assertSame(CheckResult::WARN, $this->check($settings)['jitsi-recording']->status);

        $this->responses['https://meet.clinic.example/config.js']['body'] .= "\nconfig.recordingService = {\n    // file recording\n    enabled: true,\n};";
        self::assertSame(CheckResult::PASS, $this->check($settings)['jitsi-recording']->status);
    }

    public function testEverythingGreenWhenConfiguredAndReachable(): void
    {
        $this->jitsiUp();
        $results = $this->check($this->goodSettings());

        foreach (['settings', 'jitsi', 'iframe', 'jitsi-config', 'jwt', 'public-url', 'mail', 'background'] as $id) {
            self::assertSame(CheckResult::PASS, $results[$id]->status, $id . ': ' . $results[$id]->detail);
        }
        self::assertTrue(SelfCheck::allPassed(array_values($results)));
    }

    public function testMissingSettingsAreNamedAndNetworkChecksAreSkipped(): void
    {
        $results = $this->check([]);

        self::assertSame(CheckResult::FAIL, $results['settings']->status);
        self::assertStringContainsString('Jitsi domain', $results['settings']->detail);
        self::assertArrayNotHasKey('jitsi', $results);
        self::assertSame(CheckResult::FAIL, $results['jwt']->status);
        self::assertFalse(SelfCheck::allPassed(array_values($results)));
    }

    public function testUnreachableJitsiFailsWithTheTransportError(): void
    {
        $results = $this->check($this->goodSettings());

        self::assertSame(CheckResult::FAIL, $results['jitsi']->status);
        self::assertStringContainsString('no route to host', $results['jitsi']->detail);
        self::assertSame(CheckResult::FAIL, $results['iframe']->status);
    }

    public function testAnonymousJitsiIsAWarning(): void
    {
        $this->jitsiUp();
        $this->responses['https://meet.clinic.example/config.js'] = ['status' => 200, 'body' => "anonymousdomain: 'guest.meet.jitsi'", 'error' => ''];

        self::assertSame(CheckResult::WARN, $this->check($this->goodSettings())['jitsi-config']->status);
    }

    public function testShortSecretLocalAddressAndInactiveCleanupAreWarnings(): void
    {
        $this->jitsiUp();
        $this->baseUrl = 'https://localhost:9300';
        $this->background = false;
        $settings = $this->goodSettings();
        $settings[Settings::JWT_APP_SECRET] = 'short';

        $results = $this->check($settings);

        self::assertSame(CheckResult::WARN, $results['jwt']->status);
        self::assertSame(CheckResult::WARN, $results['public-url']->status);
        self::assertSame(CheckResult::WARN, $results['background']->status);
        self::assertTrue(SelfCheck::allPassed(array_values($results)), 'warnings do not block');
    }

    public function testLocalJitsiAddressIsAWarningNotAFailure(): void
    {
        $settings = $this->goodSettings();
        $settings[Settings::JITSI_DOMAIN] = 'localhost:8443';

        $results = $this->check($settings);

        self::assertSame(CheckResult::WARN, $results['jitsi']->status);
        self::assertArrayNotHasKey('iframe', $results);
        self::assertTrue(SelfCheck::allPassed(array_values($results)));
    }

    public function testEmailOnWithoutMailerFails(): void
    {
        $this->jitsiUp();
        $this->mailer = false;
        $settings = $this->goodSettings() + [Settings::SEND_EMAIL => '1'];

        self::assertSame(CheckResult::FAIL, $this->check($settings)['mail']->status);
        $this->mailer = true;
        self::assertSame(CheckResult::PASS, $this->check($settings)['mail']->status);
    }
}
