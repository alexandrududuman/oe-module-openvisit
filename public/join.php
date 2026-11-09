<?php

/**
 * Public entry for patients and guests. No OpenEMR login: the link is the credential.
 * The site must be in the URL because there is no session yet.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

$bootstrap = new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
echo $bootstrap->joinController()->page(
    trim((string) $request->query->get('t', '')),
    (string) ($request->getClientIp() ?? 'unknown'),
);
