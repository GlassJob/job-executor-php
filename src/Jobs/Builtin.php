<?php
declare(strict_types=1);

namespace JobCenter\Jobs;

use JobCenter\Client;
use JobCenter\Job;

final class Builtin
{
    public static function register(Client $client): void
    {
        foreach (self::jobs() as $job) {
            $client->registerJob($job);
        }
    }

    /** @return list<Job> */
    public static function jobs(): array
    {
        return [
            new Job(code: 'demo.hello', handler: HelloHandler::class, name: 'Hello', handlerVersion: '1.0.0'),
            new Job(code: 'demo.fail', handler: FailHandler::class, name: 'Fail', handlerVersion: '1.0.0'),
            new Job(code: 'demo.sleep', handler: SleepHandler::class, name: 'Sleep', handlerVersion: '1.0.0', timeoutSeconds: 120),
            new Job(code: 'demo.timeout', handler: TimeoutHandler::class, name: 'Timeout', handlerVersion: '1.0.0', timeoutSeconds: 2),
            new Job(code: 'demo.progress', handler: ProgressHandler::class, name: 'Progress', handlerVersion: '1.0.0', timeoutSeconds: 360),
            new Job(code: 'demo.counter', handler: CounterHandler::class, name: 'Counter', handlerVersion: '1.0.0', timeoutSeconds: 30),
            new Job(code: 'demo.logs', handler: LogsHandler::class, name: 'Logs', handlerVersion: '1.0.0', timeoutSeconds: 30),
        ];
    }

    /** @return array<string,class-string<\JobCenter\JobHandler>> */
    public static function registry(): array
    {
        $out = [];
        foreach (self::jobs() as $job) {
            $out[$job->code] = $job->handler;
        }
        return $out;
    }
}
