<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Participant;

interface ParticipantStore
{
    public function joined(int $sessionId, string $role, string $displayName, ?int $tokenId, string $userAgent, string $joinedAt): Participant;

    public function left(int $participantId, string $leftAt): void;

    /** Stamps every row of the session that is still open. */
    public function closeOpen(int $sessionId, string $leftAt): void;

    /** @return list<Participant> oldest first */
    public function findBySession(int $sessionId): array;

    /**
     * @param list<int> $sessionIds
     * @return array<int, int> session id => number of participants who joined
     */
    public function countBySessions(array $sessionIds): array;
}
