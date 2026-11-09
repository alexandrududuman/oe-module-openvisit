<?php

/**
 * @package   OpenVisit
 * @link      https://github.com/alexandrududuman/oe-module-openvisit
 * @author    Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @copyright Copyright (c) 2026 Alexandru Duduman <alexandru@thepenguindeveloper.com>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Service;

/** Unguessable Jitsi room names: 22 base-36 characters give about 113 bits of entropy. */
final class RoomNameGenerator
{
    public const PREFIX = 'ov-';
    public const RANDOM_LENGTH = 22;
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function generate(): string
    {
        $name = self::PREFIX;
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $name .= self::ALPHABET[random_int(0, $max)];
        }
        return $name;
    }
}
