<?php
declare(strict_types=1);

namespace JobCenter;

final class Runner
{
    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        try {
            self::run();
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'runner: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    public static function run(): void
    {
        $payloadLine = fgets(STDIN);
        if ($payloadLine === false) {
            throw new \RuntimeException('missing execute payload on stdin');
        }
        $payload = json_decode($payloadLine, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('invalid execute payload');
        }

        $registryPath = (string) (getenv('JC_REGISTRY') ?: '');
        if ($registryPath === '' || !is_file($registryPath)) {
            throw new \RuntimeException('JC_REGISTRY missing or not a file');
        }
        /** @var array<string,class-string<JobHandler>> $registry */
        $registry = require $registryPath;
        $jobCode = (string) ($payload['job_code'] ?? '');
        $handlerClass = $registry[$jobCode] ?? null;
        if (!is_string($handlerClass) || !is_a($handlerClass, JobHandler::class, true)) {
            self::emit(['type' => 'error', 'code' => 'UNKNOWN_JOB', 'message' => 'job not registered', 'retryable' => false]);
            return;
        }

        $deadline = null;
        $deadlineAt = (string) ($payload['deadline_at'] ?? '');
        if ($deadlineAt !== '') {
            try {
                $deadline = new \DateTimeImmutable($deadlineAt);
            } catch (\Exception) {
                $deadline = null;
            }
        }
        if ($deadline === null) {
            $timeout = (int) ($payload['timeout_seconds'] ?? 0);
            if ($timeout > 0) {
                $deadline = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $timeout . ' seconds');
            }
        }

        $params = $payload['params'] ?? [];
        if (!is_array($params) || (array_is_list($params) && $params !== [])) {
            $params = [];
        }
        $state = $payload['state'] ?? [];
        if (!is_array($state) || (array_is_list($state) && $state !== [])) {
            $state = [];
        }

        $ctx = new JobContext(
            executionId: (string) ($payload['execution_id'] ?? ''),
            attemptId: (string) ($payload['attempt_id'] ?? ''),
            jobCode: $jobCode,
            attemptNumber: (int) ($payload['attempt_number'] ?? 1),
            triggerType: (string) ($payload['trigger_type'] ?? ''),
            deadline: $deadline,
            params: $params,
            state: $state,
            stateVersion: (int) ($payload['state_version'] ?? 0),
            emit: static function (array $frame): void {
                self::emit($frame);
            },
        );

        stream_set_blocking(STDIN, false);
        $ctx->setCancelPoll(static function () use ($ctx): void {
            while (($line = fgets(STDIN)) !== false) {
                $frame = ChildProtocol::tryDecodeLine($line);
                if (is_array($frame) && ($frame['type'] ?? '') === 'cancel') {
                    $ctx->markCancelled();
                }
            }
        });

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(\SIGTERM, static function () use ($ctx): void {
                $ctx->markCancelled();
            });
            pcntl_signal(\SIGINT, static function () use ($ctx): void {
                $ctx->markCancelled();
            });
        }

        try {
            $handler = new $handlerClass();
            $handler->handle($ctx);
            if ($ctx->isCancelled()) {
                self::emit(['type' => 'cancelled']);
                return;
            }
            $done = ['type' => 'done'];
            if ($ctx->result() !== null) {
                $done['result'] = $ctx->result();
            }
            if ($ctx->nextState() !== null) {
                $done['next_state'] = $ctx->nextState();
            }
            self::emit($done);
        } catch (CancelledException) {
            self::emit(['type' => 'cancelled']);
        } catch (DeadlineExceededException $e) {
            self::emit(['type' => 'timeout', 'code' => 'TIMEOUT', 'message' => $e->getMessage()]);
        } catch (NonRetryableException $e) {
            self::emit(['type' => 'error', 'code' => $e->errorCode ?: 'HANDLER_ERROR', 'message' => $e->getMessage(), 'retryable' => false]);
        } catch (RetryableException $e) {
            self::emit(['type' => 'error', 'code' => $e->errorCode ?: 'HANDLER_ERROR', 'message' => $e->getMessage(), 'retryable' => true]);
        } catch (\Throwable $e) {
            self::emit(['type' => 'error', 'code' => 'HANDLER_ERROR', 'message' => $e->getMessage(), 'retryable' => false]);
        }
    }

    /** @param array<string,mixed> $frame */
    public static function emit(array $frame): void
    {
        fwrite(STDOUT, ChildProtocol::encode($frame));
        fflush(STDOUT);
    }
}
