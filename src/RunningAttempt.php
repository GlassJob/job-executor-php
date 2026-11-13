<?php
declare(strict_types=1);

namespace JobCenter;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class RunningAttempt
{
    public function __construct(
        public string $attemptId,
        public string $executionId,
        public string $leaseToken,
        public string $jobCode,
        public Process $process,
        public InputStream $stdin,
        public string $stdoutBuf = '',
        /** @var list<array<string,mixed>> */
        public array $frames = [],
        /** @var array<string,mixed>|null */
        public ?array $result = null,
        /** @var array<string,mixed>|null */
        public ?array $nextState = null,
        /** @var array<string,mixed>|null */
        public ?array $error = null,
        public ?string $childTerminal = null,
        public bool $cancelRequested = false,
        public float $startedAt = 0.0,
    ) {
        if ($this->startedAt <= 0) {
            $this->startedAt = microtime(true);
        }
    }
}
