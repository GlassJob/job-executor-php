<?php
declare(strict_types=1);

namespace JobCenter;

final class AttemptLogBuf
{
    public const CAPACITY = 100;
    public const FLUSH_EVERY = 1.0;

    /** @var list<array<string,mixed>> */
    private array $entries = [];
    private int $nextSeq = 1;
    private int $dropInfo = 0;
    private int $dropWarn = 0;
    private int $dropError = 0;
    private bool $closed = false;

    /** @param callable(array<string,mixed>):void $publish */
    public function __construct(
        private string $attemptId,
        private string $leaseToken,
        private $publish,
    ) {
    }

    /** @param array<string,mixed>|null $fields */
    public function append(string $level, string $message, ?array $fields = null): void
    {
        if ($this->closed) {
            return;
        }
        $level = self::normalizeLevel($level);
        if (count($this->entries) >= self::CAPACITY) {
            $this->flushLocked();
        }
        while (count($this->entries) >= self::CAPACITY) {
            if (!$this->dropOne()) {
                break;
            }
        }
        $ent = [
            'seq' => $this->nextSeq,
            'level' => $level,
            'message' => $message,
            'ts' => Json::now(),
        ];
        if ($fields !== null && $fields !== []) {
            $ent['fields'] = $fields;
        }
        $this->nextSeq++;
        $this->entries[] = $ent;
        if ($level === 'ERROR') {
            $this->flushLocked();
        }
    }

    public function dropOne(): bool
    {
        if ($this->entries === []) {
            return false;
        }
        $best = -1;
        $bestPri = 99;
        foreach ($this->entries as $i => $e) {
            $p = self::dropPriority((string) $e['level']);
            if ($p < $bestPri) {
                $bestPri = $p;
                $best = $i;
            }
        }
        if ($best < 0) {
            return false;
        }
        $dropped = $this->entries[$best];
        array_splice($this->entries, $best, 1);
        switch (self::normalizeLevel((string) $dropped['level'])) {
            case 'WARN':
                $this->dropWarn++;
                break;
            case 'ERROR':
                $this->dropError++;
                break;
            default:
                $this->dropInfo++;
        }
        return true;
    }

    public function flush(): void
    {
        $this->flushLocked();
    }

    public function closeAndFlush(): void
    {
        $this->flushLocked();
        $this->closed = true;
    }

    public function flushLocked(): void
    {
        $di = $this->dropInfo;
        $dw = $this->dropWarn;
        $de = $this->dropError;
        if (($dw > 0 || $de > 0) && !$this->closed) {
            $this->entries[] = [
                'seq' => $this->nextSeq,
                'level' => 'WARN',
                'message' => 'log buffer overflow: dropped entries before flush',
                'fields' => [
                    'dropped_info' => $di,
                    'dropped_warn' => $dw,
                    'dropped_error' => $de,
                ],
                'ts' => Json::now(),
            ];
            $this->nextSeq++;
        }
        if ($this->entries === [] && $di === 0 && $dw === 0 && $de === 0) {
            return;
        }
        $entries = $this->entries;
        $this->entries = [];
        $this->dropInfo = $this->dropWarn = $this->dropError = 0;
        ($this->publish)([
            'attempt_id' => $this->attemptId,
            'lease_token' => $this->leaseToken,
            'entries' => $entries,
            'dropped' => $di + $dw + $de,
            'dropped_info' => $di,
            'dropped_warn' => $dw,
            'dropped_error' => $de,
        ]);
    }

    // test helpers
    public function setEntriesForTest(array $entries, int $nextSeq): void
    {
        $this->entries = $entries;
        $this->nextSeq = $nextSeq;
    }

    public function setDropsForTest(int $info, int $warn, int $error): void
    {
        $this->dropInfo = $info;
        $this->dropWarn = $warn;
        $this->dropError = $error;
    }

    public function dropCounts(): array
    {
        return [$this->dropInfo, $this->dropWarn, $this->dropError];
    }

    public static function normalizeLevel(string $level): string
    {
        return match (strtoupper($level)) {
            'DEBUG' => 'DEBUG',
            'WARN', 'WARNING' => 'WARN',
            'ERROR', 'FATAL', 'PANIC' => 'ERROR',
            default => 'INFO',
        };
    }

    public static function dropPriority(string $level): int
    {
        return match (self::normalizeLevel($level)) {
            'DEBUG' => 0,
            'INFO' => 1,
            'WARN' => 2,
            'ERROR' => 3,
            default => 1,
        };
    }
}
