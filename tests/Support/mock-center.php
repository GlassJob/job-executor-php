#!/usr/bin/env php
<?php
declare(strict_types=1);

require $argv[1];

use JobCenter\Json;
use JobCenter\Protocol\Auth;
use JobCenter\Protocol\Codec;
use JobCenter\Protocol\Envelope;
use JobCenter\Protocol\MessageType;
use WebSocket\Configuration;
use WebSocket\Connection;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;
use WebSocket\Middleware\SubprotocolNegotiation;
use WebSocket\Server;

$portFile = $argv[2];
$okFile = $argv[3];
$service = $argv[4];
$credId = $argv[5];
$secret = $argv[6];

$probe = stream_socket_server('tcp://127.0.0.1:0');
$addr = stream_socket_get_name($probe, false);
$port = (int) substr((string) $addr, (int) strrpos((string) $addr, ':') + 1);
fclose($probe);

$server = new Server($port, false, new Configuration(timeout: 0.1));
$server->addMiddleware(new SubprotocolNegotiation([Envelope::SUBPROTOCOL]));
$server->addMiddleware(new CloseHandler());
$server->addMiddleware(new PingResponder());
$server->setTimeout(0.1);

$helloDone = false;
$sleepCancelled = false;
$sleepAttempt = 'att_sleep_e2e';
$sentExecute = false;

$send = static function (Connection $conn, string $type, array|object $data): void {
    $conn->text(Codec::encode($type, $data));
};

$server->onText(static function (Server $s, Connection $conn, Text $msg) use (
    $service, $credId, $secret, $send, &$helloDone, &$sleepCancelled, $sleepAttempt, &$sentExecute, $okFile
): void {
    $env = Codec::decode($msg->getContent());
    $data = is_array($env->data) ? $env->data : [];

    if ($env->type === MessageType::AUTH_REQUEST) {
        $canon = Auth::canonical(
            (string) ($data['code'] ?? ''),
            (string) ($data['credential_id'] ?? ''),
            (string) ($data['instance_key'] ?? ''),
            (string) ($data['timestamp'] ?? ''),
            (string) ($data['nonce'] ?? ''),
        );
        $sig = Auth::signHmac($secret, $canon);
        $ok = hash_equals($sig, (string) ($data['signature'] ?? ''))
            && ($data['code'] ?? '') === $service
            && ($data['credential_id'] ?? '') === $credId
            && in_array('1.1', $data['protocol_versions'] ?? [], true)
            && !isset($data['secret']);
        $send($conn, MessageType::AUTH_RESPONSE, [
            'ok' => $ok,
            'session_id' => 'sess_1',
            'session_epoch' => 1,
            'instance_id' => 'inst_1',
            'protocol_version' => Envelope::VERSION,
            'server_time' => Json::now(),
            'heartbeat_interval_seconds' => 10,
            'lease_ttl_seconds' => 30,
        ]);
        return;
    }

    if ($env->type === MessageType::SERVICE_REGISTER) {
        $jobs = is_array($data['jobs'] ?? null) ? $data['jobs'] : [];
        $accepted = [];
        foreach ($jobs as $j) {
            if (is_array($j) && isset($j['job_code'])) {
                $accepted[] = ['job_code' => $j['job_code'], 'job_id' => 'job_' . $j['job_code']];
            }
        }
        $send($conn, MessageType::SERVICE_REGISTER_ACK, [
            'ok' => true,
            'instance_id' => 'inst_1',
            'instance_key' => $data['instance_key'] ?? '',
            'session_id' => 'sess_1',
            'session_epoch' => 1,
            'instance_status' => 'ONLINE',
            'accepted_jobs' => $accepted,
        ]);
        return;
    }

    if ($env->type === MessageType::SERVICE_HEARTBEAT) {
        $send($conn, MessageType::SERVICE_HEARTBEAT_ACK, [
            'server_time' => Json::now(),
            'instance_status' => 'ONLINE',
        ]);
        if (!$sentExecute) {
            $sentExecute = true;
            $dl = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 seconds')->format('Y-m-d\TH:i:s.v\Z');
            $send($conn, MessageType::TASK_EXECUTE, [
                'execution_id' => 'ex_hello',
                'attempt_id' => 'att_hello_e2e',
                'attempt_number' => 1,
                'lease_token' => 'lease-hello',
                'lease_expires_at' => $dl,
                'job_code' => 'demo.hello',
                'params' => ['name' => 'e2e'],
                'state' => new stdClass(),
                'state_version' => 0,
                'timeout_seconds' => 10,
                'deadline_at' => $dl,
                'trigger_type' => 'MANUAL',
            ]);
            $send($conn, MessageType::TASK_EXECUTE, [
                'execution_id' => 'ex_sleep',
                'attempt_id' => $sleepAttempt,
                'attempt_number' => 1,
                'lease_token' => 'lease-sleep',
                'lease_expires_at' => $dl,
                'job_code' => 'demo.sleep',
                'params' => ['seconds' => 20],
                'state' => new stdClass(),
                'state_version' => 0,
                'timeout_seconds' => 30,
                'deadline_at' => $dl,
                'trigger_type' => 'MANUAL',
            ]);
        }
        return;
    }

    if ($env->type === MessageType::TASK_EXECUTE_ACK && ($data['attempt_id'] ?? '') === $sleepAttempt && ($data['accepted'] ?? false)) {
        $send($conn, MessageType::TASK_CANCEL, [
            'execution_id' => 'ex_sleep',
            'attempt_id' => $sleepAttempt,
            'lease_token' => 'lease-sleep',
            'reason' => 'ADMIN',
            'grace_seconds' => 5,
        ]);
        return;
    }

    if ($env->type === MessageType::TASK_COMPLETED && ($data['attempt_id'] ?? '') === 'att_hello_e2e') {
        $helloDone = isset($data['result']['hello']) && $data['result']['hello'] === 'e2e';
        $send($conn, MessageType::TASK_RESULT_ACK, [
            'status' => 'OK',
            'attempt_id' => 'att_hello_e2e',
        ]);
        if ($helloDone && $sleepCancelled) {
            file_put_contents($okFile, 'ok');
            $s->stop();
        }
        return;
    }

    if ($env->type === MessageType::TASK_CANCELLED && ($data['attempt_id'] ?? '') === $sleepAttempt) {
        $sleepCancelled = true;
        $send($conn, MessageType::TASK_RESULT_ACK, [
            'status' => 'OK',
            'attempt_id' => $sleepAttempt,
        ]);
        if ($helloDone && $sleepCancelled) {
            file_put_contents($okFile, 'ok');
            $s->stop();
        }
        return;
    }
});

file_put_contents($portFile, (string) $port);
$server->start(0.05);
