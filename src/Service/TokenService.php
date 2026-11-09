<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\IssuedToken;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinToken;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinWindow;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\TokenCheck;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\AttemptStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\SessionStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\TokenStore;

/** Join links for patients and guests: unguessable, hashed at rest, time-boxed, revocable, rate limited. */
final class TokenService
{
    public const MAX_USES = 20;
    public const RATE_LIMIT_ATTEMPTS = 10;
    public const RATE_LIMIT_MINUTES = 10;
    private const DB_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly TokenStore $tokens,
        private readonly SessionStore $sessions,
        private readonly AttemptStore $attempts,
        private readonly ClinicalGateway $gateway,
        private readonly ModuleConfig $config,
    ) {}

    public function window(Appointment $appointment): JoinWindow
    {
        return JoinWindow::forAppointment($appointment, $this->config->joinBeforeMinutes(), $this->config->joinAfterHours());
    }

    public function issue(Visit $visit, Appointment $appointment, string $role, ?string $label, int $userId): IssuedToken
    {
        if (!in_array($role, [JoinToken::ROLE_PATIENT, JoinToken::ROLE_GUEST], true)) {
            throw new \InvalidArgumentException("Unknown role $role");
        }
        $plaintext = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $this->window($appointment)->closesAt->format(self::DB_FORMAT);
        $label = $label === null ? null : mb_substr(trim($label), 0, 80);
        $token = $this->tokens->create($visit->id, $role, self::hash($plaintext), $label === '' ? null : $label, $expiresAt, self::MAX_USES, $userId);
        $this->gateway->audit('openvisit-invite', sprintf('%s link %d for session %d', $role, $token->id, $visit->id), $appointment->pid);
        return new IssuedToken($token, $plaintext);
    }

    /** Validates a link as presented by a browser. Counts the attempt against the client first. */
    public function check(string $plaintext, string $clientId): TokenCheck
    {
        $now = $this->gateway->now();
        $clientHash = hash('sha256', $clientId);
        $since = $now->modify('-' . self::RATE_LIMIT_MINUTES . ' minutes')->format(self::DB_FORMAT);
        if ($this->attempts->countSince($clientHash, $since) >= self::RATE_LIMIT_ATTEMPTS) {
            return TokenCheck::failed(TokenCheck::RATE_LIMITED);
        }
        $this->attempts->record($clientHash, $now->format(self::DB_FORMAT));

        $token = $this->tokens->findByHash(self::hash($plaintext));
        if ($token === null) {
            return TokenCheck::failed(TokenCheck::UNKNOWN);
        }
        if ($token->isRevoked()) {
            return TokenCheck::failed(TokenCheck::REVOKED, $token);
        }
        if ($token->isExpired($now)) {
            return TokenCheck::failed(TokenCheck::EXPIRED, $token);
        }
        if ($token->isUsedUp()) {
            return TokenCheck::failed(TokenCheck::USED_UP, $token);
        }

        $visit = $this->sessions->findById($token->sessionId);
        $appointment = $visit === null ? null : $this->gateway->appointment($visit->eid);
        if ($visit === null || $appointment === null || $visit->status === Visit::STATUS_CANCELLED) {
            return TokenCheck::failed(TokenCheck::EXPIRED, $token);
        }
        $window = $this->window($appointment);
        if ($window->isBefore($now)) {
            return TokenCheck::failed(TokenCheck::NOT_YET, $token, $window);
        }
        if ($window->isAfter($now)) {
            return TokenCheck::failed(TokenCheck::CLOSED, $token, $window);
        }
        return new TokenCheck(TokenCheck::OK, $token, $visit, $appointment, $window);
    }

    /** Resolves a link to its token without window or rate checks, for in-room event reporting. */
    public function resolve(string $plaintext): ?JoinToken
    {
        $token = $this->tokens->findByHash(self::hash($plaintext));
        return $token === null || $token->isRevoked() ? null : $token;
    }

    public function consume(JoinToken $token): void
    {
        $this->tokens->recordUse($token->id);
    }

    public function revoke(int $tokenId, int $userId): void
    {
        $token = $this->tokens->findById($tokenId);
        if ($token === null) {
            return;
        }
        $this->tokens->revoke($token->id, $this->gateway->now()->format(self::DB_FORMAT));
        $this->gateway->audit('openvisit-revoke', sprintf('link %d by user %d', $token->id, $userId), null);
    }

    /** @return list<JoinToken> */
    public function forSession(int $sessionId): array
    {
        return $this->tokens->findBySession($sessionId);
    }

    public function activePatientToken(int $sessionId): ?JoinToken
    {
        $now = $this->gateway->now();
        foreach ($this->tokens->findBySession($sessionId) as $token) {
            if ($token->role === JoinToken::ROLE_PATIENT && $token->isActive($now)) {
                return $token;
            }
        }
        return null;
    }

    public function joinUrl(string $plaintext): string
    {
        return $this->gateway->publicBaseUrl() . '/interface/modules/custom_modules/oe-module-openvisit/public/join.php?'
            . http_build_query(['t' => $plaintext, 'site' => $this->gateway->siteId()]);
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
