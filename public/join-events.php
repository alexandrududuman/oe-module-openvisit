<?php

/**
 * Join and leave events from the public join page. The link itself authenticates the caller.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();
if (!$request->isMethod('POST')) {
    (new JsonResponse(['ok' => false, 'error' => 'POST required'], 405))->send();
    exit;
}

$bootstrap = new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
$result = $bootstrap->joinController()->event(
    trim((string) $request->request->get('t', '')),
    (string) $request->request->get('action', ''),
    $request->request->getInt('participant_id'),
    (string) $request->headers->get('User-Agent', ''),
);
(new JsonResponse($result, $result['ok'] ? 200 : 400))->send();
