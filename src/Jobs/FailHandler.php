<?php
declare(strict_types=1);

namespace JobCenter\Jobs;

use JobCenter\JobContext;
use JobCenter\JobHandler;
use JobCenter\NonRetryableException;

final class FailHandler implements JobHandler
{
    public function handle(JobContext $job): void
    {
        $job->log()->info('fail handler about to error');
        throw new NonRetryableException('boom', 'DEMO_FAIL');
    }
}
