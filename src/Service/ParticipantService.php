<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Participant;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\ParticipantStore;

/** Who joined which room and when, reported by the browsers and kept server-side. */
final class ParticipantService
{
    private const DB_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly ParticipantStore $participants,
        private readonly ClinicalGateway $gateway,
    ) {}

    public function joined(int $sessionId, string $role, string $displayName, ?int $tokenId, string $userAgent): Participant
    {
        $participant = $this->participants->joined(
            $sessionId,
            $role,
            $displayName,
            $tokenId,
            $userAgent,
            $this->gateway->now()->format(self::DB_FORMAT),
        );
        $this->gateway->audit('openvisit-join', sprintf('%s joined session %d', $role, $sessionId), null);
        return $participant;
    }

    public function left(int $participantId, int $sessionId): void
    {
        foreach ($this->participants->findBySession($sessionId) as $participant) {
            if ($participant->id === $participantId) {
                $this->participants->left($participantId, $this->gateway->now()->format(self::DB_FORMAT));
                return;
            }
        }
    }

    /** Once a visit is over nobody is in it, whether or not their browser said goodbye. */
    public function closeAll(int $sessionId): void
    {
        $this->participants->closeOpen($sessionId, $this->gateway->now()->format(self::DB_FORMAT));
    }

    /** The participant row this link opened and has not closed yet, if any. */
    public function openForToken(int $sessionId, int $tokenId): ?Participant
    {
        foreach ($this->participants->findBySession($sessionId) as $participant) {
            if ($participant->tokenId === $tokenId && $participant->leftAt === null) {
                return $participant;
            }
        }
        return null;
    }

    /** A link may only close the row it opened. */
    public function leftOwn(int $participantId, int $sessionId, int $tokenId): void
    {
        foreach ($this->participants->findBySession($sessionId) as $participant) {
            if ($participant->id === $participantId && $participant->tokenId === $tokenId) {
                $this->participants->left($participantId, $this->gateway->now()->format(self::DB_FORMAT));
                return;
            }
        }
    }

    /** @return list<Participant> */
    public function forSession(int $sessionId): array
    {
        return $this->participants->findBySession($sessionId);
    }

    /**
     * @param list<int> $sessionIds
     * @return array<int, int>
     */
    public function counts(array $sessionIds): array
    {
        return $this->participants->countBySessions($sessionIds);
    }
}
