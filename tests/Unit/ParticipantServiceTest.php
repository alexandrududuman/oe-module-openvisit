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

use OpenVisit\OpenEMR\Modules\OpenVisit\Service\ParticipantService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\FakeGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryParticipantStore;
use PHPUnit\Framework\TestCase;

final class ParticipantServiceTest extends TestCase
{
    public function testClosingAVisitClosesOnlyItsOpenRowsAndKeepsEarlierLeaveTimes(): void
    {
        $store = new InMemoryParticipantStore();
        $gateway = new FakeGateway();
        $service = new ParticipantService($store, $gateway);
        $provider = $service->joined(1, 'provider', 'Donna Lee', null, 'ua');
        $patient = $service->joined(1, 'patient', 'Patient', 5, 'ua');
        $other = $service->joined(2, 'provider', 'Donna Lee', null, 'ua');
        $gateway->clock = $gateway->clock->modify('+5 minutes');
        $service->left($patient->id, 1);
        $gateway->clock = $gateway->clock->modify('+10 minutes');

        $service->closeAll(1);

        self::assertSame('2026-11-09 10:15:00', $store->rows[$provider->id]->leftAt);
        self::assertSame('2026-11-09 10:05:00', $store->rows[$patient->id]->leftAt);
        self::assertNull($store->rows[$other->id]->leftAt);
    }
}
