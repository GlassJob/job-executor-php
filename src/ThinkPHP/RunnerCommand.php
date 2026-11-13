<?php
declare(strict_types=1);

namespace JobCenter\ThinkPHP;

/** Optional ThinkPHP 8 command adapter that delegates to the SDK runner. */
class RunnerCommand
{
    protected $name = 'jobcenter:runner';
    protected $description = 'Run a single GlassJob Executor child job';

    public function handle(): int
    {
        return \JobCenter\Runner::main($_SERVER['argv'] ?? []);
    }
}
