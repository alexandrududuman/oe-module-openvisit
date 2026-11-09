<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Participant;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\ParticipantStore;

final class InMemoryParticipantStore implements ParticipantStore
{
    /** @var array<int, Participant> */
    public array $rows = [];
    private int $nextId = 1;

    public function joined(int $sessionId, string $role, string $displayName, ?int $tokenId, string $userAgent, string $joinedAt): Participant
    {
        $p = new Participant($this->nextId++, $sessionId, $role, $displayName, $joinedAt, null, $tokenId);
        $this->rows[$p->id] = $p;
        return $p;
    }

    public function left(int $participantId, string $leftAt): void
    {
        $p = $this->rows[$participantId];
        $this->rows[$participantId] = new Participant($p->id, $p->sessionId, $p->role, $p->displayName, $p->joinedAt, $p->leftAt ?? $leftAt, $p->tokenId);
    }

    public function closeOpen(int $sessionId, string $leftAt): void
    {
        foreach ($this->findBySession($sessionId) as $p) {
            $this->left($p->id, $leftAt);
        }
    }

    public function findBySession(int $sessionId): array
    {
        return array_values(array_filter($this->rows, static fn(Participant $p): bool => $p->sessionId === $sessionId));
    }

    public function countBySessions(array $sessionIds): array
    {
        $counts = [];
        foreach ($this->rows as $p) {
            if (in_array($p->sessionId, $sessionIds, true)) {
                $counts[$p->sessionId] = ($counts[$p->sessionId] ?? 0) + 1;
            }
        }
        return $counts;
    }
}
