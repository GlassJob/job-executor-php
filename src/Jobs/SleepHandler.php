<?php
declare(strict_types=1);

namespace JobCenter\Jobs;

use JobCenter\CancelledException;
use JobCenter\JobContext;
use JobCenter\JobHandler;

final class SleepHandler implements JobHandler
{
    public function handle(JobContext $job): void
    {
        $seconds = (float) ($job->bind(['seconds' => 60])['seconds'] ?? 60);
        $job->log()->info('sleep start', ['seconds' => $seconds]);
        try {
            $job->sleep($seconds);
        } catch (CancelledException $e) {
            $job->log()->info('sleep cancelled', ['err' => $e->getMessage()]);
            throw $e;
        }
        $job->log()->info('sleep finished');
        $job->setResult(['slept' => true]);
    }
}
