<?php
declare(strict_types=1);

namespace JobCenter;

final class JobLogger
{
    public function __construct(private JobContext $job)
    {
    }

    /** @param array<string,mixed> $fields */
    public function debug(string $message, array $fields = []): void
    {
        $this->job->emitLog('DEBUG', $message, $fields);
    }

    /** @param array<string,mixed> $fields */
    public function info(string $message, array $fields = []): void
    {
        $this->job->emitLog('INFO', $message, $fields);
    }

    /** @param array<string,mixed> $fields */
    public function warn(string $message, array $fields = []): void
    {
        $this->job->emitLog('WARN', $message, $fields);
    }

    /** @param array<string,mixed> $fields */
    public function error(string $message, array $fields = []): void
    {
        $this->job->emitLog('ERROR', $message, $fields);
    }
}
