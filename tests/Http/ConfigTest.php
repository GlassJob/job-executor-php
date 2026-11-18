<?php
declare(strict_types=1);

namespace JobCenter\Tests\Http;

use JobCenter\Http\Config;
use JobCenter\Http\InvalidConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $restore = [];

    protected function tearDown(): void
    {
        foreach ($this->restore as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        $this->restore = [];
        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->restore)) {
            $cur = getenv($key);
            $this->restore[$key] = $cur === false ? false : $cur;
        }
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key]);
        } else {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    public function testFromEnvAndRedaction(): void
    {
        $this->setEnv('JC_HTTP_BASE_URL', 'http://127.0.0.1:8080/');
        $this->setEnv('JC_HTTP_CLIENT_ID', 'client-1');
        $this->setEnv('JC_HTTP_CLIENT_SECRET', 'super-secret');
        $this->setEnv('JC_HTTP_OPERATOR_ID', 'op-1');
        $this->setEnv('JC_HTTP_OPERATOR_NAME', 'ops');
        $this->setEnv('JC_HTTP_TIMEOUT_SECONDS', '8.5');
        $this->setEnv('JC_CREDENTIAL_ID', 'executor-cred');
        $this->setEnv('JC_CREDENTIAL_SECRET', 'executor-secret');

        $cfg = Config::fromEnv();
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
        self::assertStringNotContainsString('executor-secret', $dump);
        self::assertStringContainsString('***', $dump);
        $json = json_encode($cfg);
        self::assertIsString($json);
        self::assertStringNotContainsString('super-secret', $json);
    }

    public function testDoesNotUseExecutorCredentials(): void
    {
        $this->setEnv('JC_HTTP_BASE_URL', 'http://127.0.0.1:8080');
        $this->setEnv('JC_HTTP_CLIENT_ID', null);
        $this->setEnv('JC_HTTP_CLIENT_SECRET', null);
        $this->setEnv('JC_CREDENTIAL_ID', 'executor-cred');
        $this->setEnv('JC_CREDENTIAL_SECRET', 'executor-secret');
        $this->expectException(InvalidConfigException::class);
        Config::fromEnv();
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
