<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Gateway;

use MyMailer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\AppointmentService;
use OpenEMR\Services\UserService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;

final class OpenEmrGateway implements ClinicalGateway
{
    public const CATEGORY_CONSTANT_ID = 'openvisit_telehealth';

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly AppointmentService $appointments = new AppointmentService(),
        private readonly UserService $users = new UserService(),
    ) {}

    public function appointment(int $eid): ?Appointment
    {
        $rows = $this->appointments->getAppointment($eid);
        $first = $rows[0] ?? null;
        return is_array($first) ? $this->hydrate($first) : null;
    }

    public function appointmentsOn(string $date, int $categoryId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT pce.pc_eid, pce.pc_pid, pce.pc_aid, pce.pc_catid, pce.pc_eventDate, pce.pc_startTime, pce.pc_duration,
                    pd.fname, pd.lname, u.fname AS pce_aid_fname, u.lname AS pce_aid_lname
               FROM openemr_postcalendar_events pce
               LEFT JOIN patient_data pd ON pd.pid = pce.pc_pid
               LEFT JOIN users u ON u.id = pce.pc_aid
              WHERE pce.pc_eventDate = ? AND pce.pc_catid = ? AND pce.pc_pid > 0
              ORDER BY pce.pc_startTime',
            [$date, $categoryId],
        );
        return array_map(fn(array $row): Appointment => $this->hydrate($row), $rows);
    }

    public function appointmentsSince(string $sinceDate, int $categoryId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT pce.pc_eid, pce.pc_pid, pce.pc_aid, pce.pc_catid, pce.pc_eventDate, pce.pc_startTime, pce.pc_duration,
                    pd.fname, pd.lname, u.fname AS pce_aid_fname, u.lname AS pce_aid_lname
               FROM openemr_postcalendar_events pce
               LEFT JOIN patient_data pd ON pd.pid = pce.pc_pid
               LEFT JOIN users u ON u.id = pce.pc_aid
              WHERE pce.pc_eventDate >= ? AND pce.pc_catid = ? AND pce.pc_pid > 0
              ORDER BY pce.pc_eventDate DESC, pce.pc_startTime DESC LIMIT 500',
            [$sinceDate, $categoryId],
        );
        return array_values(array_map(fn(array $row): Appointment => $this->hydrate($row), $rows));
    }

    public function createAppointment(int $pid, int $providerId, int $categoryId, \DateTimeImmutable $start, int $durationMinutes): int
    {
        $facility = Scalars::int(QueryUtils::fetchSingleValue('SELECT facility_id FROM users WHERE id = ?', 'facility_id', [$providerId]));
        if ($facility <= 0) {
            $facility = Scalars::int(QueryUtils::fetchSingleValue('SELECT id FROM facility ORDER BY primary_business_entity DESC, id LIMIT 1', 'id', []));
        }
        $id = $this->appointments->insert($pid, [
            'pc_catid' => $categoryId,
            'pc_title' => xl('Telehealth Visit'),
            'pc_duration' => max(1, $durationMinutes) * 60,
            'pc_hometext' => xl('Started without a prior appointment'),
            'pc_eventDate' => $start->format('Y-m-d'),
            'pc_apptstatus' => '-',
            'pc_startTime' => $start->format('H:i:s'),
            'pc_facility' => $facility,
            'pc_billing_location' => $facility,
            'pc_informant' => '',
            'pc_aid' => $providerId,
            'pc_website' => '',
        ]);
        return Scalars::int($id);
    }

    public function searchPatients(string $query, int $limit = 10): array
    {
        $term = trim($query);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = addcslashes($term, '\\%_') . '%';
        $rows = QueryUtils::fetchRecords(
            "SELECT pid, fname, lname, DOB FROM patient_data
              WHERE lname LIKE ? ESCAPE '\\\\' OR fname LIKE ? ESCAPE '\\\\' OR pid = ?
              ORDER BY lname, fname LIMIT " . max(1, min($limit, 25)),
            [$like, $like, ctype_digit($term) ? (int) $term : 0],
        );
        $out = [];
        foreach ($rows as $row) {
            $row = Scalars::stringKeyed($row);
            $out[] = [
                'pid' => Scalars::int($row['pid'] ?? null),
                'name' => trim(Scalars::string($row['fname'] ?? null) . ' ' . Scalars::string($row['lname'] ?? null)),
                'dob' => substr(Scalars::string($row['DOB'] ?? null), 0, 4),
            ];
        }
        return $out;
    }

    public function createEncounter(int $eid): ?int
    {
        return Scalars::nullableInt($this->appointments->createEncounterForAppointment($eid));
    }

    public function openEncounter(int $encounter, string $startedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE form_encounter SET date = ? WHERE encounter = ?',
            [$startedAt, $encounter],
        );
    }

    public function closeEncounter(int $encounter, string $endedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE form_encounter SET date_end = ? WHERE encounter = ?',
            [$endedAt, $encounter],
        );
    }

    public function telehealthCategoryId(): ?int
    {
        $configured = $this->config->calendarCategoryId();
        if ($configured !== null) {
            return $configured;
        }
        $id = QueryUtils::fetchSingleValue(
            'SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_constant_id = ?',
            'pc_catid',
            [self::CATEGORY_CONSTANT_ID],
        );
        return is_numeric($id) ? (int) $id : null;
    }

    public function canSuperviseVisits(): bool
    {
        return AclMain::aclCheckCore('admin', 'super');
    }

    public function canSearchPatients(): bool
    {
        return AclMain::aclCheckCore('patients', 'demo');
    }

    public function userName(int $userId): array
    {
        $user = Scalars::stringKeyed($this->users->getUser($userId));
        return ['first' => Scalars::string($user['fname'] ?? null), 'last' => Scalars::string($user['lname'] ?? null)];
    }

    public function audit(string $event, string $comment, ?int $pid): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        EventAuditLogger::getInstance()->newEvent(
            $event,
            Scalars::string($session->get('authUser')),
            Scalars::string($session->get('authProvider')),
            1,
            $comment,
            $pid,
        );
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    public function publicBaseUrl(): string
    {
        $bag = OEGlobalsBag::getInstance();
        $base = rtrim($bag->getString('site_addr_oath'), '/');
        return $base === '' ? '' : $base . $bag->getWebRoot();
    }

    public function siteId(): string
    {
        $site = Scalars::string(SessionWrapperFactory::getInstance()->getActiveSession()->get('site_id'));
        return $site === '' ? 'default' : $site;
    }

    public function patientContact(int $pid): array
    {
        $row = Scalars::stringKeyed(QueryUtils::querySingleRow(
            'SELECT email, fname, lname FROM patient_data WHERE pid = ?',
            [$pid],
        ) ?? []);
        return [
            'email' => trim(Scalars::string($row['email'] ?? null)),
            'firstName' => Scalars::string($row['fname'] ?? null),
            'lastName' => Scalars::string($row['lname'] ?? null),
        ];
    }

    public function sendEmail(string $to, string $toName, string $subject, string $html, string $text): bool
    {
        if (!MyMailer::isConfigured()) {
            return false;
        }
        $bag = OEGlobalsBag::getInstance();
        $sender = $bag->getString('patient_reminder_sender_email');
        if ($sender === '') {
            $sender = $bag->getString('practice_return_email_path');
        }
        $mail = new MyMailer();
        $mail->SetFrom($sender, $bag->getString('Patient_Reminder_Sender_Name'));
        $mail->AddReplyTo($sender, $sender);
        $mail->AddAddress($to, $toName);
        $mail->Subject = $subject;
        $mail->MsgHTML($html);
        $mail->AltBody = $text;
        $mail->IsHTML(true);
        return (bool) $mail->Send();
    }

    /** @param array<mixed> $row */
    private function hydrate(array $row): Appointment
    {
        $row = Scalars::stringKeyed($row);
        return new Appointment(
            Scalars::int($row['pc_eid'] ?? null),
            Scalars::int($row['pc_pid'] ?? $row['pid'] ?? null),
            Scalars::int($row['pc_aid'] ?? null),
            Scalars::int($row['pc_catid'] ?? null),
            Scalars::string($row['pc_eventDate'] ?? null),
            Scalars::string($row['pc_startTime'] ?? null, '00:00:00'),
            Scalars::int($row['pc_duration'] ?? null),
            Scalars::string($row['fname'] ?? null),
            Scalars::string($row['lname'] ?? null),
            Scalars::string($row['pce_aid_fname'] ?? null),
            Scalars::string($row['pce_aid_lname'] ?? null),
        );
    }
}
