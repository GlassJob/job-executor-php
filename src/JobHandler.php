<?php
declare(strict_types=1);

namespace JobCenter;

interface JobHandler
{
    public function handle(JobContext $job): void;
}
