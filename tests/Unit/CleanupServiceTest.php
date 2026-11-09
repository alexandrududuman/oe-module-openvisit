<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\SettingsSource;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\CleanupService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\TokenService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\FakeGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryAttemptStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemorySessionStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryTokenStore;
use PHPUnit\Framework\TestCase;

final class CleanupServiceTest extends TestCase
{
    public function testClosesAbandonedVisitsAndPurgesOldRows(): void
    {
        $gateway = new FakeGateway();
        $gateway->clock = new \DateTimeImmutable('2026-11-09 18:00:00');
        $gateway->appointments[1] = new Appointment(1, 7, 6, 11, '2026-11-09', '09:00:00', 900, 'A', 'B', 'D', 'L');
        $gateway->appointments[2] = new Appointment(2, 8, 6, 11, '2026-11-09', '17:30:00', 900, 'C', 'D', 'D', 'L');
        $sessions = new InMemorySessionStore();
        $tokens = new InMemoryTokenStore();
        $attempts = new InMemoryAttemptStore();
        $source = new class implements SettingsSource {
            public function get(string $key): ?string
            {
                return null;
            }
        };
        $config = new ModuleConfig($source, static fn(string $v): string => $v);
        $tokenService = new TokenService($tokens, $sessions, $attempts, $gateway, $config);

        $forgotten = $sessions->create(1, 6, 'ov-old');
        $sessions->markStarted($forgotten->id, 500, '2026-11-09 09:00:00');
        $ongoing = $sessions->create(2, 6, 'ov-now');
        $sessions->markStarted($ongoing->id, 501, '2026-11-09 17:35:00');
        $tokens->create($forgotten->id, 'patient', 'h1', null, '2026-10-01 00:00:00', 20, 6);
        $tokens->create($ongoing->id, 'patient', 'h2', null, '2026-11-09 20:00:00', 20, 6);
        $attempts->record('x', '2026-11-07 00:00:00');
        $attempts->record('y', '2026-11-09 17:00:00');

        $result = (new CleanupService($sessions, $tokens, $attempts, $tokenService, $gateway))->run();

        self::assertSame(['closedVisits' => 1, 'purgedTokens' => 1, 'purgedAttempts' => 1], $result);
        self::assertSame(Visit::STATUS_ENDED, $sessions->findById($forgotten->id)?->status);
        self::assertSame(Visit::STATUS_ACTIVE, $sessions->findById($ongoing->id)?->status);
        self::assertSame(['2026-11-09 18:00:00'], array_values($gateway->closedEncounters));
        self::assertCount(1, $tokens->rows);
    }
}
