<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\SettingsSource;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinToken;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\TokenCheck;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\TokenService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\FakeGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryAttemptStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemorySessionStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryTokenStore;
use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
    private InMemoryTokenStore $tokens;
    private InMemorySessionStore $sessions;
    private InMemoryAttemptStore $attempts;
    private FakeGateway $gateway;
    private TokenService $service;
    private Visit $visit;
    private Appointment $appointment;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryTokenStore();
        $this->sessions = new InMemorySessionStore();
        $this->attempts = new InMemoryAttemptStore();
        $this->gateway = new FakeGateway();
        // Appointment at 10:30 for 15 minutes; clock starts at 10:00, window is 10:00 to 12:45.
        $this->appointment = new Appointment(42, 7, 6, 11, '2026-11-09', '10:30:00', 900, 'Phil', 'Belford', 'Donna', 'Lee');
        $this->gateway->appointments[42] = $this->appointment;
        $this->visit = $this->sessions->create(42, 6, 'ov-room');
        $source = new class implements SettingsSource {
            public function get(string $key): ?string
            {
                return null;
            }
        };
        $config = new ModuleConfig($source, static fn(string $v): string => $v);
        $this->service = new TokenService($this->tokens, $this->sessions, $this->attempts, $this->gateway, $config);
    }

    public function testIssuedLinkIsAcceptedInsideTheWindow(): void
    {
        $issued = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_PATIENT, null, 6);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued->plaintext);
        self::assertSame('2026-11-09 12:45:00', $issued->token->expiresAt);
        self::assertNotSame($issued->plaintext, $this->tokens->hashes[$issued->token->id], 'plaintext must not be stored');

        $check = $this->service->check($issued->plaintext, '203.0.113.5');

        self::assertSame(TokenCheck::OK, $check->status);
        self::assertSame($this->visit->id, $check->visit?->id);
        self::assertSame('Phil', $check->appointment?->patientFirstName);
    }

    public function testJoinUrlCarriesTokenAndSite(): void
    {
        $url = $this->service->joinUrl('abc');

        self::assertSame('https://emr.example.test/openemr/interface/modules/custom_modules/oe-module-openvisit/public/join.php?t=abc&site=default', $url);
    }

    public function testUnknownRevokedAndUsedUpLinksAreRejected(): void
    {
        self::assertSame(TokenCheck::UNKNOWN, $this->service->check('nope', 'ip')->status);

        $issued = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_GUEST, 'Interpreter', 6);
        $this->service->revoke($issued->token->id, $this->visit->id, 6);
        self::assertSame(TokenCheck::REVOKED, $this->service->check($issued->plaintext, 'ip')->status);

        $second = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_PATIENT, null, 6);
        for ($i = 0; $i < TokenService::MAX_USES; $i++) {
            $this->service->consume($second->token);
        }
        self::assertSame(TokenCheck::USED_UP, $this->service->check($second->plaintext, 'ip')->status);
    }

    public function testWindowIsEnforcedBeforeAndAfterTheAppointment(): void
    {
        $issued = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_PATIENT, null, 6);

        $this->gateway->clock = new \DateTimeImmutable('2026-11-09 09:59:00');
        $early = $this->service->check($issued->plaintext, 'ip');
        self::assertSame(TokenCheck::NOT_YET, $early->status);
        self::assertSame('2026-11-09 10:00:00', $early->window?->opensAt->format('Y-m-d H:i:s'));

        $this->gateway->clock = new \DateTimeImmutable('2026-11-09 12:45:00');
        self::assertSame(TokenCheck::EXPIRED, $this->service->check($issued->plaintext, 'ip')->status, 'token expiry and window close coincide');
    }

    public function testTooManyAttemptsFromOneClientAreBlocked(): void
    {
        for ($i = 0; $i < TokenService::RATE_LIMIT_ATTEMPTS; $i++) {
            self::assertSame(TokenCheck::UNKNOWN, $this->service->check('guess' . $i, 'same-client')->status);
        }

        self::assertSame(TokenCheck::RATE_LIMITED, $this->service->check('guess-more', 'same-client')->status);
        self::assertSame(TokenCheck::UNKNOWN, $this->service->check('guess-more', 'other-client')->status);

        $this->gateway->clock = $this->gateway->clock->modify('+' . TokenService::RATE_LIMIT_MINUTES . ' minutes +1 second');
        self::assertSame(TokenCheck::UNKNOWN, $this->service->check('guess-later', 'same-client')->status);
    }

    public function testActivePatientTokenIgnoresRevokedAndGuestLinks(): void
    {
        $guest = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_GUEST, 'Family', 6);
        $old = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_PATIENT, null, 6);
        $this->service->revoke($old->token->id, $this->visit->id, 6);
        $current = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_PATIENT, null, 6);

        self::assertSame($current->token->id, $this->service->activePatientToken($this->visit->id)?->id);
        self::assertNotSame($guest->token->id, $this->service->activePatientToken($this->visit->id)?->id);
    }

    public function testEndedVisitClosesItsLinksAndEventsAreRefused(): void
    {
        $issued = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_PATIENT, null, 6);
        $this->sessions->markStarted($this->visit->id, 1, '2026-11-09 10:00:00');
        $this->sessions->markEnded($this->visit->id, '2026-11-09 10:20:00');

        self::assertSame(TokenCheck::CLOSED, $this->service->check($issued->plaintext, 'ip')->status);
        self::assertNull($this->service->resolve($issued->plaintext));
        self::assertTrue($this->service->visitEndedFor($issued->plaintext));
    }

    public function testVisitEndedIsOnlyReportedForRealLinksOfEndedVisits(): void
    {
        $issued = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_PATIENT, null, 6);

        self::assertFalse($this->service->visitEndedFor($issued->plaintext));
        self::assertFalse($this->service->visitEndedFor('not-a-real-token'));
    }

    public function testUseCapIsEnforcedAtomicallyAndGoodLinksDoNotCountAsAttempts(): void
    {
        $issued = $this->service->issue($this->visit, $this->appointment, JoinToken::ROLE_PATIENT, null, 6);
        for ($i = 0; $i < TokenService::MAX_USES; $i++) {
            self::assertTrue($this->service->consume($this->tokens->findById($issued->token->id) ?? $issued->token));
        }
        self::assertFalse($this->service->consume($this->tokens->findById($issued->token->id) ?? $issued->token));
        self::assertCount(0, $this->attempts->rows, 'valid links never burn the rate limit');
    }

    public function testRevokeIgnoresTokensOfOtherSessions(): void
    {
        $other = $this->sessions->create(43, 6, 'ov-other');
        $issued = $this->service->issue($other, $this->appointment, JoinToken::ROLE_GUEST, 'x', 6);

        $this->service->revoke($issued->token->id, $this->visit->id, 6);

        self::assertFalse($this->tokens->findById($issued->token->id)?->isRevoked());
    }

    public function testPortalLinksAreShortLivedAndThrottledPerPatient(): void
    {
        $first = $this->service->issuePortal($this->visit, $this->appointment, 7);
        self::assertNotNull($first);
        self::assertSame(3, $first->token->maxUses);
        self::assertSame('2026-11-09 12:00:00', $first->token->expiresAt);
        for ($i = 1; $i < TokenService::RATE_LIMIT_ATTEMPTS; $i++) {
            self::assertNotNull($this->service->issuePortal($this->visit, $this->appointment, 7));
        }
        self::assertNull($this->service->issuePortal($this->visit, $this->appointment, 7));
        self::assertNotNull($this->service->issuePortal($this->visit, $this->appointment, 8), 'other patients are unaffected');
    }

    public function testJoinUrlRefusesAnEmptySiteAddress(): void
    {
        $this->gateway->baseUrl = '';
        $this->expectException(\OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException::class);
        $this->service->joinUrl('abc');
    }

    public function testUnknownRoleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->issue($this->visit, $this->appointment, 'doctor', null, 6);
    }
}
