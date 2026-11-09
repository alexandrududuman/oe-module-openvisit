<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinToken;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\TokenStore;

final class InMemoryTokenStore implements TokenStore
{
    /** @var array<int, JoinToken> */
    public array $rows = [];
    /** @var array<int, string> id => hash */
    public array $hashes = [];
    private int $nextId = 1;

    public function create(int $sessionId, string $role, string $hash, ?string $label, string $expiresAt, int $maxUses, int $createdBy): JoinToken
    {
        $token = new JoinToken($this->nextId++, $sessionId, $role, $label, $expiresAt, null, 0, $maxUses, $createdBy, '2026-11-09 09:00:00');
        $this->rows[$token->id] = $token;
        $this->hashes[$token->id] = $hash;
        return $token;
    }

    public function findByHash(string $hash): ?JoinToken
    {
        $id = array_search($hash, $this->hashes, true);
        return $id === false ? null : $this->rows[$id];
    }

    public function findById(int $id): ?JoinToken
    {
        return $this->rows[$id] ?? null;
    }

    public function findBySession(int $sessionId): array
    {
        $out = array_values(array_filter($this->rows, static fn(JoinToken $t): bool => $t->sessionId === $sessionId));
        return array_reverse($out);
    }

    public function revoke(int $id, string $revokedAt): void
    {
        $t = $this->rows[$id];
        $this->rows[$id] = new JoinToken($t->id, $t->sessionId, $t->role, $t->label, $t->expiresAt, $t->revokedAt ?? $revokedAt, $t->uses, $t->maxUses, $t->createdBy, $t->createdAt);
    }

    public function recordUse(int $id): bool
    {
        $t = $this->rows[$id];
        if ($t->uses >= $t->maxUses || $t->revokedAt !== null) {
            return false;
        }
        $this->rows[$id] = new JoinToken($t->id, $t->sessionId, $t->role, $t->label, $t->expiresAt, $t->revokedAt, $t->uses + 1, $t->maxUses, $t->createdBy, $t->createdAt);
        return true;
    }

    public function purgeExpiredBefore(string $before): int
    {
        $n = 0;
        foreach ($this->rows as $id => $t) {
            if ($t->expiresAt < $before) {
                unset($this->rows[$id], $this->hashes[$id]);
                $n++;
            }
        }
        return $n;
    }
}
