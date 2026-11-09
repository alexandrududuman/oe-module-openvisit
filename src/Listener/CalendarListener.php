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

use OpenEMR\Events\Appointments\CalendarUserGetEventsFilter;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** Marks telehealth appointments in the staff calendar with a camera icon. */
final class CalendarListener
{
    public const EVENT_CLASS = 'openvisit_telehealth';

    /** @param \Closure(): ClinicalGateway $gateway */
    public function __construct(private readonly \Closure $gateway) {}

    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(CalendarUserGetEventsFilter::EVENT_NAME, $this->onCalendarEvents(...));
        $dispatcher->addListener(StyleFilterEvent::EVENT_NAME, $this->onStyles(...));
    }

    public function onCalendarEvents(CalendarUserGetEventsFilter $event): void
    {
        $categoryId = ($this->gateway)()->telehealthCategoryId();
        if ($categoryId === null) {
            return;
        }
        $days = $event->getEventsByDays();
        foreach ($days as $day => $events) {
            if (!is_array($events)) {
                continue;
            }
            foreach ($events as $index => $item) {
                if (!is_array($item) || Scalars::int($item['catid'] ?? null) !== $categoryId) {
                    continue;
                }
                $item['eventViewClass'] = trim(Scalars::string($item['eventViewClass'] ?? null, 'event_appointment') . ' ' . self::EVENT_CLASS);
                $events[$index] = $item;
            }
            $days[$day] = $events;
        }
        $event->setEventsByDays($days);
    }

    public function onStyles(StyleFilterEvent $event): void
    {
        if (!str_contains($event->getPageName(), '/main/calendar/')) {
            return;
        }
        $styles = $event->getStyles();
        $styles[] = Bootstrap::assetPath() . '/css/calendar.css';
        $event->setStyles($styles);
    }
}
