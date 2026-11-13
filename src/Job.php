<?php
declare(strict_types=1);

namespace JobCenter;

final class Job
{
    /**
     * @param class-string<JobHandler> $handler
     * @param array<string,mixed>|null $paramsSchema
     */
    public function __construct(
        public string $code,
        public string $handler,
        public string $name = '',
        public string $description = '',
        public string $handlerVersion = '1.0.0',
        public string $defaultCron = '',
        public int $timeoutSeconds = 0,
        public int $maxConcurrency = 0,
        public ?array $paramsSchema = null,
    ) {
        if ($this->name === '') {
            $this->name = $this->code;
        }
        if ($this->handlerVersion === '') {
            $this->handlerVersion = '1.0.0';
        }
    }
}

interface JobHandler
{
    public function handle(JobContext $job): void;
}
