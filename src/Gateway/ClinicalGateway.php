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

    /** @return list<Appointment> newest first, within the last N days, one category */
    public function appointmentsSince(string $sinceDate, int $categoryId): array;

    /** Books an appointment right now for an unscheduled visit. Returns its id. */
    public function createAppointment(int $pid, int $providerId, int $categoryId, \DateTimeImmutable $start, int $durationMinutes): int;

    /** @return list<array{pid: int, name: string, dob: string}> */
    public function searchPatients(string $query, int $limit = 10): array;

    /** Returns the new encounter number, or null when core refused. */
    public function createEncounter(int $eid): ?int;

    /** Core stamps new encounters at midnight; the visit sets the real start time. */
    public function openEncounter(int $encounter, string $startedAt): void;

    public function closeEncounter(int $encounter, string $endedAt): void;

    public function telehealthCategoryId(): ?int;

    /** @return array{first: string, last: string} */
    public function userName(int $userId): array;

    public function audit(string $event, string $comment, ?int $pid): void;

    /** Absolute URL of the OpenEMR web root, used to build links patients receive. */
    public function publicBaseUrl(): string;

    public function siteId(): string;

    /** @return array{email: string, firstName: string, lastName: string} */
    public function patientContact(int $pid): array;

    /** Sends through OpenEMR's configured mailer. Returns false when mail is not set up or sending failed. */
    public function sendEmail(string $to, string $toName, string $subject, string $html, string $text): bool;

    public function now(): \DateTimeImmutable;
}
