<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;

final class SqlAttemptStore implements AttemptStore
{
    public function record(string $clientHash, string $at): void
    {
        QueryUtils::sqlInsert('INSERT INTO openvisit_join_attempt (client_hash, attempted_at) VALUES (?, ?)', [$clientHash, $at]);
    }

    public function countSince(string $clientHash, string $since): int
    {
        return Scalars::int(QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS n FROM openvisit_join_attempt WHERE client_hash = ? AND attempted_at >= ?',
            'n',
            [$clientHash, $since],
        ));
    }

    public function purgeBefore(string $before): int
    {
        QueryUtils::sqlStatementThrowException('DELETE FROM openvisit_join_attempt WHERE attempted_at < ?', [$before]);
        return Scalars::int(QueryUtils::affectedRows());
    }
}
