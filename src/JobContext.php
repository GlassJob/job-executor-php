<?php
declare(strict_types=1);

namespace JobCenter;

final class JobContext
{
    public string $executionId;
    public string $attemptId;
    public string $jobCode;
    public int $attemptNumber;
    public string $triggerType;
    public ?\DateTimeImmutable $deadline;

    /** @var array<string,mixed> */
    private array $params;
    /** @var array<string,mixed> */
    private array $state;
    private int $stateVersion;
    /** @var array<string,mixed>|null */
    private ?array $nextState = null;
    /** @var array<string,mixed>|null */
    private ?array $result = null;
    private bool $cancelled = false;
    private JobLogger $logger;
    /** @var callable(array<string,mixed>):void */
    private $emit;
    /** @var callable():void|null */
    private $cancelPoll = null;

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $state
     * @param callable(array<string,mixed>):void $emit
     */
    public function __construct(
        string $executionId,
        string $attemptId,
        string $jobCode,
        int $attemptNumber,
        string $triggerType,
        ?\DateTimeImmutable $deadline,
        array $params,
        array $state,
        int $stateVersion,
        callable $emit,
    ) {
        $this->executionId = $executionId;
        $this->attemptId = $attemptId;
        $this->jobCode = $jobCode;
        $this->attemptNumber = $attemptNumber;
        $this->triggerType = $triggerType;
        $this->deadline = $deadline;
        $this->params = $params;
        $this->state = $state;
        $this->stateVersion = $stateVersion;
        $this->emit = $emit;
        $this->logger = new JobLogger($this);
    }

    /** @return array<string,mixed> */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Bind params into an associative array (optionally merged over $defaults).
     *
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    public function bind(array $defaults = []): array
    {
        return array_merge($defaults, $this->params);
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        return $this->state;
    }

    public function stateVersion(): int
    {
        return $this->stateVersion;
    }

    /**
     * Snapshot of job_states (key=default) at dispatch time.
     *
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    public function bindState(array $defaults = []): array
    {
        return array_merge($defaults, $this->state);
    }

    public function progress(float $percent, string $message = ''): void
    {
        ($this->emit)([
            'type' => 'progress',
            'percent' => $percent,
            'message' => $message,
        ]);
    }

    /**
     * Mid-run persist to job_states.default (task.checkpoint). Fire-and-forget, matching the Go SDK.
     *
     * @param array<string,mixed>|object $state
     */
    public function checkpoint(array|object $state): void
    {
        $obj = Json::asObject($state);
        $raw = Json::encode($obj, true);
        Json::assertSize($raw, 'state');
        $decoded = Json::decodeObject($raw);
        $this->state = $decoded;
        ($this->emit)(['type' => 'checkpoint', 'state' => $decoded]);
    }

    /**
     * Written into task.completed.data.next_state on success.
     *
     * @param array<string,mixed>|object $state
     */
    public function setNextState(array|object $state): void
    {
        $obj = Json::asObject($state);
        $raw = Json::encode($obj, true);
        Json::assertSize($raw, 'state');
        $this->nextState = Json::decodeObject($raw);
        $this->state = $this->nextState;
    }

    /**
     * @param array<string,mixed>|object $result
     */
    public function setResult(array|object $result): void
    {
        $obj = Json::asObject($result);
        $raw = Json::encode($obj, true);
        Json::assertSize($raw, 'result');
        $this->result = Json::decodeObject($raw);
    }

    public function log(): JobLogger
    {
        return $this->logger;
    }

    /** @param array<string,mixed> $fields */
    public function emitLog(string $level, string $message, array $fields = []): void
    {
        $frame = [
            'type' => 'log',
            'level' => $level,
            'message' => $message,
        ];
        if ($fields !== []) {
            $frame['fields'] = $fields;
        }
        ($this->emit)($frame);
    }

    public function setCancelPoll(callable $fn): void
    {
        $this->cancelPoll = $fn;
    }

    public function markCancelled(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        $this->pollCancel();
        $this->throwIfDeadline();
        return $this->cancelled;
    }

    public function throwIfCancelled(): void
    {
        $this->pollCancel();
        $this->throwIfDeadline();
        if ($this->cancelled) {
            throw new CancelledException('job cancelled');
        }
    }

    private function pollCancel(): void
    {
        if ($this->cancelPoll !== null) {
            ($this->cancelPoll)();
        }
    }

    public function throwIfDeadline(): void
    {
        if ($this->deadline !== null && new \DateTimeImmutable('now', new \DateTimeZone('UTC')) >= $this->deadline) {
            throw new DeadlineExceededException('deadline exceeded');
        }
    }

    /**
     * Cooperative sleep that honors cancel and deadline.
     */
    public function sleep(float $seconds): void
    {
        $until = microtime(true) + $seconds;
        while (true) {
            $this->throwIfCancelled();
            $left = $until - microtime(true);
            if ($left <= 0) {
                return;
            }
            usleep((int) min(100_000, $left * 1_000_000));
        }
    }

    /** @return array<string,mixed>|null */
    public function nextState(): ?array
    {
        return $this->nextState;
    }

    /** @return array<string,mixed>|null */
    public function result(): ?array
    {
        return $this->result;
    }
}

