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

use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\AttemptStore;

final class InMemoryAttemptStore implements AttemptStore
{
    /** @var list<array{0: string, 1: string}> */
    public array $rows = [];

    public function record(string $clientHash, string $at): void
    {
        $this->rows[] = [$clientHash, $at];
    }

    public function countSince(string $clientHash, string $since): int
    {
        return count(array_filter($this->rows, static fn(array $r): bool => $r[0] === $clientHash && $r[1] >= $since));
    }

    public function purgeBefore(string $before): int
    {
        $keep = array_values(array_filter($this->rows, static fn(array $r): bool => $r[1] >= $before));
        $n = count($this->rows) - count($keep);
        $this->rows = $keep;
        return $n;
    }
}
