<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Module Manager re-runs these files on install and upgrade. Every statement must sit
 * inside a directive block naming its table, or a second run fails.
 */
final class TableSqlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function sqlFiles(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/sql/*.sql') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('sqlFiles')]
    public function testEveryStatementIsGuardedByAMatchingDirective(string $file): void
    {
        $sql = file_get_contents($file);
        self::assertIsString($sql);

        $guard = null;
        foreach (explode("\n", $sql) as $number => $line) {
            $where = basename($file) . ' line ' . ($number + 1);
            if (preg_match('/^#IfNotTable\s+(\S+)\s*$/', $line, $m) === 1) {
                self::assertNull($guard, "nested directive at $where");
                $guard = ['table' => $m[1], 'kind' => 'table'];
                continue;
            }
            if (preg_match('/^#IfNotRow\s+(\S+)\s+\S+\s+\S+\s*$/', $line, $m) === 1) {
                self::assertNull($guard, "nested directive at $where");
                $guard = ['table' => $m[1], 'kind' => 'row'];
                continue;
            }
            if (preg_match('/^#IfMissingColumn\s+(\S+)\s+\S+\s*$/', $line, $m) === 1) {
                self::assertNull($guard, "nested directive at $where");
                $guard = ['table' => $m[1], 'kind' => 'column'];
                continue;
            }
            if ($line === '#EndIf') {
                self::assertNotNull($guard, "#EndIf without a directive at $where");
                $guard = null;
                continue;
            }
            if (preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `?(\w+)`?/i', $line, $m) === 1) {
                self::assertSame(['table' => $m[1], 'kind' => 'table'], $guard, "CREATE TABLE {$m[1]} is not guarded at $where");
            }
            if (preg_match('/^INSERT INTO `?(\w+)`?/i', $line, $m) === 1) {
                self::assertSame(['table' => $m[1], 'kind' => 'row'], $guard, "INSERT INTO {$m[1]} is not guarded by #IfNotRow at $where");
            }
            if (preg_match('/^ALTER TABLE `?(\w+)`? ADD COLUMN/i', $line, $m) === 1) {
                self::assertSame(['table' => $m[1], 'kind' => 'column'], $guard, "ALTER TABLE {$m[1]} is not guarded by #IfMissingColumn at $where");
            }
        }
        self::assertNull($guard, 'file ends inside an open directive block');
    }

    /**
     * Core stores the module's SQL version only when it finds sql/install.sql or a root table.sql
     * next to version.php; without it the Module Manager never offers the upgrade scripts.
     */
    public function testCoreCanReadTheSqlVersionAndFindTheInstallScript(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/version.php');
        self::assertFileExists($root . '/sql/install.sql');
        self::assertFileDoesNotExist($root . '/sql/table.sql', 'core would run sql/table.sql but never record the SQL version');
    }

    public function testUpgradeFilesFollowTheCoreNamingPattern(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/sql/*_upgrade.sql') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertMatchesRegularExpression('/^\d+_\d+_\d+-to-\d+_\d+_\d+_upgrade\.sql$/', basename($file));
        }
    }

    public function testTablesCarryTheModulePrefix(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/install.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)`/', $sql, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $table) {
            self::assertStringStartsWith('openvisit_', $table);
        }
    }
}
