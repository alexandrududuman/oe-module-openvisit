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

use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Events\Core\TemplatePageEvent;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Loads the floating visit window into OpenEMR's main tabs page, so a call keeps running while
 * the provider moves between tabs. Only module files are added; Jitsi stays on module pages.
 */
final class DockListener
{
    private const MAIN_PAGE = '/interface/main/tabs/main.php';

    /** @param \Closure(): bool $enabled whether the current user runs visits and the module is set up */
    public function __construct(private readonly \Closure $enabled) {}

    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(ScriptFilterEvent::EVENT_NAME, $this->onScripts(...));
        $dispatcher->addListener(StyleFilterEvent::EVENT_NAME, $this->onStyles(...));
    }

    public function onScripts(ScriptFilterEvent $event): void
    {
        if ($this->isMainPage($event)) {
            $event->setScripts([...$event->getScripts(), Bootstrap::assetPath() . '/js/dock.js']);
        }
    }

    public function onStyles(StyleFilterEvent $event): void
    {
        if ($this->isMainPage($event)) {
            $event->setStyles([...$event->getStyles(), Bootstrap::assetPath() . '/css/dock.css']);
        }
    }

    private function isMainPage(TemplatePageEvent $event): bool
    {
        $script = $event->getContextArgument(ScriptFilterEvent::CONTEXT_ARGUMENT_SCRIPT_NAME);
        return is_string($script) && str_ends_with($script, self::MAIN_PAGE) && ($this->enabled)();
    }
}
