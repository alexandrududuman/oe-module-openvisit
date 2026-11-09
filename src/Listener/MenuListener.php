<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Listener;

use OpenEMR\Menu\MenuEvent;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class MenuListener
{
    private const AFTER_MENU_ID = 'cal0';

    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(MenuEvent::MENU_UPDATE, $this->onMenuUpdate(...));
    }

    /**
     * Adds a top-level "Telehealth" entry right after Calendar. The core "Modules"
     * menu is hidden from clinicians, so a child entry there would never be seen.
     */
    public function onMenuUpdate(MenuEvent $event): void
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'ovs';
        $item->menu_id = 'openvisit0';
        $item->label = xlt('Telehealth');
        $item->url = Bootstrap::MODULE_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = ['patients', 'appt'];
        $item->global_req = [];

        $menu = $event->getMenu();
        if (!is_array($menu)) {
            return;
        }
        $position = count($menu);
        foreach (array_values($menu) as $index => $entry) {
            if ($entry instanceof \stdClass && ($entry->menu_id ?? '') === self::AFTER_MENU_ID) {
                $position = $index + 1;
                break;
            }
        }
        array_splice($menu, $position, 0, [$item]);
        $event->setMenu($menu);
    }
}
