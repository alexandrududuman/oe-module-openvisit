<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinToken;

interface TokenStore
{
    public function create(int $sessionId, string $role, string $hash, ?string $label, string $expiresAt, int $maxUses, int $createdBy): JoinToken;

    public function findByHash(string $hash): ?JoinToken;

    public function findById(int $id): ?JoinToken;

    /** @return list<JoinToken> newest first */
    public function findBySession(int $sessionId): array;

    public function revoke(int $id, string $revokedAt): void;

    /** Increments the use counter only while under the cap and not revoked; returns whether it did. */
    public function recordUse(int $id): bool;

    /** Deletes tokens that expired before the given moment. Returns how many. */
    public function purgeExpiredBefore(string $before): int;
}
