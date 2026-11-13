<?php
declare(strict_types=1);

namespace JobCenter;

final class Json
{
    public const MAX_BYTES = 64 * 1024;

    /** RFC3339 UTC with milliseconds, matching Go protocol.TimeFormat in UTC. */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function messageId(): string
    {
        return 'm_' . bin2hex(random_bytes(12));
    }

    /**
     * Encode a JSON object. Empty arrays become {}. List arrays are rejected when $mustObject.
     *
     * @param mixed $value
     */
    public static function encode($value, bool $mustObject = false): string
    {
        if ($value === null) {
            $value = $mustObject ? new \stdClass() : $value;
        }
        if ($mustObject) {
            $value = self::asObject($value);
        } elseif ($value === []) {
            $value = new \stdClass();
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param mixed $value
     */
    public static function asObject($value): object|array
    {
        if ($value === null || $value === []) {
            return new \stdClass();
        }
        if (is_array($value)) {
            if (array_is_list($value) && $value !== []) {
                throw new \InvalidArgumentException('value must be a JSON object, got array');
            }
            return $value === [] ? new \stdClass() : $value;
        }
        if (is_object($value)) {
            return $value;
        }
        throw new \InvalidArgumentException('value must be a JSON object');
    }

    /**
     * @return array<string,mixed>
     */
    public static function decodeObject(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === 'null') {
            return [];
        }
        $v = json_decode($raw, true);
        if (!is_array($v) || array_is_list($v) && $v !== []) {
            if ($v === []) {
                return [];
            }
            throw new \InvalidArgumentException('expected JSON object');
        }
        return $v;
    }

    public static function assertSize(string $json, string $what = 'payload'): void
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException($what . ' exceeds 64KiB');
        }
    }
}
