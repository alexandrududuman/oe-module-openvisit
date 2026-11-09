<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Model;

/** One row of openvisit_participant: somebody joined a room. */
final readonly class Participant
{
    public function __construct(
        public int $id,
        public int $sessionId,
        public string $role,
        public string $displayName,
        public string $joinedAt,
        public ?string $leftAt,
        public ?int $tokenId,
    ) {}
}
