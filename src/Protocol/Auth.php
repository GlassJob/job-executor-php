<?php
declare(strict_types=1);

namespace JobCenter\Protocol;

final class Auth
{
    public static function canonical(string $code, string $credentialId, string $instanceKey, string $timestamp, string $nonce): string
    {
        return implode("\n", ['AUTH', $code, $credentialId, $instanceKey, $timestamp, $nonce]);
    }

    public static function signHmac(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }
}
