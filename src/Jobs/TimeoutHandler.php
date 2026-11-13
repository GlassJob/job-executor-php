<?php
declare(strict_types=1);

namespace JobCenter\Jobs;

use JobCenter\JobContext;
use JobCenter\JobHandler;

final class TimeoutHandler implements JobHandler
{
    public function handle(JobContext $job): void
    {
        $job->log()->info('timeout handler sleeping past deadline');
        // Cooperative sleep still honors deadline via throwIfDeadline.
        $job->sleep(5);
    }
}
