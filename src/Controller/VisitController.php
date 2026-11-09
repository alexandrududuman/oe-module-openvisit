<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Controller;

use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\SessionStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\JitsiJwt;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\VisitService;
use Twig\Environment;

final class VisitController
{
    private const TOKEN_GRACE_HOURS = 2;

    public function __construct(
        private readonly ModuleConfig $config,
        private readonly ClinicalGateway $gateway,
        private readonly VisitService $visits,
        private readonly SessionStore $sessions,
        private readonly Environment $twig,
    ) {}

    /** The provider's room page. */
    public function room(int $eid, int $userId, string $csrfToken): string
    {
        if (!$this->config->isConfigured()) {
            return $this->error(xl('Video visits are not set up yet. Ask your administrator to fill in the OpenVisit tab under Administration, Globals.'));
        }
        if ($eid <= 0) {
            return $this->error(xl('No appointment was given.'));
        }

        try {
            $started = $this->visits->startForProvider($eid, $userId);
        } catch (VisitException $e) {
            return $this->error($e->getMessage());
        }

        $name = $this->gateway->userName($userId);
        $displayName = trim($name['first'] . ' ' . $name['last']);
        if ($displayName === '') {
            $displayName = xl('Provider');
        }
        $now = $this->gateway->now();
        $expires = max($started->appointment->endsAt(), $now)->modify('+' . self::TOKEN_GRACE_HOURS . ' hours');
        $jwt = (new JitsiJwt($this->config->jwtAppId(), $this->config->jwtAppSecret(), $this->config->jitsiDomain()))
            ->mint($started->visit->roomName, $displayName, true, $now->getTimestamp(), $expires->getTimestamp());

        $client = [
            'domain' => $this->config->jitsiDomain(),
            'room' => $started->visit->roomName,
            'jwt' => $jwt,
            'displayName' => $displayName,
            'subject' => xl('Visit with') . ' ' . $started->appointment->patientFirstName,
            'sessionId' => $started->visit->id,
            'csrf' => $csrfToken,
            'endUrl' => Bootstrap::MODULE_PATH . '/public/api.php',
            'moderator' => true,
        ];

        return $this->twig->render('visit.html.twig', [
            'assets' => Bootstrap::assetPath(),
            'patientName' => trim($started->appointment->patientFirstName . ' ' . $started->appointment->patientLastName),
            'startsAt' => $started->appointment->startsAt()->format('Y-m-d H:i'),
            'encounter' => $started->visit->encounter,
            'clientConfig' => json_encode($client, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
    }

    /** @return array{ok: bool, status?: string, durationMinutes?: int|null, error?: string} */
    public function end(int $sessionId): array
    {
        try {
            $visit = $this->visits->end($sessionId);
        } catch (VisitException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'status' => $visit->status, 'durationMinutes' => $visit->durationMinutes()];
    }

    /** Today's telehealth appointments with their visit state. */
    public function today(bool $isAdmin): string
    {
        $categoryId = $this->gateway->telehealthCategoryId();
        $today = $this->gateway->now()->format('Y-m-d');
        $appointments = $categoryId === null ? [] : $this->gateway->appointmentsOn($today, $categoryId);
        $visits = $this->sessions->findByAppointments(array_values(array_map(static fn(Appointment $a): int => $a->eid, $appointments)));

        $rows = [];
        foreach ($appointments as $appointment) {
            $visit = $visits[$appointment->eid] ?? null;
            $rows[] = [
                'eid' => $appointment->eid,
                'time' => substr($appointment->startTime, 0, 5),
                'patient' => trim($appointment->patientFirstName . ' ' . $appointment->patientLastName),
                'provider' => trim($appointment->providerFirstName . ' ' . $appointment->providerLastName),
                'status' => $visit === null ? 'not started' : $visit->status,
                'duration' => $visit?->durationMinutes(),
                'url' => Bootstrap::MODULE_PATH . '/public/visit.php?eid=' . $appointment->eid,
            ];
        }

        return $this->twig->render('today.html.twig', [
            'date' => $today,
            'rows' => $rows,
            'configured' => $this->config->isConfigured(),
            'isAdmin' => $isAdmin,
            'hasCategory' => $categoryId !== null,
        ]);
    }

    private function error(string $message): string
    {
        return $this->twig->render('error.html.twig', ['message' => $message]);
    }
}
