<?php
declare(strict_types=1);

namespace JobCenter\ThinkPHP;

/**
 * Optional ThinkPHP 8 command adapter. The core SDK does not depend on ThinkPHP.
 *
 * Register in a ThinkPHP app console if you want `php think jobcenter:worker`.
 */
class WorkerCommand
{
    protected $name = 'jobcenter:worker';
    protected $description = 'Start the GlassJob Executor worker (protocol 1.1)';

    public function handle(): int
    {
        return \JobCenter\Client::main($_SERVER['argv'] ?? []);
    }
}
