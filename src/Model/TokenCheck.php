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

/** Outcome of validating a join link. Only `ok` carries the visit and appointment. */
final readonly class TokenCheck
{
    public const OK = 'ok';
    public const RATE_LIMITED = 'rate_limited';
    public const UNKNOWN = 'unknown';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';
    public const USED_UP = 'used_up';
    public const NOT_YET = 'not_yet';
    public const CLOSED = 'closed';

    public function __construct(
        public string $status,
        public ?JoinToken $token = null,
        public ?Visit $visit = null,
        public ?Appointment $appointment = null,
        public ?JoinWindow $window = null,
    ) {}

    public static function failed(string $status, ?JoinToken $token = null, ?JoinWindow $window = null): self
    {
        return new self($status, $token, null, null, $window);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
