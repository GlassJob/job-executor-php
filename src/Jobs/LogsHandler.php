<?php
declare(strict_types=1);

namespace JobCenter\Jobs;

use JobCenter\JobContext;
use JobCenter\JobHandler;

final class LogsHandler implements JobHandler
{
    public function handle(JobContext $job): void
    {
        $job->log()->info('demo.logs start');
        for ($i = 0; $i < 120; $i++) {
            $job->log()->info('flood info', ['i' => $i]);
        }
        $job->log()->warn('sample warn', ['n' => 1]);
        $job->log()->error('sample error', ['n' => 1]);
        $job->setResult(['flooded' => 120]);
    }
}
