<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes;

use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;

final class FakeGateway implements ClinicalGateway
{
    /** @var array<int, Appointment> */
    public array $appointments = [];
    public int $nextEncounter = 500;
    /** @var list<int> */
    public array $createdEncountersFor = [];
    /** @var array<int, string> encounter => started at */
    public array $openedEncounters = [];
    /** @var array<int, string> encounter => ended at */
    public array $closedEncounters = [];
    /** @var list<array{0: string, 1: string, 2: ?int}> */
    public array $audits = [];
    public \DateTimeImmutable $clock;

    public function __construct()
    {
        $this->clock = new \DateTimeImmutable('2026-11-09 10:00:00');
    }

    public function appointment(int $eid): ?Appointment
    {
        return $this->appointments[$eid] ?? null;
    }

    public function appointmentsOn(string $date, int $categoryId): array
    {
        return array_values(array_filter(
            $this->appointments,
            static fn(Appointment $a): bool => $a->date === $date && $a->categoryId === $categoryId,
        ));
    }

    /** @var list<array{pid: int, providerId: int, categoryId: int, start: string, minutes: int}> */
    public array $booked = [];

    public function appointmentsSince(string $sinceDate, int $categoryId): array
    {
        return array_values(array_filter($this->appointments, static fn(Appointment $a): bool => $a->date >= $sinceDate && $a->categoryId === $categoryId));
    }

    public function createAppointment(int $pid, int $providerId, int $categoryId, \DateTimeImmutable $start, int $durationMinutes): int
    {
        $this->booked[] = ['pid' => $pid, 'providerId' => $providerId, 'categoryId' => $categoryId, 'start' => $start->format('Y-m-d H:i:s'), 'minutes' => $durationMinutes];
        $eid = 900 + count($this->booked);
        $this->appointments[$eid] = new Appointment($eid, $pid, $providerId, $categoryId, $start->format('Y-m-d'), $start->format('H:i:s'), $durationMinutes * 60, 'Ad', 'Hoc', 'Donna', 'Lee');
        return $eid;
    }

    public function searchPatients(string $query, int $limit = 10): array
    {
        return $query === '' ? [] : [['pid' => 7, 'name' => 'Phil Belford', 'dob' => '1960-01-01']];
    }

    public function createEncounter(int $eid): ?int
    {
        $this->createdEncountersFor[] = $eid;
        return $this->nextEncounter++;
    }

    public function openEncounter(int $encounter, string $startedAt): void
    {
        $this->openedEncounters[$encounter] = $startedAt;
    }

    public function closeEncounter(int $encounter, string $endedAt): void
    {
        $this->closedEncounters[$encounter] = $endedAt;
    }

    public function telehealthCategoryId(): ?int
    {
        return 11;
    }

    public bool $supervisor = false;
    public bool $mayDemo = true;

    public function canSuperviseVisits(): bool
    {
        return $this->supervisor;
    }

    public function canSearchPatients(): bool
    {
        return $this->mayDemo;
    }

    public function userName(int $userId): array
    {
        return ['first' => 'Donna', 'last' => 'Lee'];
    }

    public function audit(string $event, string $comment, ?int $pid): void
    {
        $this->audits[] = [$event, $comment, $pid];
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock;
    }

    public string $email = 'phil@example.test';
    public bool $mailConfigured = true;
    /** @var list<array{to: string, subject: string}> */
    public array $sent = [];

    public string $baseUrl = 'https://emr.example.test/openemr';

    public function publicBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function siteId(): string
    {
        return 'default';
    }

    public function patientContact(int $pid): array
    {
        return ['email' => $this->email, 'firstName' => 'Phil', 'lastName' => 'Belford'];
    }

    public function sendEmail(string $to, string $toName, string $subject, string $html, string $text): bool
    {
        if (!$this->mailConfigured) {
            return false;
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject];
        return true;
    }
}
