<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Recording;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\RecordingStore;

final class InMemoryRecordingStore implements RecordingStore
{
    /** @var array<int, Recording> */
    public array $rows = [];
    private int $nextId = 1;

    public function create(int $sessionId, int $startedBy, string $createdAt): Recording
    {
        $r = new Recording($this->nextId++, $sessionId, Recording::STATUS_STARTING, $startedBy, null, null, null, null, null, $createdAt);
        $this->rows[$r->id] = $r;
        return $r;
    }

    public function findById(int $id): ?Recording
    {
        return $this->rows[$id] ?? null;
    }

    public function findBySession(int $sessionId): array
    {
        return array_values(array_filter($this->rows, static fn(Recording $r): bool => $r->sessionId === $sessionId));
    }

    public function findBySessions(array $sessionIds): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if (in_array($r->sessionId, $sessionIds, true)) {
                $out[$r->sessionId][] = $r;
            }
        }
        return $out;
    }

    public function markRecording(int $id, string $startedAt): void
    {
        $this->replace($id, status: Recording::STATUS_RECORDING, startedAt: $startedAt);
    }

    public function markProcessing(int $id, string $stoppedAt): void
    {
        $this->replace($id, status: Recording::STATUS_PROCESSING, stoppedAt: $stoppedAt);
    }

    public function markFailed(int $id): void
    {
        $this->replace($id, status: Recording::STATUS_FAILED);
    }

    public function markReady(int $id, string $filePath, int $fileSize, ?int $durationSeconds, string $stoppedAt): void
    {
        $r = $this->rows[$id];
        $this->rows[$id] = new Recording($r->id, $r->sessionId, Recording::STATUS_READY, $r->startedBy, $r->startedAt, $r->stoppedAt ?? $stoppedAt, $filePath, $fileSize, $durationSeconds, $r->createdAt);
    }

    public function failStale(string $startingBefore, string $waitingBefore): int
    {
        $failed = 0;
        foreach ($this->rows as $r) {
            $stale = ($r->status === Recording::STATUS_STARTING && $r->createdAt < $startingBefore)
                || (in_array($r->status, [Recording::STATUS_RECORDING, Recording::STATUS_PROCESSING], true) && $r->createdAt < $waitingBefore);
            if ($stale) {
                $this->markFailed($r->id);
                $failed++;
            }
        }
        return $failed;
    }

    private function replace(int $id, ?string $status = null, ?string $startedAt = null, ?string $stoppedAt = null): void
    {
        $r = $this->rows[$id];
        $this->rows[$id] = new Recording($r->id, $r->sessionId, $status ?? $r->status, $r->startedBy, $startedAt ?? $r->startedAt, $stoppedAt ?? $r->stoppedAt, $r->filePath, $r->fileSize, $r->durationSeconds, $r->createdAt);
    }
}
