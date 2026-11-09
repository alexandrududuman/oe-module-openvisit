<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;

interface SessionStore
{
    public function findById(int $id): ?Visit;

    public function findByAppointment(int $eid): ?Visit;

    public function create(int $eid, int $providerId, string $roomName): Visit;

    /** Marks the visit active. Keeps the first start time on a rejoin. */
    public function markStarted(int $id, ?int $encounter, string $startedAt): void;

    public function markEnded(int $id, string $endedAt): void;

    /**
     * @param list<int> $eids
     * @return array<int, Visit> keyed by appointment id
     */
    public function findByAppointments(array $eids): array;

    /** @return list<Visit> visits that are still marked active */
    public function findActive(): array;
}
