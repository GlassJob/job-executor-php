<?php
declare(strict_types=1);

namespace JobCenter\Jobs;

use JobCenter\JobContext;
use JobCenter\JobHandler;

final class HelloHandler implements JobHandler
{
    public function handle(JobContext $job): void
    {
        $in = $job->bind(['name' => 'world']);
        $name = (string) ($in['name'] ?? 'world');
        if ($name === '') {
            $name = 'world';
        }
        $job->log()->info('hello handler', ['name' => $name]);
        $job->progress(100, 'done');
        $job->setResult(['hello' => $name]);
    }
}
