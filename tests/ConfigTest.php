<?php
declare(strict_types=1);

namespace JobCenter\Tests;

use JobCenter\Config;
use JobCenter\Protocol\Auth;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testParseCredentialToken(): void
    {
        [$id, $secret] = Config::parseCredentialToken('jcs_abc-123.s3cret');
        self::assertSame('abc-123', $id);
        self::assertSame('s3cret', $secret);
        $this->expectException(\InvalidArgumentException::class);
        Config::parseCredentialToken('bad');
    }

    public function testConfigCredentialCompat(): void
    {
        $cfg = new Config();
        $cfg->endpoint = 'ws://127.0.0.1:8080/v1/executor/ws';
        $cfg->serviceCode = 'demo';
        $cfg->credential = 'jcs_cred1.supersecret';
        $cfg->instanceKey = 'inst-1';
        $cfg->applyDefaults();
        self::assertSame('cred1', $cfg->credentialId);
        self::assertSame('supersecret', $cfg->credentialSecret);
    }

    public function testAuthCanonical(): void
    {
        $canon = Auth::canonical('svc', 'cred', 'key', '1700000000', 'nonce1');
        self::assertSame("AUTH\nsvc\ncred\nkey\n1700000000\nnonce1", $canon);
        $sig = Auth::signHmac('secret', $canon);
        self::assertSame(64, strlen($sig));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sig);
    }

    public function testInstanceKeyTruncated(): void
    {
        $cfg = new Config();
        $cfg->endpoint = 'ws://x/v1/executor/ws';
        $cfg->serviceCode = 'demo';
        $cfg->credentialId = 'c';
        $cfg->credentialSecret = 's';
        $cfg->instanceKey = str_repeat('a', 200);
        $cfg->applyDefaults();
        self::assertSame(128, strlen($cfg->instanceKey));
    }
}
