<?php

/**
 * JSON actions for the room page. POST only, CSRF protected.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();
$session = SessionWrapperFactory::getInstance()->getActiveSession();

$respond = static function (array $body, int $status): never {
    (new JsonResponse($body, $status))->send();
    exit;
};

if (!$request->isMethod('POST')) {
    $respond(['ok' => false, 'error' => 'POST required'], 405);
}
if (!AclMain::aclCheckCore('patients', 'appt')) {
    $respond(['ok' => false, 'error' => 'Not authorized'], 403);
}
if (!CsrfUtils::verifyCsrfToken((string) $request->request->get('csrf_token_form', ''), $session)) {
    $respond(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
}

$bootstrap = new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
$action = (string) $request->request->get('action', '');

if ($action === 'end') {
    $result = $bootstrap->visitController()->end($request->request->getInt('session_id'));
    $respond($result, $result['ok'] ? 200 : 404);
}

$respond(['ok' => false, 'error' => 'Unknown action'], 400);
