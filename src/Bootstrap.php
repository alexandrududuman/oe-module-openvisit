<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit;

use OpenVisit\OpenEMR\Modules\OpenVisit\Listener\GlobalsListener;
use OpenVisit\OpenEMR\Modules\OpenVisit\Listener\MenuListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Bootstrap
{
    public const MODULE_DIRECTORY = 'oe-module-openvisit';
    public const MODULE_PATH = '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY;

    public function __construct(private readonly EventDispatcherInterface $dispatcher) {}

    public function subscribeToEvents(): void
    {
        (new GlobalsListener())->subscribe($this->dispatcher);
        (new MenuListener())->subscribe($this->dispatcher);
    }
}
