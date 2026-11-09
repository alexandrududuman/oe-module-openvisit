<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\SessionStore;

final class InMemorySessionStore implements SessionStore
{
    /** @var array<int, Visit> */
    public array $rows = [];
    private int $nextId = 1;

    public function findById(int $id): ?Visit
    {
        return $this->rows[$id] ?? null;
    }

    public function findByAppointment(int $eid): ?Visit
    {
        foreach ($this->rows as $visit) {
            if ($visit->eid === $eid) {
                return $visit;
            }
        }
        return null;
    }

    public function create(int $eid, int $providerId, string $roomName): Visit
    {
        $visit = new Visit($this->nextId++, $eid, null, $roomName, $providerId, Visit::STATUS_SCHEDULED, null, null);
        $this->rows[$visit->id] = $visit;
        return $visit;
    }

    public function markStarted(int $id, ?int $encounter, string $startedAt): void
    {
        $v = $this->rows[$id];
        $this->rows[$id] = new Visit($v->id, $v->eid, $v->encounter ?? $encounter, $v->roomName, $v->providerId, Visit::STATUS_ACTIVE, $v->startedAt ?? $startedAt, null);
    }

    public function markEnded(int $id, string $endedAt): void
    {
        $v = $this->rows[$id];
        $this->rows[$id] = new Visit($v->id, $v->eid, $v->encounter, $v->roomName, $v->providerId, Visit::STATUS_ENDED, $v->startedAt, $endedAt);
    }

    public function findActive(): array
    {
        return array_values(array_filter($this->rows, static fn(Visit $v): bool => $v->status === Visit::STATUS_ACTIVE));
    }

    /** @param list<int> $eids */
    public function findByAppointments(array $eids): array
    {
        $out = [];
        foreach ($this->rows as $visit) {
            if (in_array($visit->eid, $eids, true)) {
                $out[$visit->eid] = $visit;
            }
        }
        return $out;
    }
}
