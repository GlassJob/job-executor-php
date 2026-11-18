<?php
declare(strict_types=1);

namespace JobCenter\Http;

/**
 * Admin HMAC as defined by job-center security.md / internal/pkg/auth.
 *
 * Canonical:
 *   METHOD\nPATH\nQUERY\nClient-Id\nTimestamp\nNonce\nOperator-Id\nOperator-Name\nhex(sha256(body))
 * QUERY is key-and-value sorted, RFC3986 encoded, joined with &. Empty body hashes the empty byte string.
 */
final class Signer
{
    public const HEADER_CLIENT_ID = 'X-JC-Client-Id';
    public const HEADER_TIMESTAMP = 'X-JC-Timestamp';
    public const HEADER_NONCE = 'X-JC-Nonce';
    public const HEADER_OPERATOR_ID = 'X-JC-Operator-Id';
    public const HEADER_OPERATOR_NAME = 'X-JC-Operator-Name';
    public const HEADER_SIGNATURE = 'X-JC-Signature';

    /**
     * RFC3986 encode matching Go url.QueryEscape with '+' replaced by '%20'.
     */
    public static function rfc3986(string $s): string
    {
        return rawurlencode($s);
    }

    public static function queryUnescape(string $s): string
    {
        return rawurldecode(str_replace('+', '%20', $s));
    }

    /**
     * Parse a raw query string the way Go net/url.ParseQuery does for signing.
     *
     * @return array<string, list<string>>
     */
    public static function parseQuery(string $rawQuery): array
    {
        $vals = [];
        if ($rawQuery === '') {
            return $vals;
        }
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            if (str_contains($pair, ';')) {
                throw new \InvalidArgumentException('invalid semicolon separator in query');
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $key = self::queryUnescape($pair);
                $value = '';
            } else {
                $key = self::queryUnescape(substr($pair, 0, $eq));
                $value = self::queryUnescape(substr($pair, $eq + 1));
            }
            if ($key === '') {
                continue;
            }
            $vals[$key][] = $value;
        }
        return $vals;
    }

    /**
     * Canonical QUERY from a raw query string (may be unsorted / use '+').
     */
    public static function canonicalQuery(string $rawQuery): string
    {
        return self::canonicalFromValues(self::parseQuery($rawQuery));
    }

    /**
     * Build a canonical query string from structured parameters (also used as the wire query).
     *
     * @param array<string, scalar|list<scalar>|null> $params
     */
    public static function encodeQuery(array $params): string
    {
        $vals = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $k = (string) $key;
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if ($item === null) {
                            continue;
                        }
                        $vals[$k][] = self::stringify($item);
                    }
                } else {
                    throw new \InvalidArgumentException('query parameter ' . $k . ' must be a scalar or list');
                }
            } else {
                $vals[$k][] = self::stringify($value);
            }
        }
        return self::canonicalFromValues($vals);
    }

    /**
     * @param array<string, list<string>> $vals
     */
    public static function canonicalFromValues(array $vals): string
    {
        $keys = array_keys($vals);
        sort($keys, SORT_STRING);
        $parts = [];
        foreach ($keys as $k) {
            $vs = $vals[$k];
            sort($vs, SORT_STRING);
            foreach ($vs as $v) {
                $parts[] = self::rfc3986($k) . '=' . self::rfc3986($v);
            }
        }
        return implode('&', $parts);
    }

    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function canonical(
        string $method,
        string $path,
        string $rawQuery,
        string $clientId,
        string $timestamp,
        string $nonce,
        string $operatorId,
        string $operatorName,
        string $body,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            self::canonicalQuery($rawQuery),
            $clientId,
            $timestamp,
            $nonce,
            $operatorId,
            $operatorName,
            self::bodyHash($body),
        ]);
    }

    public static function sign(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * @return array<string,string>
     */
    public static function headers(
        string $method,
        string $path,
        string $rawQuery,
        string $clientId,
        string $secret,
        string $timestamp,
        string $nonce,
        string $operatorId,
        string $operatorName,
        string $body,
    ): array {
        $canonical = self::canonical(
            $method,
            $path,
            $rawQuery,
            $clientId,
            $timestamp,
            $nonce,
            $operatorId,
            $operatorName,
            $body,
        );
        return [
            self::HEADER_CLIENT_ID => $clientId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_OPERATOR_ID => $operatorId,
            self::HEADER_OPERATOR_NAME => $operatorName,
            self::HEADER_SIGNATURE => self::sign($secret, $canonical),
        ];
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw new \InvalidArgumentException('query values must be scalars');
    }
}
