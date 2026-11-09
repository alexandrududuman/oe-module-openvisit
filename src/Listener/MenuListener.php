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
    private const PARENT_MENU_ID = 'modimg';

    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(MenuEvent::MENU_UPDATE, $this->onMenuUpdate(...));
    }

    public function onMenuUpdate(MenuEvent $event): void
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'ovs';
        $item->menu_id = 'openvisit0';
        $item->label = xlt('OpenVisit');
        $item->url = Bootstrap::MODULE_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = ['patients', 'appt'];
        $item->global_req = [];

        $menu = $event->getMenu();
        if (!is_array($menu)) {
            return;
        }
        foreach ($menu as $entry) {
            if (!$entry instanceof \stdClass || ($entry->menu_id ?? '') !== self::PARENT_MENU_ID) {
                continue;
            }
            $children = is_array($entry->children ?? null) ? $entry->children : [];
            $children[] = $item;
            $entry->children = $children;
            $event->setMenu($menu);
            return;
        }

        $menu[] = $item;
        $event->setMenu($menu);
    }
}
