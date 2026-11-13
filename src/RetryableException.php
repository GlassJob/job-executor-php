<?php
declare(strict_types=1);

namespace JobCenter;

class RetryableException extends \RuntimeException
{
    public function __construct(string $message = '', public readonly string $errorCode = 'HANDLER_ERROR', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}


function isRetryable(\Throwable $err): array
{
    if ($err instanceof NonRetryableException) {
        return [$err->errorCode, false];
    }
    if ($err instanceof RetryableException) {
        return [$err->errorCode, true];
    }
    return ['', false];
}
