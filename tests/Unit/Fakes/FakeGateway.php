<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes;

use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;

final class FakeGateway implements ClinicalGateway
{
    /** @var array<int, Appointment> */
    public array $appointments = [];
    public int $nextEncounter = 500;
    /** @var list<int> */
    public array $createdEncountersFor = [];
    /** @var array<int, string> encounter => started at */
    public array $openedEncounters = [];
    /** @var array<int, string> encounter => ended at */
    public array $closedEncounters = [];
    /** @var list<array{0: string, 1: string, 2: ?int}> */
    public array $audits = [];
    public \DateTimeImmutable $clock;

    public function __construct()
    {
        $this->clock = new \DateTimeImmutable('2026-11-09 10:00:00');
    }

    public function appointment(int $eid): ?Appointment
    {
        return $this->appointments[$eid] ?? null;
    }

    public function appointmentsOn(string $date, int $categoryId): array
    {
        return array_values(array_filter(
            $this->appointments,
            static fn(Appointment $a): bool => $a->date === $date && $a->categoryId === $categoryId,
        ));
    }

    public function createEncounter(int $eid): ?int
    {
        $this->createdEncountersFor[] = $eid;
        return $this->nextEncounter++;
    }

    public function openEncounter(int $encounter, string $startedAt): void
    {
        $this->openedEncounters[$encounter] = $startedAt;
    }

    public function closeEncounter(int $encounter, string $endedAt): void
    {
        $this->closedEncounters[$encounter] = $endedAt;
    }

    public function telehealthCategoryId(): ?int
    {
        return 11;
    }

    public function userName(int $userId): array
    {
        return ['first' => 'Donna', 'last' => 'Lee'];
    }

    public function audit(string $event, string $comment, ?int $pid): void
    {
        $this->audits[] = [$event, $comment, $pid];
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock;
    }
}
