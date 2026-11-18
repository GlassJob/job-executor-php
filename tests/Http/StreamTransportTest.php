<?php
declare(strict_types=1);

namespace JobCenter\Tests\Http;

use JobCenter\Http\StreamTransport;
use JobCenter\Http\TransportException;
use PHPUnit\Framework\TestCase;

final class StreamTransportTest extends TestCase
{
    public function testRequestAgainstLocalServer(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            self::markTestSkipped('cannot bind loopback socket: ' . $errstr);
        }
        $name = stream_socket_get_name($server, false);
        if ($name === false || !preg_match('/:(\d+)$/', $name, $m)) {
            fclose($server);
            self::fail('cannot determine bound port');
        }
        $port = (int) $m[1];

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($server);
            self::markTestSkipped('pcntl_fork unavailable');
        }
        if ($pid === 0) {
            $conn = @stream_socket_accept($server, 3);
            if ($conn !== false) {
                $raw = '';
                while (!str_contains($raw, "\r\n\r\n")) {
                    $chunk = fread($conn, 1024);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $raw .= $chunk;
                }
                if (preg_match('/content-length:\s*(\d+)/i', $raw, $cm)) {
                    $need = (int) $cm[1];
                    $pos = strpos($raw, "\r\n\r\n");
                    $have = $pos === false ? 0 : strlen($raw) - $pos - 4;
                    while ($have < $need) {
                        $chunk = fread($conn, $need - $have);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $raw .= $chunk;
                        $have += strlen($chunk);
                    }
                }
                $body = '{"code":0,"message":"成功","data":{"ok":true}}';
                $resp = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                    . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
                fwrite($conn, $resp);
                fclose($conn);
            }
            fclose($server);
            exit(0);
        }

        fclose($server);
        try {
            $t = new StreamTransport();
            $resp = $t->request(
                'POST',
                'http://127.0.0.1:' . $port . '/v1/admin/services',
                ['X-Test' => '1'],
                '{"x":1}',
                3.0,
            );
            self::assertSame(200, $resp->status);
            self::assertStringContainsString('"ok":true', $resp->body);
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    public function testConnectionFailure(): void
    {
        $t = new StreamTransport();
        $this->expectException(TransportException::class);
        $t->request('GET', 'http://127.0.0.1:1/', [], '', 0.3);
    }
}
