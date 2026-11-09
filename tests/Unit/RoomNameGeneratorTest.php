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

use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RoomNameGenerator;
use PHPUnit\Framework\TestCase;

final class RoomNameGeneratorTest extends TestCase
{
    public function testShapeIsPrefixPlusLowercaseAlphanumerics(): void
    {
        $name = (new RoomNameGenerator())->generate();

        self::assertMatchesRegularExpression('/^ov-[a-z0-9]{22}$/', $name);
    }

    public function testNamesDoNotRepeat(): void
    {
        $generator = new RoomNameGenerator();
        $names = [];
        for ($i = 0; $i < 2000; $i++) {
            $names[$generator->generate()] = true;
        }

        self::assertCount(2000, $names);
    }
}
