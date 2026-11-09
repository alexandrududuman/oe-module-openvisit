<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Model;

/** A finished recording Jibri left in the inbox, claimed by this request for import. */
final readonly class ReadyRecording
{
    public function __construct(
        public string $directory,
        public string $file,
        public string $room,
        public ?int $durationSeconds,
    ) {}
}
