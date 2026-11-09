<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

use OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\StartedVisit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\SessionStore;

/** The visit lifecycle: one session per appointment, one encounter per session. */
final class VisitService
{
    private const DB_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly ClinicalGateway $gateway,
        private readonly RoomNameGenerator $rooms,
    ) {}

    /** Opens the provider side. Safe to call again to rejoin; the room and encounter are reused. */
    public function startForProvider(int $eid, int $userId): StartedVisit
    {
        $appointment = $this->gateway->appointment($eid)
            ?? throw new VisitException(xl('This appointment no longer exists.'));
        if ($appointment->pid <= 0) {
            throw new VisitException(xl('This appointment has no patient, so there is nobody to call.'));
        }

        $visit = $this->sessions->findByAppointment($eid)
            ?? $this->sessions->create($eid, $userId, $this->rooms->generate());

        $now = $this->gateway->now()->format(self::DB_FORMAT);
        $encounter = $visit->encounter;
        if ($encounter === null) {
            $encounter = $this->gateway->createEncounter($eid);
            if ($encounter !== null) {
                $this->gateway->openEncounter($encounter, $now);
            }
        }
        $this->sessions->markStarted($visit->id, $encounter, $now);
        $this->gateway->audit('openvisit-start', sprintf('session %d room %s', $visit->id, $visit->roomName), $appointment->pid);

        return new StartedVisit($this->reload($visit->id), $appointment);
    }

    /** Closes the visit and stamps the encounter end time. Ending twice is harmless. */
    public function end(int $sessionId): Visit
    {
        $visit = $this->sessions->findById($sessionId)
            ?? throw new VisitException(xl('This visit does not exist.'));
        if ($visit->isEnded()) {
            return $visit;
        }

        $now = $this->gateway->now()->format(self::DB_FORMAT);
        $this->sessions->markEnded($visit->id, $now);
        if ($visit->encounter !== null) {
            $this->gateway->closeEncounter($visit->encounter, $now);
        }
        $this->gateway->audit('openvisit-end', sprintf('session %d', $visit->id), null);

        return $this->reload($visit->id);
    }

    private function reload(int $id): Visit
    {
        return $this->sessions->findById($id)
            ?? throw new \RuntimeException("Session $id disappeared");
    }
}
