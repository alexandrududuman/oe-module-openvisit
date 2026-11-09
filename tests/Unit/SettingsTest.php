<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use OpenEMR\Services\Globals\GlobalSetting;
use OpenVisit\OpenEMR\Modules\OpenVisit\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testEveryKeyIsNamespacedToTheModule(): void
    {
        foreach (array_keys(Settings::definitions()) as $key) {
            self::assertStringStartsWith('openvisit_', $key);
        }
    }

    public function testEveryDefinitionHasLabelAndDescription(): void
    {
        foreach (Settings::definitions() as $key => $definition) {
            self::assertNotSame('', $definition['label'], $key);
            self::assertNotSame('', $definition['description'], $key);
        }
    }

    public function testTypesAreCoreTypes(): void
    {
        $allowed = [
            GlobalSetting::DATA_TYPE_TEXT,
            GlobalSetting::DATA_TYPE_NUMBER,
            GlobalSetting::DATA_TYPE_BOOL,
            GlobalSetting::DATA_TYPE_ENCRYPTED,
            GlobalSetting::DATA_TYPE_DEFAULT_VISIT_CATEGORY,
        ];
        foreach (Settings::definitions() as $key => $definition) {
            self::assertContains($definition['type'], $allowed, $key);
        }
    }

    public function testSecretIsStoredEncrypted(): void
    {
        self::assertSame(
            GlobalSetting::DATA_TYPE_ENCRYPTED,
            Settings::definitions()[Settings::JWT_APP_SECRET]['type'],
        );
    }

    public function testOptionalBehavioursAreOffByDefault(): void
    {
        $definitions = Settings::definitions();
        self::assertSame(0, $definitions[Settings::SEND_EMAIL]['default']);
        self::assertSame(0, $definitions[Settings::ATTACH_VISIT_FORM]['default']);
        self::assertSame(0, $definitions[Settings::SHOW_PATIENT_LAST_INITIAL]['default']);
    }
}
