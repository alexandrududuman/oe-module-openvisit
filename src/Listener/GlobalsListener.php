<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Listener;

use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Services\Globals\GlobalSetting;
use OpenVisit\OpenEMR\Modules\OpenVisit\Settings;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class GlobalsListener
{
    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(GlobalsInitializedEvent::EVENT_HANDLE, $this->onGlobalsInitialized(...));
    }

    public function onGlobalsInitialized(GlobalsInitializedEvent $event): void
    {
        $service = $event->getGlobalsService();
        $section = xl(Settings::SECTION);
        $service->createSection($section);

        foreach (Settings::definitions() as $key => $definition) {
            $service->appendToSection($section, $key, new GlobalSetting(
                xl($definition['label']),
                $definition['type'],
                $definition['default'],
                xl($definition['description']),
            ));
        }
    }
}
