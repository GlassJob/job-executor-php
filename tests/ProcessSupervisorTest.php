<?php
declare(strict_types=1);

namespace JobCenter\Tests;

use JobCenter\Json;
use JobCenter\ProcessSupervisor;
use JobCenter\RunningAttempt;
use PHPUnit\Framework\TestCase;

final class ProcessSupervisorTest extends TestCase
{
    private function supervisor(): ProcessSupervisor
    {
        $root = dirname(__DIR__);
        return new ProcessSupervisor(PHP_BINARY, $root . '/bin/jobcenter-runner', $root . '/src/Jobs/registry.php', $root);
    }

    /** @param array<string,mixed> $extra */
    private function payload(string $job, array $extra = []): array
    {
        return array_merge([
            'execution_id' => 'ex_' . bin2hex(random_bytes(4)),
            'attempt_id' => 'att_' . bin2hex(random_bytes(6)),
            'attempt_number' => 1,
            'lease_token' => 'lease-1',
            'job_code' => $job,
            'params' => new \stdClass(),
            'state' => new \stdClass(),
            'state_version' => 0,
            'timeout_seconds' => 10,
            'deadline_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 seconds')->format('Y-m-d\TH:i:s.v\Z'),
            'trigger_type' => 'MANUAL',
        ], $extra);
    }

    private function runUntilDone(ProcessSupervisor $sup, string $attemptId, float $timeout = 8.0): RunningAttempt
    {
        $deadline = microtime(true) + $timeout;
        $got = null;
        while (microtime(true) < $deadline) {
            $finished = $sup->pump();
            foreach ($finished as $rt) {
                if ($rt->attemptId === $attemptId) {
                    $got = $rt;
                    break 2;
                }
            }
            usleep(20_000);
        }
        self::assertNotNull($got, 'child did not finish in time');
        return $got;
    }

    public function testHelloCompletes(): void
    {
        $sup = $this->supervisor();
        $p = $this->payload('demo.hello', ['params' => ['name' => 'php']]);
        $sup->start($p['attempt_id'], $p);
        $rt = $this->runUntilDone($sup, $p['attempt_id']);
        self::assertSame('done', $rt->childTerminal);
        self::assertSame(['hello' => 'php'], $rt->result);
        $types = array_column($rt->frames, 'type');
        self::assertContains('progress', $types);
        self::assertContains('log', $types);
        self::assertContains('done', $types);
    }

    public function testFailIsNonRetryable(): void
    {
        $sup = $this->supervisor();
        $p = $this->payload('demo.fail');
        $sup->start($p['attempt_id'], $p);
        $rt = $this->runUntilDone($sup, $p['attempt_id']);
        self::assertSame('error', $rt->childTerminal);
        self::assertSame('DEMO_FAIL', $rt->error['code'] ?? null);
        self::assertFalse($rt->error['retryable'] ?? true);
    }

    public function testCounterCheckpointAndNextState(): void
    {
        $sup = $this->supervisor();
        $p = $this->payload('demo.counter', [
            'params' => ['step' => 3],
            'state' => ['count' => 10],
            'state_version' => 4,
        ]);
        $sup->start($p['attempt_id'], $p);
        $rt = $this->runUntilDone($sup, $p['attempt_id']);
        self::assertSame('done', $rt->childTerminal);
        self::assertSame(13, $rt->result['count'] ?? null);
        self::assertSame(3, $rt->result['step'] ?? null);
        self::assertSame(4, $rt->result['state_version'] ?? null);
        self::assertSame(['count' => 13, 'last' => 'completed'], $rt->nextState);
        $cp = null;
        foreach ($rt->frames as $f) {
            if (($f['type'] ?? '') === 'checkpoint') {
                $cp = $f['state'] ?? null;
            }
        }
        self::assertSame(['count' => 13, 'last' => 'checkpoint'], $cp);
    }

    public function testLogsFloodEmitsManyLogFrames(): void
    {
        $sup = $this->supervisor();
        $p = $this->payload('demo.logs');
        $sup->start($p['attempt_id'], $p);
        $rt = $this->runUntilDone($sup, $p['attempt_id']);
        self::assertSame('done', $rt->childTerminal);
        $logs = array_values(array_filter($rt->frames, static fn ($f) => ($f['type'] ?? '') === 'log'));
        self::assertGreaterThan(100, count($logs));
        $levels = array_column($logs, 'level');
        self::assertContains('WARN', $levels);
        self::assertContains('ERROR', $levels);
    }

    public function testCancelDuringSleep(): void
    {
        $sup = $this->supervisor();
        $p = $this->payload('demo.sleep', [
            'params' => ['seconds' => 8],
            'timeout_seconds' => 15,
            'deadline_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+15 seconds')->format('Y-m-d\TH:i:s.v\Z'),
        ]);
        $sup->start($p['attempt_id'], $p);
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline && $sup->get($p['attempt_id']) !== null) {
            $sup->pump();
            usleep(20_000);
        }
        self::assertNotNull($sup->get($p['attempt_id']));
        self::assertTrue($sup->cancel($p['attempt_id']));
        $rt = $this->runUntilDone($sup, $p['attempt_id'], 8.0);
        self::assertSame('cancelled', $rt->childTerminal);
    }

    public function testTimeoutHandlerHonorsDeadline(): void
    {
        $sup = $this->supervisor();
        $p = $this->payload('demo.timeout', [
            'timeout_seconds' => 1,
            'deadline_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 seconds')->format('Y-m-d\TH:i:s.v\Z'),
        ]);
        $sup->start($p['attempt_id'], $p);
        $rt = $this->runUntilDone($sup, $p['attempt_id'], 6.0);
        self::assertSame('timeout', $rt->childTerminal);
    }

    public function testEmptyObjectStateIsPreserved(): void
    {
        $raw = Json::encode(new \stdClass(), true);
        self::assertSame('{}', $raw);
    }

    public function testUnknownJobErrors(): void
    {
        $sup = $this->supervisor();
        $p = $this->payload('demo.missing');
        $sup->start($p['attempt_id'], $p);
        $rt = $this->runUntilDone($sup, $p['attempt_id']);
        self::assertSame('error', $rt->childTerminal);
        self::assertSame('UNKNOWN_JOB', $rt->error['code'] ?? null);
    }
}
