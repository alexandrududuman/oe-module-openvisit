<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;

final class SqlSessionStore implements SessionStore
{
    private const COLUMNS = 'id, pc_eid, encounter, room_name, provider_id, status, started_at, ended_at';

    public function findById(int $id): ?Visit
    {
        $rows = QueryUtils::fetchRecords('SELECT ' . self::COLUMNS . ' FROM openvisit_session WHERE id = ?', [$id]);
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findByAppointment(int $eid): ?Visit
    {
        $rows = QueryUtils::fetchRecords('SELECT ' . self::COLUMNS . ' FROM openvisit_session WHERE pc_eid = ?', [$eid]);
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function create(int $eid, int $providerId, string $roomName): Visit
    {
        $id = QueryUtils::sqlInsert(
            'INSERT INTO openvisit_session (pc_eid, room_name, provider_id, status) VALUES (?, ?, ?, ?)',
            [$eid, $roomName, $providerId, Visit::STATUS_SCHEDULED],
        );
        $visit = $this->findById((int) $id);
        if ($visit === null) {
            throw new \RuntimeException('Session row vanished after insert');
        }
        return $visit;
    }

    public function markStarted(int $id, ?int $encounter, string $startedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE openvisit_session
                SET status = ?, encounter = COALESCE(encounter, ?), started_at = COALESCE(started_at, ?), ended_at = NULL
              WHERE id = ?',
            [Visit::STATUS_ACTIVE, $encounter, $startedAt, $id],
        );
    }

    public function markEnded(int $id, string $endedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE openvisit_session SET status = ?, ended_at = ? WHERE id = ?',
            [Visit::STATUS_ENDED, $endedAt, $id],
        );
    }

    /** @param list<int> $eids */
    public function findByAppointments(array $eids): array
    {
        $ids = array_values(array_filter($eids, static fn(int $v): bool => $v > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = QueryUtils::fetchRecords(
            'SELECT ' . self::COLUMNS . " FROM openvisit_session WHERE pc_eid IN ($placeholders)",
            $ids,
        );
        $visits = [];
        foreach ($rows as $row) {
            $visit = $this->hydrate($row);
            $visits[$visit->eid] = $visit;
        }
        return $visits;
    }

    /** @param array<mixed> $row */
    private function hydrate(array $row): Visit
    {
        $row = Scalars::stringKeyed($row);
        return new Visit(
            Scalars::int($row['id'] ?? null),
            Scalars::int($row['pc_eid'] ?? null),
            Scalars::nullableInt($row['encounter'] ?? null),
            Scalars::string($row['room_name'] ?? null),
            Scalars::int($row['provider_id'] ?? null),
            Scalars::string($row['status'] ?? null),
            Scalars::nullableString($row['started_at'] ?? null),
            Scalars::nullableString($row['ended_at'] ?? null),
        );
    }
}
