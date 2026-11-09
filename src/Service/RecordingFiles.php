<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\ReadyRecording;

/**
 * The two folders a recording passes through: the inbox Jibri writes to (one folder per
 * recording, with a .ready marker from docker/jibri/finalize.sh) and the module's storage.
 * Jibri owns the inbox itself, so a filed folder that cannot be removed is marked instead and
 * the finalize script removes it on its next run.
 */
final class RecordingFiles
{
    private const READY = '.ready';
    private const CLAIMED = '.importing';
    private const UNMATCHED = '.unmatched';
    private const IMPORTED = '.imported';
    private const JIBRI_NAME = '/^(.+)_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.mp4$/';

    public function __construct(
        private readonly string $inbox,
        private readonly string $storage,
    ) {}

    /** @return list<ReadyRecording> recordings this call now owns; a concurrent call gets the others */
    public function claimReady(): array
    {
        if (!is_dir($this->inbox)) {
            return [];
        }
        $claimed = [];
        foreach (glob($this->inbox . '/*/' . self::READY) ?: [] as $marker) {
            $directory = dirname($marker);
            if (!@rename($marker, $directory . '/' . self::CLAIMED)) {
                continue;
            }
            $file = (glob($directory . '/*.mp4') ?: [])[0] ?? null;
            if ($file === null) {
                continue;
            }
            $duration = trim((string) file_get_contents($directory . '/' . self::CLAIMED));
            $claimed[] = new ReadyRecording(
                $directory,
                $file,
                $this->roomOf($directory, $file),
                is_numeric($duration) ? (int) $duration : null,
            );
        }
        return $claimed;
    }

    /** Moves the file to its place in storage, removes the inbox folder, returns the size in bytes. */
    public function store(ReadyRecording $recording, string $relativePath): int
    {
        $target = $this->absolute($relativePath);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Cannot create ' . dirname($target));
        }
        if (!rename($recording->file, $target)) {
            throw new \RuntimeException('Cannot move ' . $recording->file);
        }
        chmod($target, 0600);
        foreach (glob($recording->directory . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $leftover) {
            if (basename($leftover) !== self::CLAIMED) {
                @unlink($leftover);
            }
        }
        $claim = $recording->directory . '/' . self::CLAIMED;
        if (!@unlink($claim) || !@rmdir($recording->directory)) {
            @touch($recording->directory . '/' . self::IMPORTED);
        }
        return (int) filesize($target);
    }

    /** Leaves a recording that belongs to no OpenVisit room where it is, and stops looking at it. */
    public function setAside(ReadyRecording $recording): void
    {
        @rename($recording->directory . '/' . self::CLAIMED, $recording->directory . '/' . self::UNMATCHED);
    }

    public function absolute(string $relativePath): string
    {
        if (preg_match('#^\d+/\d+\.mp4$#', $relativePath) !== 1) {
            throw new \InvalidArgumentException('Unexpected recording path');
        }
        return $this->storage . '/' . $relativePath;
    }

    public function inboxUsable(): bool
    {
        return is_dir($this->inbox) && is_readable($this->inbox);
    }

    public function storageUsable(): bool
    {
        if (!is_dir($this->storage) && !@mkdir($this->storage, 0700, true) && !is_dir($this->storage)) {
            return false;
        }
        return is_writable($this->storage);
    }

    private function roomOf(string $directory, string $file): string
    {
        $metadata = json_decode((string) @file_get_contents($directory . '/metadata.json'), true);
        $url = is_array($metadata) && is_string($metadata['meeting_url'] ?? null) ? $metadata['meeting_url'] : '';
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path !== '' && $path !== '/') {
            return strtolower(basename($path));
        }
        return preg_match(self::JIBRI_NAME, basename($file), $m) === 1 ? strtolower($m[1]) : '';
    }
}
