<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Listener;

use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** Adds the "Start video visit" button to saved appointments in the telehealth category. */
final class AppointmentListener
{
    /** @param \Closure(): ClinicalGateway $gateway built lazily, the dialog is the only place that needs it */
    public function __construct(private readonly \Closure $gateway) {}

    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(AppointmentRenderEvent::RENDER_BELOW_PATIENT, $this->onRenderBelowPatient(...));
    }

    public function onRenderBelowPatient(AppointmentRenderEvent $event): void
    {
        $appointment = $event->getAppt();
        $eid = Scalars::int($appointment['pc_eid'] ?? null);
        $categoryId = Scalars::int($appointment['pc_catid'] ?? null);
        if ($eid <= 0 || $categoryId <= 0 || Scalars::int($appointment['pc_pid'] ?? null) <= 0) {
            return;
        }
        if ($categoryId !== ($this->gateway)()->telehealthCategoryId()) {
            return;
        }

        $url = Bootstrap::MODULE_PATH . '/public/visit.php?eid=' . $eid;
        echo '<div class="mt-2" id="openvisit-start">'
            . '<a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="' . attr($url) . '">'
            . '<i class="fa fa-video mr-1" aria-hidden="true"></i>' . xlt('Start video visit')
            . '</a></div>';
    }
}
