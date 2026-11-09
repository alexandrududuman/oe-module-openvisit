<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Recording;

interface RecordingStore
{
    public function create(int $sessionId, int $startedBy, string $createdAt): Recording;

    public function findById(int $id): ?Recording;

    /** @return list<Recording> oldest first */
    public function findBySession(int $sessionId): array;

    /**
     * @param list<int> $sessionIds
     * @return array<int, list<Recording>> session id => recordings, oldest first
     */
    public function findBySessions(array $sessionIds): array;

    public function markRecording(int $id, string $startedAt): void;

    /** Recording stopped; the file is being finalized by Jibri. */
    public function markProcessing(int $id, string $stoppedAt): void;

    public function markFailed(int $id): void;

    public function markReady(int $id, string $filePath, int $fileSize, ?int $durationSeconds, string $stoppedAt): void;

    /** Fails recordings that never got going or whose file never arrived. Returns how many. */
    public function failStale(string $startingBefore, string $waitingBefore): int;
}
