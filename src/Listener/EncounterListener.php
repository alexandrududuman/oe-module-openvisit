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

use OpenEMR\Events\Encounter\EncounterFormsListRenderEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** Shows the video visit of an encounter, with its recordings, at the top of the encounter page. */
final class EncounterListener
{
    /** @param \Closure(int): string $card renders the card for an encounter, empty when it had no video visit */
    public function __construct(private readonly \Closure $card) {}

    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(EncounterFormsListRenderEvent::EVENT_SECTION_RENDER_PRE, $this->onRender(...));
    }

    public function onRender(EncounterFormsListRenderEvent $event): void
    {
        $encounter = $event->getEncounter();
        if ($encounter !== null && $encounter > 0) {
            echo ($this->card)($encounter);
        }
    }
}
