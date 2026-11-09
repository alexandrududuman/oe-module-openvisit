<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Model;

/** When a patient or guest link works: from shortly before the appointment to a while after it. */
final readonly class JoinWindow
{
    public function __construct(
        public \DateTimeImmutable $opensAt,
        public \DateTimeImmutable $closesAt,
    ) {}

    public static function forAppointment(Appointment $appointment, int $beforeMinutes, int $afterHours): self
    {
        return new self(
            $appointment->startsAt()->modify("-{$beforeMinutes} minutes"),
            $appointment->endsAt()->modify("+{$afterHours} hours"),
        );
    }

    public function isBefore(\DateTimeImmutable $now): bool
    {
        return $now < $this->opensAt;
    }

    public function isAfter(\DateTimeImmutable $now): bool
    {
        return $now >= $this->closesAt;
    }

    public function contains(\DateTimeImmutable $now): bool
    {
        return !$this->isBefore($now) && !$this->isAfter($now);
    }
}
