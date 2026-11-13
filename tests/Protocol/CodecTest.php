<?php
declare(strict_types=1);

namespace JobCenter\Tests\Protocol;

use JobCenter\Json;
use JobCenter\Protocol\Codec;
use JobCenter\Protocol\Envelope;
use PHPUnit\Framework\TestCase;

final class CodecTest extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        $raw = Codec::encode('task.started', [
            'attempt_id' => 'a1',
            'lease_token' => 'L',
            'started_at' => Json::now(),
        ], 'm_abc');
        $env = Codec::decode($raw);
        self::assertSame(Envelope::VERSION, $env->protocolVersion);
        self::assertSame('1.1', $env->protocolVersion);
        self::assertSame('m_abc', $env->messageId);
        self::assertSame('task.started', $env->type);
        self::assertIsArray($env->data);
        self::assertSame('a1', $env->data['attempt_id']);
    }

    public function testEmptyDataEncodesAsObject(): void
    {
        $raw = Codec::encode('system.notice', []);
        $v = json_decode($raw, false);
        self::assertIsObject($v->data);
        self::assertSame('{}', json_encode($v->data));
    }

    public function testRejectsMissingFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Codec::decode('{"protocol_version":"1.1","type":"x"}');
    }

    public function testJsonRejectsListAsObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Json::asObject([1, 2, 3]);
    }

    public function testJsonEmptyArrayIsObject(): void
    {
        self::assertSame('{}', Json::encode([], true));
        self::assertSame('{}', Json::encode(new \stdClass(), true));
    }

    public function testAssertSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Json::assertSize(str_repeat('a', Json::MAX_BYTES + 1), 'state');
    }
}
