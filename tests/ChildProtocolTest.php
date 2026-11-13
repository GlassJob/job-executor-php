<?php
declare(strict_types=1);

namespace JobCenter\Tests;

use JobCenter\ChildProtocol;
use PHPUnit\Framework\TestCase;

final class ChildProtocolTest extends TestCase
{
    public function testEncodeDecode(): void
    {
        $line = ChildProtocol::encode(['type' => 'progress', 'percent' => 40, 'message' => 'ok']);
        self::assertStringStartsWith('@@JC ', $line);
        self::assertStringEndsWith("\n", $line);
        $frame = ChildProtocol::tryDecodeLine($line);
        self::assertSame('progress', $frame['type']);
        self::assertSame(40, $frame['percent']);
    }

    public function testIgnoresPlainOutput(): void
    {
        self::assertNull(ChildProtocol::tryDecodeLine("hello world\n"));
    }
}
