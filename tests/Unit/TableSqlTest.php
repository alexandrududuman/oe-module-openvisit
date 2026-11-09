<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Module Manager re-runs table.sql on every install. Each CREATE TABLE must
 * sit inside an #IfNotTable block naming that table, or a second install fails.
 */
final class TableSqlTest extends TestCase
{
    public function testEveryStatementIsGuardedByAMatchingDirective(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/sql/table.sql');
        self::assertIsString($sql);

        $guard = null;
        foreach (explode("\n", $sql) as $number => $line) {
            $where = 'line ' . ($number + 1);
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
        }
        self::assertNull($guard, 'file ends inside an open directive block');
    }

    public function testTablesCarryTheModulePrefix(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/table.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)`/', $sql, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $table) {
            self::assertStringStartsWith('openvisit_', $table);
        }
    }
}
