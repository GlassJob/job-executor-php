<?php
declare(strict_types=1);

namespace JobCenter\ThinkPHP;

/**
 * Optional ThinkPHP 8 command adapter. The core SDK does not depend on ThinkPHP.
 *
 * Register in a ThinkPHP app console if you want `php think jobcenter:worker`.
 * Pass a PHP file that returns a JobCenter\Config instance:
 *   php think jobcenter:worker /path/to/jobcenter-config.php
 * Applications may also construct Config explicitly and call Client::start() themselves.
 */
class WorkerCommand
{
    protected $name = 'jobcenter:worker';
    protected $description = 'Start the GlassJob Executor worker (protocol 1.1)';

    public function handle(): int
    {
        $argv = $_SERVER['argv'] ?? [];
        $path = $argv[2] ?? '';
        if ($path === '') {
            $candidate = $argv[1] ?? '';
            if ($candidate !== $this->name && $candidate !== 'jobcenter:worker') {
                $path = $candidate;
            }
        }
        return \JobCenter\Client::main(['jobcenter-worker', $path]);
    }
}
