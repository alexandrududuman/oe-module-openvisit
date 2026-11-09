<?php

/**
 * Portal patients click Join on their appointment card. The portal session proves who they
 * are; the module issues a regular join link for them and sends them to it.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

$skipLandingPageError = true;
require_once __DIR__ . '/../../../../../portal/verify_session.php';

use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Bootstrap;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinToken;
use OpenVisit\OpenEMR\Modules\OpenVisit\Support\Scalars;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();
$bootstrap = new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
$gateway = $bootstrap->gateway();
$patientId = Scalars::int($pid ?? null);
$appointment = $gateway->appointment($request->query->getInt('eid'));

if ($appointment === null || $patientId <= 0 || $appointment->pid !== $patientId) {
    http_response_code(404);
    echo xlt('This appointment is not yours or no longer exists.');
    exit;
}

$visit = $bootstrap->visits()->ensureSession($appointment);
$issued = $bootstrap->tokens()->issue($visit, $appointment, JoinToken::ROLE_PATIENT, 'portal', 0);
header('Location: ' . $bootstrap->tokens()->joinUrl($issued->plaintext));
exit;
