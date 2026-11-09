<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\SettingsSource;
use OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\InviteService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\TokenService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Settings;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\FakeGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryAttemptStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemorySessionStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryTokenStore;
use PHPUnit\Framework\TestCase;

final class InviteServiceTest extends TestCase
{
    private FakeGateway $gateway;
    private Visit $visit;
    private Appointment $appointment;

    protected function setUp(): void
    {
        $this->gateway = new FakeGateway();
        $this->appointment = new Appointment(42, 7, 6, 11, '2026-11-09', '10:30:00', 900, 'Phil', 'Belford', 'Donna', 'Lee');
        $this->gateway->appointments[42] = $this->appointment;
        $this->visit = (new InMemorySessionStore())->create(42, 6, 'ov-room');
    }

    /** @param array<string, string> $settings */
    private function service(array $settings): InviteService
    {
        $source = new class ($settings) implements SettingsSource {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values) {}

            public function get(string $key): ?string
            {
                return $this->values[$key] ?? null;
            }
        };
        $config = new ModuleConfig($source, static fn(string $v): string => $v);
        $tokens = new TokenService(new InMemoryTokenStore(), new InMemorySessionStore(), new InMemoryAttemptStore(), $this->gateway, $config);
        return new InviteService($tokens, $this->gateway, $config);
    }

    public function testEmailIsOffByDefaultAndSaysSo(): void
    {
        $service = $this->service([]);

        self::assertFalse($service->emailEnabled());
        $this->expectException(VisitException::class);
        $this->expectExceptionMessageMatches('/turned off/');
        $service->emailPatient($this->visit, $this->appointment, 'https://x/join', 6);
    }

    public function testEmailGoesThroughCoreMailerWhenEnabled(): void
    {
        $service = $this->service([Settings::SEND_EMAIL => '1']);

        $masked = $service->emailPatient($this->visit, $this->appointment, 'https://x/join?t=abc', 6);

        self::assertSame('p***@example.test', $masked);
        self::assertCount(1, $this->gateway->sent);
        self::assertSame('phil@example.test', $this->gateway->sent[0]['to']);
    }

    public function testMissingPatientEmailIsReportedPlainly(): void
    {
        $this->gateway->email = '';
        $this->expectException(VisitException::class);
        $this->expectExceptionMessageMatches('/no valid email/');
        $this->service([Settings::SEND_EMAIL => '1'])->emailPatient($this->visit, $this->appointment, 'https://x', 6);
    }

    public function testMailerFailureIsReportedPlainly(): void
    {
        $this->gateway->mailConfigured = false;
        $this->expectException(VisitException::class);
        $this->expectExceptionMessageMatches('/could not be sent/');
        $this->service([Settings::SEND_EMAIL => '1'])->emailPatient($this->visit, $this->appointment, 'https://x', 6);
    }

    public function testCreateReturnsAUsableLinkOnce(): void
    {
        $link = $this->service([])->create($this->visit, $this->appointment, 'guest', '  Interpreter  ', 6);

        self::assertSame('guest', $link['role']);
        self::assertSame('Interpreter', $link['label']);
        self::assertStringContainsString('/public/join.php?t=', $link['url']);
        self::assertStringContainsString('&site=default', $link['url']);
    }
}
