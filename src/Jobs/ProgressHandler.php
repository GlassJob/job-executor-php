<?php
declare(strict_types=1);

namespace JobCenter\Jobs;

use JobCenter\CancelledException;
use JobCenter\JobContext;
use JobCenter\JobHandler;

final class ProgressHandler implements JobHandler
{
    public function handle(JobContext $job): void
    {
        $in = $job->bind(['total_seconds' => 300, 'step_seconds' => 15]);
        $total = (float) $in['total_seconds'];
        $step = (float) $in['step_seconds'];
        if ($step <= 0) {
            $step = 15;
        }
        $steps = (int) floor($total / $step);
        if ($steps < 1) {
            $steps = 1;
        }
        $job->log()->info('progress job start', ['duration' => $total, 'step' => $step]);
        $job->progress(0, 'started');
        $started = microtime(true);
        for ($i = 1; $i <= $steps; $i++) {
            try {
                $job->sleep($step);
            } catch (CancelledException $e) {
                $job->log()->info('progress cancelled', ['err' => $e->getMessage(), 'elapsed' => microtime(true) - $started]);
                throw $e;
            }
            $pct = $i / $steps * 100;
            $job->progress($pct, $i === $steps ? 'done' : 'running');
            $job->log()->info('progress tick', ['percent' => $pct, 'elapsed' => microtime(true) - $started]);
        }
        $job->setResult([
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'steps' => $steps,
        ]);
    }
}
