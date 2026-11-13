<?php
declare(strict_types=1);

namespace JobCenter\Tests;

use JobCenter\PendingStore;
use PHPUnit\Framework\TestCase;

final class PendingStoreTest extends TestCase
{
    public function testSaveListRemove(): void
    {
        $dir = sys_get_temp_dir() . '/jc-pending-' . bin2hex(random_bytes(4));
        $store = new PendingStore($dir);
        $store->save('att_1', 'task.completed', [
            'attempt_id' => 'att_1',
            'lease_token' => 'L',
            'finished_at' => '2026-01-01T00:00:00.000Z',
            'result' => new \stdClass(),
        ]);
        $list = $store->list();
        self::assertCount(1, $list);
        self::assertSame('att_1', $list[0]['attempt_id']);
        self::assertSame('task.completed', $list[0]['type']);
        $store->remove('att_1');
        self::assertCount(0, $store->list());
    }

    public function testSanitizesFilename(): void
    {
        $dir = sys_get_temp_dir() . '/jc-pending-' . bin2hex(random_bytes(4));
        $store = new PendingStore($dir);
        $store->save('../evil/id', 'task.failed', ['attempt_id' => '../evil/id']);
        $files = glob($dir . '/*.json') ?: [];
        self::assertCount(1, $files);
        self::assertStringNotContainsString('..', basename($files[0]));
    }
}
