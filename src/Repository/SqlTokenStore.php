<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinToken;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;

final class SqlTokenStore implements TokenStore
{
    private const COLUMNS = 'id, session_id, role, label, expires_at, revoked_at, uses, max_uses, created_by, created_at';

    public function create(int $sessionId, string $role, string $hash, ?string $label, string $expiresAt, int $maxUses, int $createdBy): JoinToken
    {
        $id = QueryUtils::sqlInsert(
            'INSERT INTO openvisit_token (session_id, role, token_hash, label, expires_at, max_uses, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$sessionId, $role, $hash, $label, $expiresAt, $maxUses, $createdBy],
        );
        return $this->findById(Scalars::int($id)) ?? throw new \RuntimeException('Token row vanished after insert');
    }

    public function findByHash(string $hash): ?JoinToken
    {
        $rows = QueryUtils::fetchRecords('SELECT ' . self::COLUMNS . ' FROM openvisit_token WHERE token_hash = ?', [$hash], true);
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findById(int $id): ?JoinToken
    {
        $rows = QueryUtils::fetchRecords('SELECT ' . self::COLUMNS . ' FROM openvisit_token WHERE id = ?', [$id]);
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findBySession(int $sessionId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT ' . self::COLUMNS . ' FROM openvisit_token WHERE session_id = ? ORDER BY id DESC',
            [$sessionId],
        );
        return array_values(array_map(fn(array $row): JoinToken => $this->hydrate($row), $rows));
    }

    public function revoke(int $id, string $revokedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE openvisit_token SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [$revokedAt, $id],
        );
    }

    public function recordUse(int $id): void
    {
        QueryUtils::sqlStatementThrowException('UPDATE openvisit_token SET uses = uses + 1 WHERE id = ?', [$id]);
    }

    public function purgeExpiredBefore(string $before): int
    {
        QueryUtils::sqlStatementThrowException('DELETE FROM openvisit_token WHERE expires_at < ?', [$before]);
        return Scalars::int(QueryUtils::affectedRows());
    }

    /** @param array<mixed> $row */
    private function hydrate(array $row): JoinToken
    {
        $row = Scalars::stringKeyed($row);
        return new JoinToken(
            Scalars::int($row['id'] ?? null),
            Scalars::int($row['session_id'] ?? null),
            Scalars::string($row['role'] ?? null),
            Scalars::nullableString($row['label'] ?? null),
            Scalars::string($row['expires_at'] ?? null),
            Scalars::nullableString($row['revoked_at'] ?? null),
            Scalars::int($row['uses'] ?? null),
            Scalars::int($row['max_uses'] ?? null),
            Scalars::int($row['created_by'] ?? null),
            Scalars::string($row['created_at'] ?? null),
        );
    }
}
