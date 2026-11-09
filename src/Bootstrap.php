<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit;

use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Controller\VisitController;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\ClinicalGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Gateway\OpenEmrGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Listener\AppointmentListener;
use OpenVisit\OpenEMR\Modules\OpenVisit\Listener\GlobalsListener;
use OpenVisit\OpenEMR\Modules\OpenVisit\Listener\MenuListener;
use OpenVisit\OpenEMR\Modules\OpenVisit\Repository\SqlSessionStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RoomNameGenerator;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\VisitService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/** Wiring only. Objects are built on demand so the per-request bootstrap stays cheap. */
final class Bootstrap
{
    public const MODULE_DIRECTORY = 'oe-module-openvisit';
    public const MODULE_PATH = '/interface/modules/custom_modules/' . self::MODULE_DIRECTORY;

    private ?ClinicalGateway $gateway = null;

    public function __construct(private readonly EventDispatcherInterface $dispatcher) {}

    public function subscribeToEvents(): void
    {
        (new GlobalsListener())->subscribe($this->dispatcher);
        (new MenuListener())->subscribe($this->dispatcher);
        (new AppointmentListener($this->gateway(...)))->subscribe($this->dispatcher);
    }

    public function config(): ModuleConfig
    {
        return ModuleConfig::fromGlobals();
    }

    public function gateway(): ClinicalGateway
    {
        return $this->gateway ??= new OpenEmrGateway($this->config());
    }

    public function visits(): VisitService
    {
        return new VisitService(new SqlSessionStore(), $this->gateway(), new RoomNameGenerator());
    }

    public function visitController(): VisitController
    {
        return new VisitController($this->config(), $this->gateway(), $this->visits(), new SqlSessionStore(), $this->twig());
    }

    public function twig(): Environment
    {
        $templates = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates';
        return (new TwigContainer($templates, OEGlobalsBag::getInstance()->getKernel()))->getTwig();
    }

    public static function assetPath(): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_PATH . '/public/assets';
    }
}
