<?php
declare(strict_types=1);

namespace JobCenter\Http;

final class TransportResponse
{
    /**
     * @param array<string,string> $headers Lowercased header names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }
}
