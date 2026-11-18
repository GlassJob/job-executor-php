<?php
declare(strict_types=1);

namespace JobCenter\Tests;

use JobCenter\Client;
use JobCenter\Config;
use JobCenter\Http\Client as HttpClient;
use JobCenter\Job;
use JobCenter\JobHandler;
use JobCenter\Jobs\HelloHandler;
use PHPUnit\Framework\TestCase;

final class CompatibilityTest extends TestCase
{
    public function testExecutorAutoloadAndHttpNamespaceAreDistinct(): void
    {
        self::assertTrue(class_exists(Client::class));
        self::assertTrue(class_exists(HttpClient::class));
        self::assertNotSame(Client::class, HttpClient::class);
        self::assertTrue(class_exists(Config::class));
        self::assertTrue(interface_exists(JobHandler::class));
        self::assertTrue(class_exists(Job::class));
        self::assertTrue(is_subclass_of(HelloHandler::class, JobHandler::class));
        $job = new Job(code: 'demo.hello', handler: HelloHandler::class, name: 'Hello');
        self::assertSame('demo.hello', $job->code);
        self::assertSame(HelloHandler::class, $job->handler);
    }
}
