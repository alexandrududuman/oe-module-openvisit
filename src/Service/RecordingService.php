<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Participant;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Recording;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\ParticipantStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\RecordingStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\SessionStore;

/** Call recordings: started by the provider with two or more people in the call, filed by Jibri. */
final class RecordingService
{
    public const MIN_PEOPLE = 2;
    private const STARTING_TIMEOUT_MINUTES = 10;
    private const FILE_TIMEOUT_HOURS = 12;
    private const DB_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly RecordingStore $recordings,
        private readonly ParticipantStore $participants,
        private readonly SessionStore $sessions,
        private readonly ClinicalGateway $gateway,
        private readonly ModuleConfig $config,
        private readonly RecordingFiles $files,
    ) {}

    public function enabled(): bool
    {
        return $this->config->allowRecording();
    }

    /** The provider asks to record, having confirmed that everyone in the call agreed. */
    public function start(Visit $visit, int $userId): Recording
    {
        if (!$this->enabled()) {
            throw new VisitException(xl('Recording is turned off in the OpenVisit settings.'));
        }
        if ($visit->status !== Visit::STATUS_ACTIVE) {
            throw new VisitException(xl('Only a visit in progress can be recorded.'));
        }
        foreach ($this->recordings->findBySession($visit->id) as $existing) {
            if ($existing->isLive()) {
                throw new VisitException(xl('This visit is already being recorded.'));
            }
        }
        if ($this->peopleInCall($visit->id) < self::MIN_PEOPLE) {
            throw new VisitException(xl('Recording needs at least two people in the call. Wait until the patient or a guest has joined.'));
        }
        $recording = $this->recordings->create($visit->id, $userId, $this->now());
        $this->gateway->audit(
            'openvisit-record-start',
            sprintf('recording %d of session %d started by user %d, who confirmed the participants consent', $recording->id, $visit->id, $userId),
            $this->gateway->appointment($visit->eid)?->pid,
        );
        return $recording;
    }

    /** The provider plus everybody else still in the call; a provider on two tabs is one person. */
    public function peopleInCall(int $sessionId): int
    {
        $open = array_filter($this->participants->findBySession($sessionId), static fn(Participant $p): bool => $p->leftAt === null);
        $providers = count(array_filter($open, static fn(Participant $p): bool => $p->role === 'provider'));
        return min(1, $providers) + count($open) - $providers;
    }

    public function started(int $recordingId, int $sessionId): void
    {
        $recording = $this->ofSession($recordingId, $sessionId);
        if ($recording !== null && $recording->status === Recording::STATUS_STARTING) {
            $this->recordings->markRecording($recording->id, $this->now());
        }
    }

    public function stopped(int $recordingId, int $sessionId): void
    {
        $recording = $this->ofSession($recordingId, $sessionId);
        if ($recording !== null && $recording->isLive()) {
            $this->recordings->markProcessing($recording->id, $this->now());
            $this->gateway->audit('openvisit-record-stop', sprintf('recording %d of session %d stopped', $recording->id, $sessionId), null);
        }
    }

    public function failed(int $recordingId, int $sessionId): void
    {
        $recording = $this->ofSession($recordingId, $sessionId);
        if ($recording !== null && $recording->status === Recording::STATUS_STARTING) {
            $this->recordings->markFailed($recording->id);
        }
    }

    /** @return list<Recording> */
    public function forSession(int $sessionId): array
    {
        return $this->recordings->findBySession($sessionId);
    }

    /**
     * @param list<int> $sessionIds
     * @return array<int, list<Recording>>
     */
    public function forSessions(array $sessionIds): array
    {
        return $this->recordings->findBySessions($sessionIds);
    }

    public function find(int $recordingId): ?Recording
    {
        return $this->recordings->findById($recordingId);
    }

    public function fileOf(Recording $recording): ?string
    {
        if ($recording->status !== Recording::STATUS_READY || $recording->filePath === null) {
            return null;
        }
        $path = $this->files->absolute($recording->filePath);
        return is_file($path) ? $path : null;
    }

    /** Files what Jibri finished into storage and gives up on recordings that never came. Returns how many were filed. */
    public function importReady(): int
    {
        $imported = 0;
        foreach ($this->files->claimReady() as $ready) {
            $visit = $ready->room === '' ? null : $this->sessions->findByRoom($ready->room);
            if ($visit === null) {
                $this->files->setAside($ready);
                continue;
            }
            $waiting = array_values(array_filter($this->recordings->findBySession($visit->id), static fn(Recording $r): bool => $r->awaitsFile()));
            $recording = $waiting[0] ?? $this->recordings->create($visit->id, $visit->providerId, $this->now());
            $relative = sprintf('%d/%d.mp4', $visit->id, $recording->id);
            $size = $this->files->store($ready, $relative);
            $this->recordings->markReady($recording->id, $relative, $size, $ready->durationSeconds, $this->now());
            $this->gateway->audit('openvisit-record-saved', sprintf('recording %d of session %d saved, %d bytes', $recording->id, $visit->id, $size), null);
            $imported++;
        }
        $now = $this->gateway->now();
        $this->recordings->failStale(
            $now->modify('-' . self::STARTING_TIMEOUT_MINUTES . ' minutes')->format(self::DB_FORMAT),
            $now->modify('-' . self::FILE_TIMEOUT_HOURS . ' hours')->format(self::DB_FORMAT),
        );
        return $imported;
    }

    private function ofSession(int $recordingId, int $sessionId): ?Recording
    {
        $recording = $this->recordings->findById($recordingId);
        return $recording !== null && $recording->sessionId === $sessionId ? $recording : null;
    }

    private function now(): string
    {
        return $this->gateway->now()->format(self::DB_FORMAT);
    }
}
