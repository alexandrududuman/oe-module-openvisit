<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Controller;

use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Recording;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\SessionStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\InviteService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\JitsiJwt;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\ParticipantService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RecordingService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\TokenService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\VisitService;
use Twig\Environment;

final class VisitController
{
    private const TOKEN_GRACE_HOURS = 2;

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly ClinicalGateway $gateway,
        private readonly VisitService $visits,
        private readonly SessionStore $sessions,
        private readonly InviteService $invites,
        private readonly TokenService $tokens,
        private readonly ParticipantService $participants,
        private readonly RecordingService $recordings,
        private readonly Environment $twig,
    ) {}

    /** The provider's room page. */
    public function room(int $eid, int $userId, string $csrfToken): string
    {
        if (!$this->config->isConfigured()) {
            return $this->error(xl('Video visits are not set up yet. Ask your administrator to fill in the OpenVisit tab under Administration, Globals.'));
        }
        if ($eid <= 0) {
            return $this->error(xl('No appointment was given.'));
        }

        $appointment = $this->gateway->appointment($eid);
        if ($appointment === null || !$this->mayAct($appointment, $userId) || $appointment->categoryId !== $this->gateway->telehealthCategoryId()) {
            return $this->error(xl('This appointment is not a telehealth visit you can run.'));
        }
        try {
            $started = $this->visits->startForProvider($eid, $userId);
        } catch (VisitException $e) {
            return $this->error($e->getMessage());
        }

        $name = $this->gateway->userName($userId);
        $displayName = trim($name['first'] . ' ' . $name['last']);
        if ($displayName === '') {
            $displayName = xl('Provider');
        }
        $now = $this->gateway->now();
        $expires = max($started->appointment->endsAt(), $now)->modify('+' . self::TOKEN_GRACE_HOURS . ' hours');
        $jwt = (new JitsiJwt($this->config->jwtAppId(), $this->config->jwtAppSecret(), $this->config->jitsiDomain()))
            ->mint($started->visit->roomName, $displayName, true, $now->getTimestamp(), $expires->getTimestamp());

        $client = [
            'domain' => $this->config->jitsiDomain(),
            'room' => $started->visit->roomName,
            'jwt' => $jwt,
            'displayName' => $displayName,
            'subject' => xl('Video visit'),
            'sessionId' => $started->visit->id,
            'csrf' => $csrfToken,
            'endUrl' => Bootstrap::MODULE_PATH . '/public/api.php',
            'moderator' => true,
            'emailEnabled' => $this->invites->emailEnabled(),
            'recording' => ['enabled' => $this->recordings->enabled(), 'minPeople' => RecordingService::MIN_PEOPLE],
            'patientName' => trim($started->appointment->patientFirstName . ' ' . $started->appointment->patientLastName),
        ];

        return $this->twig->render('visit.html.twig', [
            'assets' => Bootstrap::assetPath(),
            'patientName' => trim($started->appointment->patientFirstName . ' ' . $started->appointment->patientLastName),
            'startsAt' => $started->appointment->startsAt()->format('Y-m-d H:i'),
            'encounter' => $started->visit->encounter,
            'recordingEnabled' => $this->recordings->enabled(),
            'clientConfig' => json_encode($client, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
    }

    /** @return array{ok: bool, status?: string, durationMinutes?: int|null, error?: string} */
    public function end(int $sessionId, int $userId): array
    {
        if ($this->ownedVisit($sessionId, $userId) === null) {
            return ['ok' => false, 'error' => xl('This visit does not exist.')];
        }
        try {
            $visit = $this->visits->end($sessionId);
        } catch (VisitException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->participants->closeAll($sessionId);
        return ['ok' => true, 'status' => $visit->status, 'durationMinutes' => $visit->durationMinutes()];
    }

    /** @return array<string, mixed> */
    public function invite(string $action, int $sessionId, string $role, ?string $label, int $tokenId, int $userId): array
    {
        $owned = $this->ownedVisit($sessionId, $userId);
        if ($owned === null) {
            return ['ok' => false, 'error' => xl('This visit does not exist.')];
        }
        [$visit, $appointment] = $owned;
        try {
            switch ($action) {
                case 'invite.list':
                    return ['ok' => true, 'links' => $this->invites->list($sessionId), 'emailEnabled' => $this->invites->emailEnabled()];
                case 'invite.create':
                    if (!InviteService::isAssignableRole($role)) {
                        return ['ok' => false, 'error' => 'bad role'];
                    }
                    return ['ok' => true, 'link' => $this->invites->create($visit, $appointment, $role, $label, $userId)];
                case 'invite.revoke':
                    $this->tokens->revoke($tokenId, $sessionId, $userId);
                    return ['ok' => true];
                case 'invite.email':
                    $link = $this->invites->create($visit, $appointment, 'patient', null, $userId);
                    $sentTo = $this->invites->emailPatient($visit, $appointment, $link['url'], $userId);
                    return ['ok' => true, 'sentTo' => $sentTo, 'link' => $link];
            }
        } catch (VisitException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => false, 'error' => 'unknown action'];
    }

    /** @return array{ok: bool, participantId?: int, error?: string} */
    public function participant(string $action, int $sessionId, int $participantId, string $displayName, string $userAgent, int $userId): array
    {
        if ($this->ownedVisit($sessionId, $userId) === null) {
            return ['ok' => false, 'error' => xl('This visit does not exist.')];
        }
        if ($action === 'participant.joined') {
            $p = $this->participants->joined($sessionId, 'provider', $displayName, null, $userAgent);
            return ['ok' => true, 'participantId' => $p->id];
        }
        if ($action === 'participant.left') {
            $this->participants->left($participantId, $sessionId);
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'unknown action'];
    }

    /** @return array<string, mixed> */
    public function recording(string $action, int $sessionId, int $recordingId, int $userId): array
    {
        $owned = $this->ownedVisit($sessionId, $userId);
        if ($owned === null) {
            return ['ok' => false, 'error' => xl('This visit does not exist.')];
        }
        try {
            switch ($action) {
                case 'recording.start':
                    return ['ok' => true, 'recording' => $this->recordings->start($owned[0], $userId)->toArray()];
                case 'recording.started':
                    $this->recordings->started($recordingId, $sessionId);
                    break;
                case 'recording.stopped':
                    $this->recordings->stopped($recordingId, $sessionId);
                    break;
                case 'recording.failed':
                    $this->recordings->failed($recordingId, $sessionId);
                    break;
                case 'recording.list':
                    $this->recordings->importReady();
                    break;
                default:
                    return ['ok' => false, 'error' => 'unknown action'];
            }
        } catch (VisitException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'recordings' => array_map(static fn(Recording $r): array => $r->toArray(), $this->recordings->forSession($sessionId))];
    }

    /** The player page for one recording; the same answer as "not found" for anyone else's. */
    public function recordingPage(int $recordingId, int $userId): string
    {
        $recording = $this->recordings->find($recordingId);
        $owned = $recording === null ? null : $this->ownedVisit($recording->sessionId, $userId);
        if ($recording === null || $owned === null || $this->recordings->fileOf($recording) === null) {
            return $this->error(xl('This recording does not exist or is not ready yet.'));
        }
        [$visit, $appointment] = $owned;
        return $this->twig->render('recording.html.twig', [
            'patient' => trim($appointment->patientFirstName . ' ' . $appointment->patientLastName),
            'provider' => trim($appointment->providerFirstName . ' ' . $appointment->providerLastName),
            'date' => $appointment->date,
            'startedAt' => $recording->startedAt ?? $recording->createdAt,
            'duration' => self::clock($recording->durationSeconds),
            'encounter' => $visit->encounter,
            'streamUrl' => 'recording.php?' . http_build_query(['id' => $recording->id, 'stream' => 1]),
        ]);
    }

    /** Absolute path of a ready recording the user may watch, else null. A first byte request is a viewing. */
    public function recordingFile(int $recordingId, int $userId, bool $startsViewing): ?string
    {
        $recording = $this->recordings->find($recordingId);
        $owned = $recording === null ? null : $this->ownedVisit($recording->sessionId, $userId);
        $path = $recording === null || $owned === null ? null : $this->recordings->fileOf($recording);
        if ($recording !== null && $owned !== null && $path !== null && $startsViewing) {
            $this->gateway->audit('openvisit-record-view', sprintf('recording %d of session %d viewed by user %d', $recording->id, $recording->sessionId, $userId), $owned[1]->pid);
        }
        return $path;
    }

    public function recordingPoster(int $recordingId, int $userId): ?string
    {
        $recording = $this->recordings->find($recordingId);
        if ($recording === null || $this->ownedVisit($recording->sessionId, $userId) === null) {
            return null;
        }
        return $this->recordings->posterOf($recording);
    }

    /** The video calls of an encounter, one row each, shown on the encounter page; empty when it had none. */
    public function encounterCard(int $encounter, int $userId): string
    {
        $calls = [];
        foreach ($encounter > 0 ? $this->sessions->findByEncounter($encounter) : [] as $visit) {
            $appointment = $this->gateway->appointment($visit->eid);
            if ($appointment !== null) {
                $calls[] = [$visit, $appointment, $this->mayAct($appointment, $userId)];
            }
        }
        if ($calls === []) {
            return '';
        }
        if (in_array(true, array_column($calls, 2), true)) {
            $this->recordings->importReady();
        }
        $ids = array_map(static fn(array $call): int => $call[0]->id, $calls);
        $joined = $this->participants->counts($ids);
        $recordings = $this->recordings->forSessions($ids);
        $rows = array_map(
            fn(array $call): array => $this->callRow($call[0], $call[1], $call[2], $joined[$call[0]->id] ?? 0, $recordings[$call[0]->id] ?? []),
            $calls,
        );
        $active = in_array(Visit::STATUS_ACTIVE, array_column($rows, 'status'), true);
        return $this->twig->render('encounter-visit.html.twig', [
            'assets' => Bootstrap::assetPath(),
            'status' => $active ? Visit::STATUS_ACTIVE : $rows[count($rows) - 1]['status'],
            'calls' => $rows,
        ]);
    }

    /**
     * @param list<Recording> $recordings
     * @return array<string, mixed>
     */
    private function callRow(Visit $visit, Appointment $appointment, bool $mayWatch, int $joined, array $recordings): array
    {
        return [
            'eid' => $visit->eid,
            'status' => $visit->status,
            'summary' => $this->visitSummary($visit, $joined),
            'rejoinUrl' => $visit->status === Visit::STATUS_ACTIVE && $mayWatch ? Bootstrap::publicPath() . '/visit.php?eid=' . $visit->eid : null,
            'patient' => trim($appointment->patientFirstName . ' ' . $appointment->patientLastName),
            'recordings' => $mayWatch ? $this->recordingCards($recordings, $appointment) : [],
            'hiddenRecordings' => !$mayWatch && $recordings !== [],
        ];
    }

    private function visitSummary(Visit $visit, int $joined): string
    {
        $time = static fn(?string $at): string => $at === null ? '' : substr($at, 11, 5);
        if ($visit->status === Visit::STATUS_ACTIVE) {
            return sprintf(xl('In progress since %s.'), $time($visit->startedAt));
        }
        if ($visit->startedAt === null) {
            return xl('Not started.');
        }
        $minutes = $visit->durationMinutes() ?? 0;
        return sprintf(
            xl('%1$s to %2$s, %3$s. %4$s joined.'),
            $time($visit->startedAt),
            $time($visit->endedAt),
            $minutes === 1 ? xl('1 minute') : sprintf(xl('%d minutes'), $minutes),
            $joined === 1 ? xl('1 person') : sprintf(xl('%d people'), $joined),
        );
    }

    /**
     * @param list<Recording> $recordings
     * @return list<array<string, mixed>>
     */
    private function recordingCards(array $recordings, Appointment $appointment): array
    {
        $patient = trim($appointment->patientFirstName . ' ' . $appointment->patientLastName);
        $base = Bootstrap::publicPath() . '/recording.php?';
        $cards = [];
        foreach ($recordings as $r) {
            $time = substr($r->startedAt ?? $r->createdAt, 11, 5);
            $cards[] = [
                'id' => $r->id,
                'status' => $r->status,
                'duration' => self::clock($r->durationSeconds),
                'time' => $time,
                'stream' => $base . http_build_query(['id' => $r->id, 'stream' => 1]),
                'poster' => $this->recordings->posterOf($r) === null ? null : $base . http_build_query(['id' => $r->id, 'poster' => 1]),
                'page' => $base . http_build_query(['id' => $r->id]),
                'title' => sprintf(xl('Recording of %1$s, %2$s at %3$s'), $patient, $appointment->date, $time),
            ];
        }
        return $cards;
    }

    private static function clock(?int $seconds): string
    {
        return $seconds === null ? '' : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * The visit and its appointment when the user may act on it, else null (same answer as "not found").
     *
     * @return array{0: Visit, 1: Appointment}|null
     */
    private function ownedVisit(int $sessionId, int $userId): ?array
    {
        $visit = $this->sessions->findById($sessionId);
        $appointment = $visit === null ? null : $this->gateway->appointment($visit->eid);
        if ($visit === null || $appointment === null || !$this->mayAct($appointment, $userId)) {
            return null;
        }
        return [$visit, $appointment];
    }

    private function mayAct(Appointment $appointment, int $userId): bool
    {
        return $appointment->providerId === $userId || $this->gateway->canSuperviseVisits();
    }

    /** @return array<string, mixed> */
    public function adhoc(string $action, string $query, int $pid, int $userId): array
    {
        if (!$this->config->allowAdhoc()) {
            return ['ok' => false, 'error' => xl('Unscheduled visits are turned off in the OpenVisit settings.')];
        }
        if ($action === 'patient.search') {
            if (!$this->gateway->canSearchPatients()) {
                return ['ok' => false, 'error' => xl('You are not allowed to look patients up.')];
            }
            return ['ok' => true, 'patients' => $this->gateway->searchPatients($query)];
        }
        $categoryId = $this->gateway->telehealthCategoryId();
        if ($categoryId === null) {
            return ['ok' => false, 'error' => xl('The Telehealth Visit calendar category is missing.')];
        }
        try {
            $eid = $this->visits->bookAdhoc($pid, $userId, $categoryId, $this->config->adhocDurationMinutes());
        } catch (VisitException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'eid' => $eid, 'url' => Bootstrap::MODULE_PATH . '/public/visit.php?eid=' . $eid];
    }

    /** Visits of the last 30 days, newest first. */
    public function history(): string
    {
        $categoryId = $this->gateway->telehealthCategoryId();
        $since = $this->gateway->now()->modify('-30 days')->format('Y-m-d');
        $appointments = $categoryId === null ? [] : $this->gateway->appointmentsSince($since, $categoryId);
        $visits = $this->sessions->findByAppointments(array_values(array_map(static fn(Appointment $a): int => $a->eid, $appointments)));
        $sessionIds = array_values(array_map(static fn(Visit $v): int => $v->id, $visits));
        $joined = $this->participants->counts($sessionIds);
        $this->recordings->importReady();
        $recordings = $this->recordings->forSessions($sessionIds);

        $rows = [];
        foreach ($appointments as $appointment) {
            $visit = $visits[$appointment->eid] ?? null;
            if ($visit === null) {
                continue;
            }
            $rows[] = [
                'eid' => $appointment->eid,
                'date' => $appointment->date,
                'time' => substr($appointment->startTime, 0, 5),
                'patient' => trim($appointment->patientFirstName . ' ' . $appointment->patientLastName),
                'provider' => trim($appointment->providerFirstName . ' ' . $appointment->providerLastName),
                'status' => $visit->status,
                'startedAt' => $visit->startedAt,
                'duration' => $visit->durationMinutes(),
                'joined' => $joined[$visit->id] ?? 0,
                'encounter' => $visit->encounter,
                'recordings' => $this->recordingCards($recordings[$visit->id] ?? [], $appointment),
            ];
        }
        return $this->twig->render('history.html.twig', ['rows' => $rows, 'since' => $since, 'assets' => Bootstrap::assetPath()]);
    }

    /** Today's telehealth appointments with their visit state. */
    public function today(bool $isAdmin, string $csrfToken = ''): string
    {
        $categoryId = $this->gateway->telehealthCategoryId();
        $today = $this->gateway->now()->format('Y-m-d');
        $appointments = $categoryId === null ? [] : $this->gateway->appointmentsOn($today, $categoryId);
        $visits = $this->sessions->findByAppointments(array_values(array_map(static fn(Appointment $a): int => $a->eid, $appointments)));
        $joined = $this->participants->counts(array_values(array_map(static fn(Visit $v): int => $v->id, $visits)));

        $rows = [];
        foreach ($appointments as $appointment) {
            $visit = $visits[$appointment->eid] ?? null;
            $rows[] = [
                'eid' => $appointment->eid,
                'time' => substr($appointment->startTime, 0, 5),
                'patient' => trim($appointment->patientFirstName . ' ' . $appointment->patientLastName),
                'provider' => trim($appointment->providerFirstName . ' ' . $appointment->providerLastName),
                'status' => $visit === null ? 'not started' : $visit->status,
                'duration' => $visit?->durationMinutes(),
                'joined' => $visit === null ? 0 : ($joined[$visit->id] ?? 0),
                'url' => Bootstrap::MODULE_PATH . '/public/visit.php?eid=' . $appointment->eid,
            ];
        }

        return $this->twig->render('today.html.twig', [
            'assets' => Bootstrap::assetPath(),
            'todayConfig' => json_encode(['csrf' => $csrfToken, 'apiUrl' => Bootstrap::MODULE_PATH . '/public/api.php'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'allowAdhoc' => $this->config->allowAdhoc(),
            'date' => $today,
            'rows' => $rows,
            'configured' => $this->config->isConfigured(),
            'isAdmin' => $isAdmin,
            'hasCategory' => $categoryId !== null,
        ]);
    }

    private function error(string $message): string
    {
        return $this->twig->render('error.html.twig', ['message' => $message]);
    }
}
