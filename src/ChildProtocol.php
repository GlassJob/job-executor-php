<?php
declare(strict_types=1);

namespace JobCenter;

/** Worker ↔ runner stdio frames. */
final class ChildProtocol
{
    public const PREFIX = '@@JC ';

    /** @param array<string,mixed> $frame */
    public static function encode(array $frame): string
    {
        return self::PREFIX . Json::encode($frame) . "\n";
    }

    /** @return array<string,mixed>|null */
    public static function tryDecodeLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if (!str_starts_with($line, self::PREFIX)) {
            return null;
        }
        $json = substr($line, strlen(self::PREFIX));
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
