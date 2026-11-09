<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\AttemptStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\SessionStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\TokenStore;

/** Hourly housekeeping run by the OpenEMR background service. */
final class CleanupService
{
    public const TOKEN_RETENTION_DAYS = 7;
    public const ATTEMPT_RETENTION_HOURS = 24;
    private const DB_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly TokenStore $tokens,
        private readonly AttemptStore $attempts,
        private readonly TokenService $tokenService,
        private readonly ClinicalGateway $gateway,
    ) {}

    /** @return array{closedVisits: int, purgedTokens: int, purgedAttempts: int} */
    public function run(): array
    {
        $now = $this->gateway->now();
        $closed = 0;
        foreach ($this->sessions->findActive() as $visit) {
            $appointment = $this->gateway->appointment($visit->eid);
            if ($appointment === null || $this->tokenService->window($appointment)->isAfter($now)) {
                $at = $now->format(self::DB_FORMAT);
                $this->sessions->markEnded($visit->id, $at);
                if ($visit->encounter !== null) {
                    $this->gateway->closeEncounter($visit->encounter, $at);
                }
                $this->gateway->audit('openvisit-autoclose', sprintf('session %d closed by cleanup', $visit->id), null);
                $closed++;
            }
        }
        return [
            'closedVisits' => $closed,
            'purgedTokens' => $this->tokens->purgeExpiredBefore($now->modify('-' . self::TOKEN_RETENTION_DAYS . ' days')->format(self::DB_FORMAT)),
            'purgedAttempts' => $this->attempts->purgeBefore($now->modify('-' . self::ATTEMPT_RETENTION_HOURS . ' hours')->format(self::DB_FORMAT)),
        ];
    }
}
