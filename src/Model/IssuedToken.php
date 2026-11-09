<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Model;

/** A freshly created link: the stored row plus the one-time plaintext for the URL. */
final readonly class IssuedToken
{
    public function __construct(
        public JoinToken $token,
        public string $plaintext,
    ) {}
}
