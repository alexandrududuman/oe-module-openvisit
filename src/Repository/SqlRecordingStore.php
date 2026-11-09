<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Recording;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;

final class SqlRecordingStore implements RecordingStore
{
    private const COLUMNS = 'id, session_id, status, started_by, started_at, stopped_at, file_path, file_size, duration_seconds, created_at';

    public function create(int $sessionId, int $startedBy, string $createdAt): Recording
    {
        $id = QueryUtils::sqlInsert(
            'INSERT INTO openvisit_recording (session_id, status, started_by, created_at) VALUES (?, ?, ?, ?)',
            [$sessionId, Recording::STATUS_STARTING, $startedBy, $createdAt],
        );
        return $this->findById(Scalars::int($id)) ?? throw new \RuntimeException('Recording row vanished after insert');
    }

    public function findById(int $id): ?Recording
    {
        $rows = QueryUtils::fetchRecords('SELECT ' . self::COLUMNS . ' FROM openvisit_recording WHERE id = ?', [$id]);
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findBySession(int $sessionId): array
    {
        $rows = QueryUtils::fetchRecords('SELECT ' . self::COLUMNS . ' FROM openvisit_recording WHERE session_id = ? ORDER BY id', [$sessionId]);
        return array_values(array_map(fn(array $row): Recording => $this->hydrate($row), $rows));
    }

    public function findBySessions(array $sessionIds): array
    {
        $ids = array_values(array_filter($sessionIds, static fn(int $v): bool => $v > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = QueryUtils::fetchRecords('SELECT ' . self::COLUMNS . " FROM openvisit_recording WHERE session_id IN ($placeholders) ORDER BY id", $ids);
        $bySession = [];
        foreach ($rows as $row) {
            $recording = $this->hydrate($row);
            $bySession[$recording->sessionId][] = $recording;
        }
        return $bySession;
    }

    public function markRecording(int $id, string $startedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE openvisit_recording SET status = ?, started_at = ? WHERE id = ? AND status = ?',
            [Recording::STATUS_RECORDING, $startedAt, $id, Recording::STATUS_STARTING],
        );
    }

    public function markProcessing(int $id, string $stoppedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE openvisit_recording SET status = ?, stopped_at = ? WHERE id = ? AND status IN (?, ?)',
            [Recording::STATUS_PROCESSING, $stoppedAt, $id, Recording::STATUS_STARTING, Recording::STATUS_RECORDING],
        );
    }

    public function markFailed(int $id): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE openvisit_recording SET status = ? WHERE id = ? AND status <> ?',
            [Recording::STATUS_FAILED, $id, Recording::STATUS_READY],
        );
    }

    public function markReady(int $id, string $filePath, int $fileSize, ?int $durationSeconds, string $stoppedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE openvisit_recording
                SET status = ?, file_path = ?, file_size = ?, duration_seconds = ?, stopped_at = COALESCE(stopped_at, ?)
              WHERE id = ?',
            [Recording::STATUS_READY, $filePath, $fileSize, $durationSeconds, $stoppedAt, $id],
        );
    }

    public function failStale(string $startingBefore, string $waitingBefore): int
    {
        $stale = QueryUtils::fetchRecords(
            'SELECT id FROM openvisit_recording
              WHERE (status = ? AND created_at < ?) OR (status IN (?, ?) AND created_at < ?)',
            [Recording::STATUS_STARTING, $startingBefore, Recording::STATUS_RECORDING, Recording::STATUS_PROCESSING, $waitingBefore],
        );
        foreach ($stale as $row) {
            $this->markFailed(Scalars::int(Scalars::stringKeyed($row)['id'] ?? null));
        }
        return count($stale);
    }

    /** @param array<mixed> $row */
    private function hydrate(array $row): Recording
    {
        $row = Scalars::stringKeyed($row);
        return new Recording(
            Scalars::int($row['id'] ?? null),
            Scalars::int($row['session_id'] ?? null),
            Scalars::string($row['status'] ?? null),
            Scalars::int($row['started_by'] ?? null),
            Scalars::nullableString($row['started_at'] ?? null),
            Scalars::nullableString($row['stopped_at'] ?? null),
            Scalars::nullableString($row['file_path'] ?? null),
            Scalars::nullableInt($row['file_size'] ?? null),
            Scalars::nullableInt($row['duration_seconds'] ?? null),
            Scalars::string($row['created_at'] ?? null),
        );
    }
}
