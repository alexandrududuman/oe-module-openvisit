<?php

/**
 * Called by the OpenEMR Module Manager on install, enable, disable and the
 * other lifecycle actions. Must live in the global namespace (core requirement).
 *
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        return match ($methodName) {
            'install' => $this->install($modId, $currentActionStatus),
            'enable' => $this->enable($modId, $currentActionStatus),
            'disable' => $this->disable($modId, $currentActionStatus),
            default => $currentActionStatus,
        };
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenVisit\\OpenEMR\\Modules\\OpenVisit\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    // Keep the config button visible before the module is enabled.
    private function install(int|string $modId, string $status): string
    {
        $this->setModuleState($modId, 0, 1);
        return $status;
    }

    private function enable(int|string $modId, string $status): string
    {
        $this->setModuleState($modId, 1, 0);
        $this->setBackgroundService(true);
        return $status;
    }

    private function disable(int|string $modId, string $status): string
    {
        $this->setModuleState($modId, 0, 1);
        $this->setBackgroundService(false);
        return $status;
    }

    private function setBackgroundService(bool $active): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `background_services` SET `active` = ? WHERE `name` = ?',
            [$active ? 1 : 0, 'OpenVisitCleanup'],
        );
    }

    private function setModuleState(int|string $modId, int $active, int $uiActive): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `modules` SET `mod_active` = ?, `mod_ui_active` = ? WHERE `mod_id` = ? OR `mod_directory` = ?',
            [$active, $uiActive, $modId, $modId],
        );
    }
}
