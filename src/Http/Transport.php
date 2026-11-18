<?php
declare(strict_types=1);

namespace JobCenter\Http;

interface Transport
{
    /**
     * Send one HTTP request. Do not retry; callers decide retry policy.
     *
     * @param array<string,string> $headers
     */
    public function request(string $method, string $url, array $headers, string $body, float $timeoutSeconds): TransportResponse;
}
