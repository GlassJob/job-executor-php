<?php
declare(strict_types=1);

namespace JobCenter\Tests\Http;

use JobCenter\Http\Transport;
use JobCenter\Http\TransportException;
use JobCenter\Http\TransportResponse;

final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:string,timeout:float}> */
    public array $requests = [];

    /** @var list<TransportResponse|\Throwable> */
    private array $queue = [];

    public function enqueue(TransportResponse|\Throwable $response): void
    {
        $this->queue[] = $response;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function enqueueEnvelope(int $httpStatus, int $code, mixed $data = null, string $message = '成功'): void
    {
        $payload = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        $this->enqueue(new TransportResponse(
            $httpStatus,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['content-type' => 'application/json'],
        ));
    }

    public function request(string $method, string $url, array $headers, string $body, float $timeoutSeconds): TransportResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeoutSeconds,
        ];
        if ($this->queue === []) {
            throw new TransportException('no queued HTTP response');
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
