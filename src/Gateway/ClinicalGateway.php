<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Gateway;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;

/** Everything the visit flow needs from OpenEMR core, behind one seam so the flow is unit-testable. */
interface ClinicalGateway
{
    public function appointment(int $eid): ?Appointment;

    /** @return array<int, Appointment> appointments of one day in one category, ordered by time */
    public function appointmentsOn(string $date, int $categoryId): array;

    /** Returns the new encounter number, or null when core refused. */
    public function createEncounter(int $eid): ?int;

    /** Core stamps new encounters at midnight; the visit sets the real start time. */
    public function openEncounter(int $encounter, string $startedAt): void;

    public function closeEncounter(int $encounter, string $endedAt): void;

    public function telehealthCategoryId(): ?int;

    /** @return array{first: string, last: string} */
    public function userName(int $userId): array;

    public function audit(string $event, string $comment, ?int $pid): void;

    public function now(): \DateTimeImmutable;
}
