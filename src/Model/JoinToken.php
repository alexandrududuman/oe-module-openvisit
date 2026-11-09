<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Model;

/** One row of openvisit_token. The plaintext token is never stored, only its hash. */
final readonly class JoinToken
{
    public const ROLE_PATIENT = 'patient';
    public const ROLE_GUEST = 'guest';

    public function __construct(
        public int $id,
        public int $sessionId,
        public string $role,
        public ?string $label,
        public string $expiresAt,
        public ?string $revokedAt,
        public int $uses,
        public int $maxUses,
        public int $createdBy,
        public string $createdAt,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return new \DateTimeImmutable($this->expiresAt) <= $now;
    }

    public function isUsedUp(): bool
    {
        return $this->uses >= $this->maxUses;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now) && !$this->isUsedUp();
    }
}
