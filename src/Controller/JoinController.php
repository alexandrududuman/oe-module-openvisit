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
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinToken;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\TokenCheck;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\JitsiJwt;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\ParticipantService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\TokenService;
use Twig\Environment;

/** The public side: a patient or guest arrives with nothing but a link. */
final class JoinController
{
    public function __construct(
        private readonly ModuleConfig $config,
        private readonly ClinicalGateway $gateway,
        private readonly TokenService $tokens,
        private readonly ParticipantService $participants,
        private readonly Environment $twig,
    ) {}

    public function page(string $plaintext, string $clientId): string
    {
        if ($plaintext === '' || !$this->config->isConfigured()) {
            return $this->problem(TokenCheck::UNKNOWN);
        }
        $check = $this->tokens->check($plaintext, $clientId);
        if (!$check->isOk() || $check->token === null || $check->visit === null || $check->appointment === null) {
            return $this->problem($check->status, $check);
        }
        if (!$this->tokens->consume($check->token)) {
            return $this->problem(TokenCheck::USED_UP);
        }

        $displayName = $this->displayName($check);
        $now = $this->gateway->now();
        $jwt = (new JitsiJwt($this->config->jwtAppId(), $this->config->jwtAppSecret(), $this->config->jitsiDomain()))
            ->mint($check->visit->roomName, $displayName, false, $now->getTimestamp(), (new \DateTimeImmutable($check->token->expiresAt))->getTimestamp());

        $client = [
            'domain' => $this->config->jitsiDomain(),
            'room' => $check->visit->roomName,
            'jwt' => $jwt,
            'displayName' => $displayName,
            'token' => $plaintext,
            'eventsUrl' => Bootstrap::MODULE_PATH . '/public/join-events.php',
            'site' => $this->gateway->siteId(),
        ];
        return $this->twig->render('join.html.twig', [
            'assets' => Bootstrap::assetPath(),
            'displayName' => $displayName,
            'role' => $check->token->role,
            'startsAt' => $check->appointment->startsAt()->format('Y-m-d H:i'),
            'clientConfig' => json_encode($client, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
    }

    /** @return array{ok: bool, participantId?: int, error?: string} */
    public function event(string $plaintext, string $action, int $participantId, string $userAgent): array
    {
        $token = $this->tokens->resolve($plaintext);
        if ($token === null) {
            return ['ok' => false, 'error' => 'unknown link'];
        }
        if ($action === 'joined') {
            $open = $this->participants->openForToken($token->sessionId, $token->id);
            if ($open !== null) {
                return ['ok' => true, 'participantId' => $open->id];
            }
            $name = $token->role === JoinToken::ROLE_GUEST ? ($token->label ?? xl('Guest')) : xl('Patient');
            $participant = $this->participants->joined($token->sessionId, $token->role, $name, $token->id, $userAgent);
            return ['ok' => true, 'participantId' => $participant->id];
        }
        if ($action === 'left' && $participantId > 0) {
            $this->participants->leftOwn($participantId, $token->sessionId, $token->id);
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'unknown action'];
    }

    private function displayName(TokenCheck $check): string
    {
        $token = $check->token;
        $appointment = $check->appointment;
        if ($token === null || $appointment === null) {
            return xl('Guest');
        }
        if ($token->role === JoinToken::ROLE_GUEST) {
            return $token->label !== null && $token->label !== '' ? $token->label : xl('Guest');
        }
        $name = $appointment->patientFirstName !== '' ? $appointment->patientFirstName : xl('Patient');
        if ($this->config->showPatientLastInitial() && $appointment->patientLastName !== '') {
            $name .= ' ' . mb_substr($appointment->patientLastName, 0, 1) . '.';
        }
        return $name;
    }

    private function problem(string $status, ?TokenCheck $check = null): string
    {
        $messages = [
            TokenCheck::RATE_LIMITED => xl('Too many attempts. Please wait a few minutes and open the link again.'),
            TokenCheck::UNKNOWN => xl('This link is not valid. Check that you copied the whole link, or ask the clinic for a new one.'),
            TokenCheck::REVOKED => xl('This link was cancelled by the clinic. Ask them for a new one.'),
            TokenCheck::EXPIRED => xl('This link has expired. Ask the clinic for a new one.'),
            TokenCheck::USED_UP => xl('This link was used too many times. Ask the clinic for a new one.'),
            TokenCheck::NOT_YET => xl('It is too early to join. The link opens shortly before your appointment.'),
            TokenCheck::CLOSED => xl('This visit is over. If you still need to talk to the clinic, please call them.'),
        ];
        $opensAt = $check?->window?->opensAt;
        return $this->twig->render('join-error.html.twig', [
            'assets' => Bootstrap::assetPath(),
            'status' => $status,
            'message' => $messages[$status] ?? $messages[TokenCheck::UNKNOWN],
            'opensAt' => $status === TokenCheck::NOT_YET && $opensAt !== null ? $opensAt->format('Y-m-d H:i') : null,
        ]);
    }
}
