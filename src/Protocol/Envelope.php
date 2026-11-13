<?php
declare(strict_types=1);

namespace JobCenter\Protocol;

use JobCenter\Json;

final class Envelope
{
    public const VERSION = '1.1';
    public const SUBPROTOCOL = 'jobcenter.v1';

    public function __construct(
        public string $protocolVersion,
        public string $messageId,
        public string $type,
        public string $timestamp,
        public mixed $data,
        public ?string $requestId = null,
        public ?string $traceId = null,
    ) {
    }

    /** @param mixed $data */
    public static function make(string $type, $data, ?string $messageId = null): self
    {
        return new self(
            protocolVersion: self::VERSION,
            messageId: $messageId ?? Json::messageId(),
            type: $type,
            timestamp: Json::now(),
            data: $data ?? new \stdClass(),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = [
            'protocol_version' => $this->protocolVersion,
            'message_id' => $this->messageId,
            'type' => $this->type,
            'timestamp' => $this->timestamp,
            'data' => $this->data === [] ? new \stdClass() : $this->data,
        ];
        if ($this->requestId !== null) {
            $out['request_id'] = $this->requestId;
        }
        if ($this->traceId !== null) {
            $out['trace_id'] = $this->traceId;
        }
        return $out;
    }
}
