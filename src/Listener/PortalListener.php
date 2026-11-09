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

use OpenEMR\Events\Core\TwigEnvironmentEvent;
use OpenEMR\Events\PatientPortal\AppointmentFilterEvent;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Loader\FilesystemLoader;

/**
 * Patient portal: a Join button on telehealth appointments. The portal template only prints
 * four fields, so the module overrides it through the Twig loader.
 */
final class PortalListener
{
    /** @param \Closure(): ClinicalGateway $gateway */
    public function __construct(private readonly \Closure $gateway) {}

    public function subscribe(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->addListener(AppointmentFilterEvent::EVENT_NAME, $this->onAppointment(...));
        $dispatcher->addListener(TwigEnvironmentEvent::EVENT_CREATED, $this->onTwigCreated(...));
    }

    public function onAppointment(AppointmentFilterEvent $event): void
    {
        $record = Scalars::stringKeyed($event->getDbRecord());
        $eid = Scalars::int($record['pc_eid'] ?? null);
        $categoryId = Scalars::int($record['pc_catid'] ?? null);
        if ($eid <= 0 || $categoryId !== ($this->gateway)()->telehealthCategoryId()) {
            return;
        }
        $appointment = $event->getAppointment();
        $appointment['openvisitJoinUrl'] = Bootstrap::MODULE_PATH . '/public/portal-join.php?eid=' . $eid;
        $event->setAppointment($appointment);
    }

    public function onTwigCreated(TwigEnvironmentEvent $event): void
    {
        $loader = $event->getTwigEnvironment()->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            return;
        }
        $overrides = self::overridesPath();
        if (in_array($overrides, $loader->getPaths(), true) || in_array(dirname($overrides), $loader->getPaths(), true)) {
            return;
        }
        $loader->prependPath($overrides);
    }

    public static function overridesPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'overrides';
    }
}
