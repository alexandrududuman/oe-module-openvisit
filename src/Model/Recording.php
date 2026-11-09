<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Model;

/** One row of openvisit_recording: a call recording made by Jibri. */
final readonly class Recording
{
    public const STATUS_STARTING = 'starting';
    public const STATUS_RECORDING = 'recording';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public int $id,
        public int $sessionId,
        public string $status,
        public int $startedBy,
        public ?string $startedAt,
        public ?string $stoppedAt,
        public ?string $filePath,
        public ?int $fileSize,
        public ?int $durationSeconds,
        public string $createdAt,
    ) {}

    /** Started from the room and still waiting for its file. */
    public function awaitsFile(): bool
    {
        return in_array($this->status, [self::STATUS_STARTING, self::STATUS_RECORDING, self::STATUS_PROCESSING], true);
    }

    /** Jibri is (about to be) recording right now. */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_STARTING || $this->status === self::STATUS_RECORDING;
    }

    /** @return array{id: int, status: string, startedAt: ?string, stoppedAt: ?string, durationSeconds: ?int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'stoppedAt' => $this->stoppedAt,
            'durationSeconds' => $this->durationSeconds,
        ];
    }
}
