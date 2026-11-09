<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Participant;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;

final class SqlParticipantStore implements ParticipantStore
{
    private const COLUMNS = 'id, session_id, role, display_name, joined_at, left_at, token_id';

    public function joined(int $sessionId, string $role, string $displayName, ?int $tokenId, string $userAgent, string $joinedAt): Participant
    {
        $id = QueryUtils::sqlInsert(
            'INSERT INTO openvisit_participant (session_id, role, display_name, token_id, user_agent, joined_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$sessionId, $role, mb_substr($displayName, 0, 120), $tokenId, mb_substr($userAgent, 0, 255), $joinedAt],
        );
        $rows = QueryUtils::fetchRecords('SELECT ' . self::COLUMNS . ' FROM openvisit_participant WHERE id = ?', [Scalars::int($id)]);
        return $this->hydrate($rows[0] ?? []);
    }

    public function left(int $participantId, string $leftAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE openvisit_participant SET left_at = ? WHERE id = ? AND left_at IS NULL',
            [$leftAt, $participantId],
        );
    }

    public function findBySession(int $sessionId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT ' . self::COLUMNS . ' FROM openvisit_participant WHERE session_id = ? ORDER BY id',
            [$sessionId],
        );
        return array_values(array_map(fn(array $row): Participant => $this->hydrate($row), $rows));
    }

    public function countBySessions(array $sessionIds): array
    {
        $ids = array_values(array_filter($sessionIds, static fn(int $v): bool => $v > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = QueryUtils::fetchRecords(
            "SELECT session_id, COUNT(*) AS n FROM openvisit_participant WHERE session_id IN ($placeholders) GROUP BY session_id",
            $ids,
        );
        $counts = [];
        foreach ($rows as $row) {
            $row = Scalars::stringKeyed($row);
            $counts[Scalars::int($row['session_id'] ?? null)] = Scalars::int($row['n'] ?? null);
        }
        return $counts;
    }

    /** @param array<mixed> $row */
    private function hydrate(array $row): Participant
    {
        $row = Scalars::stringKeyed($row);
        return new Participant(
            Scalars::int($row['id'] ?? null),
            Scalars::int($row['session_id'] ?? null),
            Scalars::string($row['role'] ?? null),
            Scalars::string($row['display_name'] ?? null),
            Scalars::string($row['joined_at'] ?? null),
            Scalars::nullableString($row['left_at'] ?? null),
            Scalars::nullableInt($row['token_id'] ?? null),
        );
    }
}
