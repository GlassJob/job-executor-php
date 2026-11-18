<?php
declare(strict_types=1);

namespace JobCenter\Http;

/**
 * Job Center Admin envelope or HTTP error. Does not include request secrets.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param array<string,mixed>|null $envelope
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly int $apiCode,
        public readonly ?array $envelope = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $apiCode, $previous);
    }
}
