<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck;

/** Network and environment lookups the self-check needs, behind a seam for tests. */
interface Prober
{
    /** @return array{status: int, body: string, error: string} status 0 when the request failed */
    public function get(string $url, int $timeoutSeconds = 5): array;

    public function mailerConfigured(): bool;

    public function backgroundServiceActive(string $name): bool;

    public function publicBaseUrl(): string;
}
