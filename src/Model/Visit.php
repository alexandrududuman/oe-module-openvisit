<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Model;

/** One row of openvisit_session. */
final readonly class Visit
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public int $id,
        public int $eid,
        public ?int $encounter,
        public string $roomName,
        public int $providerId,
        public string $status,
        public ?string $startedAt,
        public ?string $endedAt,
    ) {}

    public function isEnded(): bool
    {
        return $this->status === self::STATUS_ENDED;
    }

    /** Whole minutes between start and end, null while the visit is open. */
    public function durationMinutes(): ?int
    {
        if ($this->startedAt === null || $this->endedAt === null) {
            return null;
        }
        $seconds = strtotime($this->endedAt) - strtotime($this->startedAt);
        return max(0, intdiv($seconds, 60));
    }
}
