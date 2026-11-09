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

final readonly class Appointment
{
    public function __construct(
        public int $eid,
        public int $pid,
        public int $providerId,
        public int $categoryId,
        public string $date,
        public string $startTime,
        public int $durationSeconds,
        public string $patientFirstName,
        public string $patientLastName,
        public string $providerFirstName,
        public string $providerLastName,
    ) {}

    public function startsAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->date . ' ' . $this->startTime);
    }

    public function endsAt(): \DateTimeImmutable
    {
        return $this->startsAt()->modify('+' . max(0, $this->durationSeconds) . ' seconds');
    }
}
