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

use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\JoinWindow;
use PHPUnit\Framework\TestCase;

final class JoinWindowTest extends TestCase
{
    public function testWindowSurroundsTheAppointment(): void
    {
        $appointment = new Appointment(1, 1, 1, 1, '2026-11-09', '10:30:00', 1800, 'A', 'B', 'C', 'D');
        $window = JoinWindow::forAppointment($appointment, 30, 2);

        self::assertSame('2026-11-09 10:00:00', $window->opensAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-09 13:00:00', $window->closesAt->format('Y-m-d H:i:s'));
        self::assertTrue($window->isBefore(new \DateTimeImmutable('2026-11-09 09:59:59')));
        self::assertTrue($window->contains(new \DateTimeImmutable('2026-11-09 10:00:00')));
        self::assertTrue($window->contains(new \DateTimeImmutable('2026-11-09 12:59:59')));
        self::assertTrue($window->isAfter(new \DateTimeImmutable('2026-11-09 13:00:00')));
    }
}
