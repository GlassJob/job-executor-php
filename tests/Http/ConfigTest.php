<?php
declare(strict_types=1);

namespace JobCenter\Tests\Http;

use JobCenter\Http\Config;
use JobCenter\Http\InvalidConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testExplicitConfigAndRedaction(): void
    {
        $cfg = new Config(
            baseUrl: 'http://127.0.0.1:8080/',
            clientId: 'client-1',
            clientSecret: 'super-secret',
            operatorId: 'op-1',
            operatorName: 'ops',
            timeoutSeconds: 8.5,
        );
        $cfg->validate();
        self::assertSame('http://127.0.0.1:8080', $cfg->baseUrl);
        self::assertSame('client-1', $cfg->clientId);
        self::assertSame('super-secret', $cfg->clientSecret());
        self::assertSame('op-1', $cfg->operatorId);
        self::assertSame('ops', $cfg->operatorName);
        self::assertSame(8.5, $cfg->timeoutSeconds);

        ob_start();
        var_dump($cfg);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('super-secret', $dump);
        self::assertStringContainsString('***', $dump);
        $json = json_encode($cfg);
        self::assertIsString($json);
        self::assertStringNotContainsString('super-secret', $json);
    }

    public function testHasNoEnvironmentLoader(): void
    {
        self::assertFalse(method_exists(Config::class, 'fromEnv'));
        self::assertFalse(method_exists(Config::class, 'env'));
    }

    public function testRejectsMissingCredentials(): void
    {
        $cfg = new Config(baseUrl: 'http://127.0.0.1:8080', clientId: '', clientSecret: '');
        $this->expectException(InvalidConfigException::class);
        $cfg->validate();
    }

    public function testRejectsWebsocketBaseUrl(): void
    {
        $cfg = new Config(baseUrl: 'ws://127.0.0.1:8080/v1/executor/ws', clientId: 'c', clientSecret: 's');
        $this->expectException(InvalidConfigException::class);
        $cfg->validate();
    }

    public function testRejectsZeroTimeoutAndUserinfo(): void
    {
        $cfg = new Config(baseUrl: 'http://127.0.0.1:8080', clientId: 'c', clientSecret: 's', timeoutSeconds: 0);
        try {
            $cfg->validate();
            self::fail('expected timeout error');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('timeoutSeconds', $e->getMessage());
        }
        $cfg = new Config(baseUrl: 'http://user:pass@127.0.0.1:8080', clientId: 'c', clientSecret: 's');
        $this->expectException(InvalidConfigException::class);
        $cfg->validate();
    }
}
