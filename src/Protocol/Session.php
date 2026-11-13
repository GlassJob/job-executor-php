<?php
declare(strict_types=1);

namespace JobCenter\Protocol;

use Phrity\Net\Stream\SocketStream;
use WebSocket\Client;
use WebSocket\Configuration;
use WebSocket\Connection;
use WebSocket\Exception\TimeoutException;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;
use WebSocket\Middleware\SubprotocolNegotiation;

/**
 * WebSocket session with an inbox so heartbeats / acks do not steal awaited frames.
 * Timeouts are applied onHandshake (phrity has no onConnect).
 */
final class Session
{
    private Client $client;
    private ?Connection $connection = null;
    /** @var list<Envelope> */
    private array $inbox = [];
    private bool $open = false;

    public function __construct(
        public readonly string $endpoint,
        public readonly float $timeoutSeconds = 15.0,
    ) {
        $configuration = new Configuration(timeout: (int) max(1, $this->timeoutSeconds));
        $this->client = new Client($endpoint, $configuration);
        $this->client->addMiddleware(new CloseHandler());
        $this->client->addMiddleware(new PingResponder());
        $this->client->addMiddleware(new SubprotocolNegotiation([Envelope::SUBPROTOCOL]));
        $this->client->setTimeout((int) max(1, $this->timeoutSeconds));
        $this->client->onHandshake(function (Client $c, Connection $conn, $hs): void {
            $this->connection = $conn;
            $stream = $conn->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout((int) max(1, $this->timeoutSeconds));
            }
        });
    }

    public function connect(): void
    {
        $this->client->connect();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        try {
            $this->client->close();
        } catch (\Throwable) {
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** @param mixed $data */
    public function send(string $type, $data): void
    {
        $this->client->text(Codec::encode($type, $data));
    }

    public function receive(float $timeoutSeconds): ?Envelope
    {
        if ($this->inbox !== []) {
            return array_shift($this->inbox);
        }
        return $this->readOne($timeoutSeconds);
    }

    public function receiveType(string $type, float $timeoutSeconds): Envelope
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new \RuntimeException('timeout waiting for ' . $type);
            }
            $env = $this->receive($left);
            if ($env === null) {
                continue;
            }
            if ($env->type === $type) {
                return $env;
            }
            $this->inbox[] = $env;
        }
    }

    private function readOne(float $timeoutSeconds): ?Envelope
    {
        $prev = $this->client->getTimeout();
        $sec = max(1, (int) ceil($timeoutSeconds));
        if ($timeoutSeconds < 1) {
            // phrity timeout is integer seconds; use at least 1 and treat TimeoutException as no message
            $sec = 1;
        }
        try {
            $this->client->setTimeout($sec);
            if ($this->connection !== null) {
                $stream = $this->connection->getStream();
                if ($stream instanceof SocketStream) {
                    $stream->setTimeout($sec);
                }
            }
            $msg = $this->client->receive();
            if ($msg instanceof Text) {
                return Codec::decode($msg->getContent());
            }
            return null;
        } catch (TimeoutException) {
            return null;
        } finally {
            try {
                $this->client->setTimeout($prev);
            } catch (\Throwable) {
            }
        }
    }
}
