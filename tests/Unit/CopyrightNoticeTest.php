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

use PHPUnit\Framework\TestCase;

/** Every source file names its author, so a copy keeps the notice the GPL requires it to keep. */
final class CopyrightNoticeTest extends TestCase
{
    private const NOTICE = '/Copyright \(c\) \d{4}(-\d{4})? Alexandru Duduman/';
    private const EXTENSIONS = ['php', 'js', 'css', 'twig', 'sh'];
    private const DIRECTORIES = ['src', 'public', 'templates', 'background', 'docker', 'tests'];
    private const SKIPPED = ['tests/e2e/results', 'tests/e2e/report'];

    public function testEverySourceFileCarriesTheCopyrightNotice(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach ($this->sourceFiles($root) as $file) {
            $head = (string) file_get_contents($file, false, null, 0, 1024);
            if (preg_match(self::NOTICE, $head) !== 1) {
                $missing[] = substr($file, strlen($root) + 1);
            }
        }
        self::assertSame([], $missing, 'Add "Copyright (c) <year> Alexandru Duduman" to the header of these files');
    }

    /** @return list<string> */
    private function sourceFiles(string $root): array
    {
        $files = glob($root . '/{*.php,*.js}', GLOB_BRACE) ?: [];
        foreach (self::DIRECTORIES as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file instanceof \SplFileInfo ? $file->getPathname() : '';
                $relative = substr($path, strlen($root) + 1);
                if (!in_array(pathinfo($path, PATHINFO_EXTENSION), self::EXTENSIONS, true)) {
                    continue;
                }
                foreach (self::SKIPPED as $skipped) {
                    if (str_starts_with($relative, $skipped)) {
                        continue 2;
                    }
                }
                $files[] = $path;
            }
        }
        return $files;
    }
}
