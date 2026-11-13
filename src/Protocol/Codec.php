<?php
declare(strict_types=1);

namespace JobCenter\Protocol;

use JobCenter\Json;

final class Codec
{
    /** @param mixed $data */
    public static function encode(string $type, $data, ?string $messageId = null): string
    {
        return Json::encode(Envelope::make($type, $data, $messageId)->toArray());
    }

    public static function decode(string $raw): Envelope
    {
        $v = json_decode($raw, true);
        if (!is_array($v)) {
            throw new \InvalidArgumentException('decode envelope: not an object');
        }
        $pv = (string) ($v['protocol_version'] ?? '');
        $id = (string) ($v['message_id'] ?? '');
        $type = (string) ($v['type'] ?? '');
        if ($pv === '' || $id === '' || $type === '' || !array_key_exists('data', $v)) {
            throw new \InvalidArgumentException('decode envelope: missing required fields');
        }
        return new Envelope(
            protocolVersion: $pv,
            messageId: $id,
            type: $type,
            timestamp: (string) ($v['timestamp'] ?? ''),
            data: $v['data'],
            requestId: isset($v['request_id']) ? (string) $v['request_id'] : null,
            traceId: isset($v['trace_id']) ? (string) $v['trace_id'] : null,
        );
    }
}
