<?php

/**
 * Staff player for a visit recording, and the video stream behind it. Recordings live in the
 * site documents folder, which the web server never serves directly.
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
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

if (!AclMain::aclCheckCore('patients', 'appt')) {
    http_response_code(403);
    echo xlt('Not authorized');
    exit;
}

$request = Request::createFromGlobals();
$userId = Scalars::int(SessionWrapperFactory::getInstance()->getActiveSession()->get('authUserID'));
$controller = (new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher()))->visitController();
$recordingId = $request->query->getInt('id');

if ($request->query->getBoolean('stream')) {
    $path = $controller->recordingFile($recordingId, $userId);
    if ($path === null) {
        http_response_code(404);
        exit;
    }
    // A video is fetched in many range requests; none of them should hold the session lock.
    session_write_close();
    $response = new BinaryFileResponse($path, 200, [
        'Content-Type' => 'video/mp4',
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
    ], false, ResponseHeaderBag::DISPOSITION_INLINE, false, false);
    $response->prepare($request);
    $response->send();
    exit;
}

echo $controller->recordingPage($recordingId, $userId);
