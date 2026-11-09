<?php

/**
 * Shown inside the Module Manager iframe. Runs even while the module is
 * disabled, so it must not depend on the module's own classes.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__FILE__, 4) . '/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo xlt('Not authorized');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OpenVisit'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body>
<div class="container mt-3">
    <h3><?php echo xlt('OpenVisit Telehealth'); ?></h3>
    <p><?php echo xlt('Settings live under Administration, Globals, in the OpenVisit tab.'); ?></p>
    <p><?php echo xlt('The connection self-check for Jitsi, JWT and TURN arrives in a later release.'); ?></p>
</div>
</body>
</html>
