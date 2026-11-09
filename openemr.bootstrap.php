<?php

/**
 * OpenVisit module bootstrap. OpenEMR includes this file on every request
 * once the module is enabled, so it only wires listeners.
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit;

/** @var \OpenEMR\Core\ModulesClassLoader $classLoader */
$classLoader->registerNamespaceIfNotExists(
    'OpenVisit\\OpenEMR\\Modules\\OpenVisit\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src',
);

// On an older core the module stays dormant instead of breaking core pages.
if (!Bootstrap::coreSupported()) {
    return;
}

/** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
(new Bootstrap($eventDispatcher))->subscribeToEvents();
