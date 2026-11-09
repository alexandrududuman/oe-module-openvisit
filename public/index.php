<?php

/**
 * Staff landing page: today's telehealth appointments and their visit state.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;

if (!AclMain::aclCheckCore('patients', 'appt')) {
    http_response_code(403);
    echo xlt('Not authorized');
    exit;
}

$bootstrap = new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
$session = SessionWrapperFactory::getInstance()->getActiveSession();
echo $bootstrap->visitController()->today(AclMain::aclCheckCore('admin', 'super'), CsrfUtils::collectCsrfToken($session));
