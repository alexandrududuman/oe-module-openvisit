<?php

/**
 * Shown inside the Module Manager iframe: the setup self-check. Runs even while the module is
 * disabled, so it registers the module namespace itself.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__FILE__, 4) . '/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck\CheckResult;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck\CurlProber;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\SelfCheck\SelfCheck;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo xlt('Not authorized');
    exit;
}

(new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir()))
    ->registerNamespaceIfNotExists('OpenVisit\\OpenEMR\\Modules\\OpenVisit\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$results = (new SelfCheck(ModuleConfig::fromGlobals(), new CurlProber()))->run();
$icons = [CheckResult::PASS => 'fa-check-circle text-success', CheckResult::WARN => 'fa-exclamation-triangle text-warning', CheckResult::FAIL => 'fa-times-circle text-danger'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OpenVisit'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body>
<div class="container mt-3">
    <h3><?php echo xlt('OpenVisit Telehealth'); ?> <small class="text-muted"><?php echo xlt('setup check'); ?></small></h3>
    <p><?php echo xlt('Settings live under Administration, Globals, in the OpenVisit tab. This page tells you what still needs attention.'); ?></p>
    <?php if (SelfCheck::allPassed($results)) : ?>
        <div class="alert alert-success" id="openvisit-check-summary" data-status="pass"><?php echo xlt('Ready for video visits.'); ?></div>
    <?php else : ?>
        <div class="alert alert-danger" id="openvisit-check-summary" data-status="fail"><?php echo xlt('Not ready yet. Fix the items marked in red.'); ?></div>
    <?php endif; ?>
    <ul class="list-group" id="openvisit-checks">
        <?php foreach ($results as $result) : ?>
            <li class="list-group-item" data-check="<?php echo attr($result->id); ?>" data-status="<?php echo attr($result->status); ?>">
                <i class="fa <?php echo attr($icons[$result->status] ?? ''); ?> mr-2" aria-hidden="true"></i>
                <strong><?php echo text($result->title); ?></strong>
                <span class="ml-1"><?php echo text($result->detail); ?></span>
                <?php if ($result->fix !== '') : ?><div class="small text-muted ml-4"><?php echo text($result->fix); ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="mt-3"><a class="btn btn-secondary btn-sm" href="moduleConfig.php"><?php echo xlt('Run the check again'); ?></a></p>
</div>
</body>
</html>
