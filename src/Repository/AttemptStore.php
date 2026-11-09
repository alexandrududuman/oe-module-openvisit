<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

/** Join attempts per client, for rate limiting the public join page. */
interface AttemptStore
{
    public function record(string $clientHash, string $at): void;

    public function countSince(string $clientHash, string $since): int;

    public function purgeBefore(string $before): int;
}
