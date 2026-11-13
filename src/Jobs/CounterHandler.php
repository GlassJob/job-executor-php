<?php
declare(strict_types=1);

namespace JobCenter\Jobs;

use JobCenter\JobContext;
use JobCenter\JobHandler;

final class CounterHandler implements JobHandler
{
    public function handle(JobContext $job): void
    {
        $st = $job->bindState(['count' => 0, 'last' => '']);
        $job->log()->info('loaded job state', ['version' => $job->stateVersion(), 'count' => $st['count'] ?? 0]);
        $in = $job->bind(['step' => 1]);
        $step = (int) ($in['step'] ?? 1);
        if ($step === 0) {
            $step = 1;
        }
        $st['count'] = (int) ($st['count'] ?? 0) + $step;
        $st['last'] = 'checkpoint';
        $job->progress(40, 'checkpointing');
        $job->checkpoint($st);
        $job->sleep(0.2);
        $st['last'] = 'completed';
        $job->progress(100, 'finishing');
        $job->setNextState($st);
        $job->setResult([
            'count' => $st['count'],
            'step' => $step,
            'state_version' => $job->stateVersion(),
        ]);
    }
}
