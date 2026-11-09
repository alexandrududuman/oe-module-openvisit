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

use OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RoomNameGenerator;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\VisitService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\FakeGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class VisitServiceTest extends TestCase
{
    private InMemorySessionStore $store;
    private FakeGateway $gateway;
    private VisitService $service;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionStore();
        $this->gateway = new FakeGateway();
        $this->gateway->appointments[42] = new Appointment(42, 7, 6, 11, '2026-11-09', '10:30:00', 900, 'Phil', 'Belford', 'Donna', 'Lee');
        $this->gateway->appointments[43] = new Appointment(43, 0, 6, 11, '2026-11-09', '11:00:00', 900, '', '', 'Donna', 'Lee');
        $this->service = new VisitService($this->store, $this->gateway, new RoomNameGenerator());
    }

    public function testFirstStartCreatesSessionEncounterAndAudit(): void
    {
        $started = $this->service->startForProvider(42, 6);

        self::assertSame(Visit::STATUS_ACTIVE, $started->visit->status);
        self::assertSame(500, $started->visit->encounter);
        self::assertSame('2026-11-09 10:00:00', $started->visit->startedAt);
        self::assertStringStartsWith(RoomNameGenerator::PREFIX, $started->visit->roomName);
        self::assertSame([42], $this->gateway->createdEncountersFor);
        self::assertSame([500 => '2026-11-09 10:00:00'], $this->gateway->openedEncounters);
        self::assertSame('openvisit-start', $this->gateway->audits[0][0]);
        self::assertSame(7, $this->gateway->audits[0][2]);
        self::assertSame('Phil', $started->appointment->patientFirstName);
    }

    public function testRejoinReusesRoomEncounterAndStartTime(): void
    {
        $first = $this->service->startForProvider(42, 6);
        $this->gateway->clock = $this->gateway->clock->modify('+5 minutes');

        $second = $this->service->startForProvider(42, 6);

        self::assertSame($first->visit->id, $second->visit->id);
        self::assertSame($first->visit->roomName, $second->visit->roomName);
        self::assertSame($first->visit->encounter, $second->visit->encounter);
        self::assertSame($first->visit->startedAt, $second->visit->startedAt);
        self::assertCount(1, $this->gateway->createdEncountersFor);
        self::assertCount(1, $this->gateway->openedEncounters);
    }

    public function testEndStampsSessionAndEncounter(): void
    {
        $started = $this->service->startForProvider(42, 6);
        $this->gateway->clock = $this->gateway->clock->modify('+17 minutes');

        $ended = $this->service->end($started->visit->id);

        self::assertTrue($ended->isEnded());
        self::assertSame('2026-11-09 10:17:00', $ended->endedAt);
        self::assertSame(17, $ended->durationMinutes());
        self::assertSame(['2026-11-09 10:17:00'], array_values($this->gateway->closedEncounters));
        self::assertSame('openvisit-end', $this->gateway->audits[1][0]);
    }

    public function testEndingTwiceChangesNothing(): void
    {
        $started = $this->service->startForProvider(42, 6);
        $first = $this->service->end($started->visit->id);
        $this->gateway->clock = $this->gateway->clock->modify('+1 hour');

        $second = $this->service->end($started->visit->id);

        self::assertSame($first->endedAt, $second->endedAt);
        self::assertCount(1, $this->gateway->closedEncounters);
    }

    public function testStartAfterEndIsRefused(): void
    {
        $started = $this->service->startForProvider(42, 6);
        $this->service->end($started->visit->id);

        $this->expectException(VisitException::class);
        $this->service->startForProvider(42, 6);
    }

    public function testEnsureSessionCreatesOnceWithoutStarting(): void
    {
        $first = $this->service->ensureSession($this->gateway->appointments[42]);
        $second = $this->service->ensureSession($this->gateway->appointments[42]);

        self::assertSame($first->id, $second->id);
        self::assertSame(Visit::STATUS_SCHEDULED, $second->status);
        self::assertSame([], $this->gateway->createdEncountersFor);
    }

    public function testAdhocBooksNowAndCanBeStartedRightAway(): void
    {
        $eid = $this->service->bookAdhoc(7, 6, 11, 15);

        self::assertSame(901, $eid);
        self::assertSame(['pid' => 7, 'providerId' => 6, 'categoryId' => 11, 'start' => '2026-11-09 10:00:00', 'minutes' => 15], $this->gateway->booked[0]);
        self::assertSame('openvisit-adhoc', $this->gateway->audits[0][0]);
        self::assertSame(Visit::STATUS_ACTIVE, $this->service->startForProvider($eid, 6)->visit->status);
    }

    public function testAdhocWithoutPatientIsRejected(): void
    {
        $this->expectException(VisitException::class);
        $this->service->bookAdhoc(0, 6, 11, 15);
    }

    public function testUnknownAppointmentIsRejected(): void
    {
        $this->expectException(VisitException::class);
        $this->service->startForProvider(999, 6);
    }

    public function testAppointmentWithoutPatientIsRejected(): void
    {
        $this->expectException(VisitException::class);
        $this->service->startForProvider(43, 6);
    }

    public function testEndingUnknownVisitIsRejected(): void
    {
        $this->expectException(VisitException::class);
        $this->service->end(12345);
    }
}
