<?php
declare(strict_types=1);

namespace JobCenter;

final class Reconnect
{
    public static function nextBackoff(int $attempt): float
    {
        if ($attempt < 0) {
            $attempt = 0;
        }
        $base = 1 << min($attempt, 5); // cap shift so we don't overflow; then clamp to 30
        if ($base > 30) {
            $base = 30;
        }
        $jitter = 0.2;
        $f = 1 + ((mt_rand() / mt_getrandmax()) * 2 - 1) * $jitter;
        $d = $base * $f;
        if ($d < 1) {
            $d = 1;
        }
        if ($d > 30) {
            $d = 30;
        }
        return $d;
    }

    public static function isFatalSessionError(\Throwable $err): bool
    {
        $s = $err->getMessage();
        return str_contains($s, 'auth rejected')
            || str_contains($s, 'AUTH_INVALID_CREDENTIAL')
            || str_contains($s, 'StatusCode(4001)')
            || str_contains($s, 'close code 4001');
    }
}
