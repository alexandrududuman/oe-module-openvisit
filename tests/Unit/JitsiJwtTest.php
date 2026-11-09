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

use OpenVisit\OpenEMR\Modules\OpenVisit\Service\JitsiJwt;
use PHPUnit\Framework\TestCase;

final class JitsiJwtTest extends TestCase
{
    private const SECRET = 'correct horse battery staple';

    public function testTokenCarriesTheJitsiClaims(): void
    {
        $jwt = new JitsiJwt('openvisit', self::SECRET, 'meet.clinic.example:8443');

        $token = $jwt->mint('ov-abc', 'Donna Lee', true, 1_000_000, 1_003_600);
        $claims = $jwt->claims($token);

        self::assertNotNull($claims);
        self::assertSame('openvisit', $claims['aud']);
        self::assertSame('openvisit', $claims['iss']);
        self::assertSame('meet.clinic.example', $claims['sub']);
        self::assertSame('ov-abc', $claims['room']);
        self::assertSame(1_000_000, $claims['iat']);
        self::assertSame(999_940, $claims['nbf']);
        self::assertSame(1_003_600, $claims['exp']);
        self::assertSame(['name' => 'Donna Lee', 'moderator' => true], $claims['context']['user']);
    }

    public function testHeaderDeclaresHs256(): void
    {
        $token = (new JitsiJwt('a', self::SECRET, 'h'))->mint('r', 'n', false, 1, 2);
        $header = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true), true);

        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $header);
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $token, 'segments must be base64url without padding');
    }

    public function testWrongSecretDoesNotVerify(): void
    {
        $token = (new JitsiJwt('openvisit', self::SECRET, 'h'))->mint('r', 'n', false, 1, 2);

        self::assertNull((new JitsiJwt('openvisit', 'other', 'h'))->claims($token));
        self::assertNull((new JitsiJwt('openvisit', self::SECRET, 'h'))->claims('not.a.token.at.all'));
    }

    public function testPatientTokenIsNotModerator(): void
    {
        $jwt = new JitsiJwt('openvisit', self::SECRET, 'h');
        $claims = $jwt->claims($jwt->mint('r', 'Phil', false, 1, 2));

        self::assertFalse($claims['context']['user']['moderator']);
    }
}
