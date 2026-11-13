<?php
declare(strict_types=1);

namespace JobCenter\Tests;

use JobCenter\AttemptLogBuf;
use PHPUnit\Framework\TestCase;

final class AttemptLogBufTest extends TestCase
{
    public function testFlushOnFullThenError(): void
    {
        $frames = [];
        $buf = new AttemptLogBuf('a1', 'lease', function (array $msg) use (&$frames): void {
            $frames[] = $msg;
        });
        for ($i = 0; $i < AttemptLogBuf::CAPACITY; $i++) {
            $buf->append('INFO', 'info');
        }
        $buf->append('ERROR', 'boom');
        self::assertCount(2, $frames);
        self::assertSame(0, $frames[0]['dropped']);
        $hasError = false;
        foreach ($frames[1]['entries'] as $e) {
            if ($e['level'] === 'ERROR') {
                $hasError = true;
            }
        }
        self::assertTrue($hasError);
    }

    public function testDropPrefersInfo(): void
    {
        $buf = new AttemptLogBuf('a1', 'lease', static function (): void {});
        $buf->setEntriesForTest([
            ['seq' => 1, 'level' => 'INFO', 'message' => 'i'],
            ['seq' => 2, 'level' => 'WARN', 'message' => 'w'],
            ['seq' => 3, 'level' => 'ERROR', 'message' => 'e'],
        ], 4);
        self::assertTrue($buf->dropOne());
        [$di, $dw, $de] = $buf->dropCounts();
        self::assertSame([1, 0, 0], [$di, $dw, $de]);
        self::assertTrue($buf->dropOne());
        [, $dw, $de] = $buf->dropCounts();
        self::assertSame(1, $dw);
        self::assertSame(0, $de);
        self::assertTrue($buf->dropOne());
        [, , $de] = $buf->dropCounts();
        self::assertSame(1, $de);
    }

    public function testSyntheticWarn(): void
    {
        $frames = [];
        $buf = new AttemptLogBuf('a1', 'lease', function (array $msg) use (&$frames): void {
            $frames[] = $msg;
        });
        $buf->setEntriesForTest([['seq' => 1, 'level' => 'ERROR', 'message' => 'e']], 2);
        $buf->setDropsForTest(0, 1, 2);
        $buf->flush();
        self::assertCount(1, $frames);
        $f = $frames[0];
        self::assertSame(2, $f['dropped_error']);
        self::assertSame(1, $f['dropped_warn']);
        $found = false;
        foreach ($f['entries'] as $e) {
            if ($e['level'] === 'WARN') {
                $found = true;
            }
        }
        self::assertTrue($found);
    }
}
