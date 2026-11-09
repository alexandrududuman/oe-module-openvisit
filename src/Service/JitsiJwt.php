<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;

/**
 * HS256 tokens for a Jitsi server with JWT auth. Kept dependency-free so the module
 * works even when copied in by hand without a Composer install.
 */
final class JitsiJwt
{
    private const LEEWAY_SECONDS = 60;

    public function __construct(
        private readonly string $appId,
        #[\SensitiveParameter]
        private readonly string $secret,
        private readonly string $domain,
    ) {}

    public function mint(string $room, string $displayName, bool $moderator, int $issuedAt, int $expiresAt): string
    {
        $payload = [
            'aud' => $this->appId,
            'iss' => $this->appId,
            'sub' => $this->host(),
            'room' => $room,
            'iat' => $issuedAt,
            'nbf' => $issuedAt - self::LEEWAY_SECONDS,
            'exp' => $expiresAt,
            'context' => ['user' => ['name' => $displayName, 'moderator' => $moderator]],
        ];
        $segments = [$this->encode(['alg' => 'HS256', 'typ' => 'JWT']), $this->encode($payload)];
        $signature = hash_hmac('sha256', implode('.', $segments), $this->secret, true);
        $segments[] = $this->base64Url($signature);
        return implode('.', $segments);
    }

    /** @return array<string, mixed>|null The claims when the signature is valid, else null. */
    public function claims(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $expected = $this->base64Url(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $this->secret, true));
        if (!hash_equals($expected, $parts[2])) {
            return null;
        }
        $decoded = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        return is_array($decoded) ? Scalars::stringKeyed($decoded) : null;
    }

    /** Domain without the port, as Jitsi compares it against its own host name. */
    public function host(): string
    {
        return explode(':', $this->domain)[0];
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return $this->base64Url(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
