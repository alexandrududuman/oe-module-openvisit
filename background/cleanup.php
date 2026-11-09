<?php

/**
 * Entry point for the OpenVisitCleanup background service. OpenEMR's runner requires this
 * file and calls the function named in the background_services row.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;

function openvisit_background_cleanup(): void
{
    $fileroot = OEGlobalsBag::getInstance()->get('fileroot');
    $loader = new ModulesClassLoader(is_string($fileroot) ? $fileroot : dirname(__DIR__, 5));
    $loader->registerNamespaceIfNotExists('OpenVisit\\OpenEMR\\Modules\\OpenVisit\\', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src');
    if (!Bootstrap::coreSupported()) {
        return;
    }
    $bootstrap = new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
    $result = $bootstrap->cleanup()->run();
    $recordings = $bootstrap->recordings()->importReady();
    error_log(sprintf(
        'OpenVisit cleanup: %d visits closed, %d tokens purged, %d attempts purged, %d recordings filed',
        $result['closedVisits'],
        $result['purgedTokens'],
        $result['purgedAttempts'],
        $recordings,
    ));
}
