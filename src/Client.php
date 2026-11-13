<?php
declare(strict_types=1);

namespace JobCenter;

use JobCenter\Protocol\Auth;
use JobCenter\Protocol\Codec;
use JobCenter\Protocol\Envelope;
use JobCenter\Protocol\MessageType;
use JobCenter\Protocol\Session;

final class Client
{
    private const SOFT_OUTBOX_LIMIT = 256;

    private Config $cfg;
    /** @var array<string,Job> */
    private array $jobs = [];
    private bool $started = false;
    private bool $stopping = false;
    private ?Session $session = null;
    private ProcessSupervisor $supervisor;
    private PendingStore $pending;
    /** @var array<string,true> */
    private array $seenAttempts = [];
    /** @var array<string,true> */
    private array $cancelRequested = [];
    /** @var array<string,AttemptLogBuf> */
    private array $logBufs = [];
    /** @var list<array{type:string,data:mixed}> */
    private array $softBuf = [];
    private int $sessionEpoch = 0;
    private string $instanceId = '';
    private bool $connected = false;
    private float $nextLogFlushAt = 0.0;
    private float $nextHeartbeatAt = 0.0;
    private float $heartbeatInterval = 10.0;
    /** @var array<string,mixed>|null */
    private ?array $syncAck = null;

    public function __construct(Config $cfg)
    {
        $cfg->applyDefaults();
        $this->cfg = $cfg;
        $root = dirname(__DIR__);
        $this->supervisor = new ProcessSupervisor(
            $cfg->phpBinary,
            $cfg->runnerBin,
            $cfg->registryPath,
            $root,
        );
        $this->pending = new PendingStore(rtrim($cfg->dataDir, '/\\') . DIRECTORY_SEPARATOR . 'pending_results');
    }

    public function registerJob(Job $job): void
    {
        if ($job->code === '' || $job->handler === '') {
            throw new \InvalidArgumentException('job code and handler are required');
        }
        if (isset($this->jobs[$job->code])) {
            throw new \InvalidArgumentException('duplicate job code: ' . $job->code);
        }
        $this->jobs[$job->code] = $job;
    }

    /** @return array{connected:bool,in_flight_jobs:int,session_epoch:int,instance_id:string,instance_key:string} */
    public function status(): array
    {
        return [
            'connected' => $this->connected,
            'in_flight_jobs' => $this->supervisor->count(),
            'session_epoch' => $this->sessionEpoch,
            'instance_id' => $this->instanceId,
            'instance_key' => $this->cfg->instanceKey,
        ];
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function start(): void
    {
        if ($this->started) {
            throw new \RuntimeException('client already started');
        }
        $this->started = true;
        $this->stopping = false;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = function (): void {
                $this->stopping = true;
            };
            pcntl_signal(\SIGTERM, $stop);
            pcntl_signal(\SIGINT, $stop);
        }

        $attempt = 0;
        while (!$this->stopping) {
            try {
                $this->runOnce();
                $attempt = 0;
            } catch (\Throwable $e) {
                $this->connected = false;
                $this->session = null;
                fwrite(STDERR, 'session ended: ' . $e->getMessage() . "\n");
                if (Reconnect::isFatalSessionError($e)) {
                    throw $e;
                }
            }
            if ($this->stopping) {
                break;
            }
            $delay = Reconnect::nextBackoff($attempt);
            $attempt++;
            fwrite(STDERR, "reconnecting attempt={$attempt} delay={$delay}s\n");
            $this->pumpDuringWait($delay);
        }

        foreach ($this->supervisor->all() as $rt) {
            $this->supervisor->cancel($rt->attemptId);
        }
        $deadline = microtime(true) + $this->cfg->shutdownTimeoutSeconds;
        while ($this->supervisor->count() > 0 && microtime(true) < $deadline) {
            $this->pumpChildren();
            usleep(50_000);
        }
    }

    /** CLI entry used by bin/jobcenter-worker (loads .env + builtin jobs). */
    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        EnvLoad::loadFirst(...EnvLoad::candidatePaths());
        try {
            $cfg = Config::fromEnv();
            $client = new self($cfg);
            Jobs\Builtin::register($client);
            $client->start();
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }

    private function runOnce(): void
    {
        $session = new Session($this->cfg->endpoint, 15.0);
        $session->connect();
        $this->session = $session;
        try {
            $auth = $this->doAuth($session);
            $this->instanceId = (string) ($auth['instance_id'] ?? '');
            $this->sessionEpoch = (int) ($auth['session_epoch'] ?? 0);
            $hb = (int) ($auth['heartbeat_interval_seconds'] ?? 10);
            $this->heartbeatInterval = $hb > 0 ? (float) $hb : 10.0;
            $this->connected = true;
            $this->doRegister($session);
            $this->nextHeartbeatAt = 0.0;
            $this->sendHeartbeat();
            $this->replayPending();
            $this->doSyncRunning();
            $this->flushSoftOutbox();

            while (!$this->stopping) {
                $this->pumpChildren();
                $now = microtime(true);
                if ($now >= $this->nextLogFlushAt) {
                    foreach ($this->logBufs as $buf) {
                        $buf->flush();
                    }
                    $this->nextLogFlushAt = $now + AttemptLogBuf::FLUSH_EVERY;
                }
                if ($now >= $this->nextHeartbeatAt) {
                    $this->sendHeartbeat();
                }
                try {
                    $env = $session->receive(1.0);
                } catch (\Throwable $e) {
                    throw $e;
                }
                if ($env !== null) {
                    $this->handleMessage($env);
                }
            }
            $this->doUnregister($session);
        } finally {
            $this->connected = false;
            $this->session = null;
            $session->close();
        }
    }

    private function doAuth(Session $session): array
    {
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canon = Auth::canonical($this->cfg->serviceCode, $this->cfg->credentialId, $this->cfg->instanceKey, $ts, $nonce);
        $sig = Auth::signHmac($this->cfg->credentialSecret, $canon);
        $session->send(MessageType::AUTH_REQUEST, [
            'method' => 'hmac',
            'code' => $this->cfg->serviceCode,
            'credential_id' => $this->cfg->credentialId,
            'instance_key' => $this->cfg->instanceKey,
            'timestamp' => $ts,
            'nonce' => $nonce,
            'signature' => $sig,
            'protocol_versions' => [Envelope::VERSION],
            'sdk' => ['language' => 'php', 'version' => $this->cfg->sdkVersion],
        ]);
        $env = $session->receiveType(MessageType::AUTH_RESPONSE, 10.0);
        $data = is_array($env->data) ? $env->data : [];
        if (!($data['ok'] ?? false)) {
            $code = is_array($data['error'] ?? null) ? (string) ($data['error']['code'] ?? '') : '';
            throw new \RuntimeException('auth rejected: ' . $code);
        }
        fwrite(STDERR, 'authenticated session_epoch=' . ($data['session_epoch'] ?? '') . ' instance_id=' . ($data['instance_id'] ?? '') . "\n");
        return $data;
    }

    private function doRegister(Session $session): void
    {
        $decls = [];
        foreach ($this->jobs as $j) {
            $d = [
                'job_code' => $j->code,
                'job_name' => $j->name,
                'handler' => $j->code,
                'handler_version' => $j->handlerVersion,
            ];
            if ($j->description !== '') {
                $d['description'] = $j->description;
            }
            if ($j->defaultCron !== '') {
                $d['default_cron'] = $j->defaultCron;
            }
            if ($j->timeoutSeconds > 0) {
                $d['timeout_seconds'] = $j->timeoutSeconds;
            }
            if ($j->maxConcurrency > 0) {
                $d['max_concurrency'] = $j->maxConcurrency;
            }
            if ($j->paramsSchema !== null) {
                $d['params_schema'] = $j->paramsSchema === [] ? new \stdClass() : $j->paramsSchema;
            }
            $decls[] = $d;
        }
        $host = gethostname() ?: 'host';
        $data = [
            'code' => $this->cfg->serviceCode,
            'instance_key' => $this->cfg->instanceKey,
            'environment' => $this->cfg->environment,
            'host' => ['hostname' => $host, 'pid' => getmypid()],
            'runtime' => ['language' => 'php', 'version' => PHP_VERSION],
            'deployment' => [
                'version' => $this->cfg->version,
                'git_commit' => $this->cfg->gitCommit,
                'build_time' => $this->cfg->buildTime,
            ],
            'capacity' => ['max_concurrency' => $this->cfg->maxConcurrency],
            'sdk_version' => $this->cfg->sdkVersion,
            'started_at' => Json::now(),
            'jobs' => $decls,
        ];
        $session->send(MessageType::SERVICE_REGISTER, $data);
        $env = $session->receiveType(MessageType::SERVICE_REGISTER_ACK, 10.0);
        $ack = is_array($env->data) ? $env->data : [];
        if (!($ack['ok'] ?? false)) {
            throw new \RuntimeException('register rejected: ' . Json::encode($ack['error'] ?? new \stdClass()));
        }
        fwrite(STDERR, 'registered accepted=' . count($ack['accepted_jobs'] ?? []) . ' status=' . ($ack['instance_status'] ?? '') . "\n");
    }

    private function doUnregister(Session $session): void
    {
        try {
            $session->send(MessageType::SERVICE_UNREGISTER, [
                'reason' => 'SHUTDOWN',
                'instance_key' => $this->cfg->instanceKey,
            ]);
        } catch (\Throwable) {
        }
    }

    private function sendHeartbeat(): void
    {
        $this->publishSoft(MessageType::SERVICE_HEARTBEAT, [
            'status' => 'ONLINE',
            'running' => $this->supervisor->count(),
            'max_concurrency' => $this->cfg->maxConcurrency,
            'instance_key' => $this->cfg->instanceKey,
        ]);
        $this->nextHeartbeatAt = microtime(true) + $this->heartbeatInterval;
    }

    private function handleMessage(Envelope $env): void
    {
        $data = is_array($env->data) ? $env->data : [];
        switch ($env->type) {
            case MessageType::SERVICE_HEARTBEAT_ACK:
                $this->connected = true;
                break;
            case MessageType::TASK_EXECUTE:
                $this->handleExecute($data);
                break;
            case MessageType::TASK_CANCEL:
                $this->handleCancel($data);
                break;
            case MessageType::TASK_RESULT_ACK:
                $this->onResultAck($data);
                break;
            case MessageType::TASK_SYNC_RUNNING_ACK:
                $this->syncAck = $data;
                $this->applySyncRunningAck($data);
                break;
            case MessageType::SYSTEM_NOTICE:
            case MessageType::SYSTEM_ERROR:
            case MessageType::AUTH_RESPONSE:
            case MessageType::SERVICE_REGISTER_ACK:
                break;
            default:
                break;
        }
    }

    /** @param array<string,mixed> $ex */
    private function handleExecute(array $ex): void
    {
        $attemptId = (string) ($ex['attempt_id'] ?? '');
        $lease = (string) ($ex['lease_token'] ?? '');
        $jobCode = (string) ($ex['job_code'] ?? '');

        if ($attemptId !== '' && isset($this->seenAttempts[$attemptId])) {
            $this->publishSoft(MessageType::TASK_EXECUTE_ACK, [
                'attempt_id' => $attemptId,
                'lease_token' => $lease,
                'accepted' => true,
                'duplicate' => true,
            ]);
            return;
        }
        if ($attemptId !== '') {
            $this->seenAttempts[$attemptId] = true;
        }

        if (!isset($this->jobs[$jobCode])) {
            $this->publishSoft(MessageType::TASK_EXECUTE_ACK, [
                'attempt_id' => $attemptId,
                'lease_token' => $lease,
                'accepted' => false,
                'error' => ['code' => 'UNKNOWN_JOB', 'message' => 'job not registered'],
            ]);
            return;
        }
        if ($this->supervisor->count() >= $this->cfg->maxConcurrency) {
            $this->publishSoft(MessageType::TASK_EXECUTE_ACK, [
                'attempt_id' => $attemptId,
                'lease_token' => $lease,
                'accepted' => false,
                'error' => ['code' => 'CAPACITY_FULL', 'message' => 'max concurrency reached'],
            ]);
            return;
        }

        $state = $ex['state'] ?? [];
        if (!is_array($state) || (array_is_list($state) && $state !== [])) {
            $state = [];
        }
        $params = $ex['params'] ?? [];
        if (!is_array($params) || (array_is_list($params) && $params !== [])) {
            $params = [];
        }

        $payload = [
            'execution_id' => (string) ($ex['execution_id'] ?? ''),
            'attempt_id' => $attemptId,
            'attempt_number' => (int) ($ex['attempt_number'] ?? 1),
            'lease_token' => $lease,
            'lease_expires_at' => (string) ($ex['lease_expires_at'] ?? ''),
            'job_code' => $jobCode,
            'job_name' => (string) ($ex['job_name'] ?? ''),
            'params' => $params === [] ? new \stdClass() : $params,
            'state' => $state === [] ? new \stdClass() : $state,
            'state_version' => (int) ($ex['state_version'] ?? 0),
            'timeout_seconds' => (int) ($ex['timeout_seconds'] ?? 0),
            'deadline_at' => (string) ($ex['deadline_at'] ?? ''),
            'trigger_type' => (string) ($ex['trigger_type'] ?? ''),
            'handler_version' => (string) ($ex['handler_version'] ?? ''),
        ];

        $this->supervisor->start($attemptId, $payload);
        $this->logBufs[$attemptId] = new AttemptLogBuf($attemptId, $lease, function (array $msg): void {
            $this->publishSoft(MessageType::TASK_LOG, $msg);
        });

        $this->publishSoft(MessageType::TASK_EXECUTE_ACK, [
            'attempt_id' => $attemptId,
            'lease_token' => $lease,
            'accepted' => true,
        ]);
        $this->publishSoft(MessageType::TASK_STARTED, [
            'attempt_id' => $attemptId,
            'lease_token' => $lease,
            'started_at' => Json::now(),
        ]);
        fwrite(STDERR, "job started job_code={$jobCode} attempt_id={$attemptId}\n");
    }

    /** @param array<string,mixed> $msg */
    private function handleCancel(array $msg): void
    {
        $attemptId = (string) ($msg['attempt_id'] ?? '');
        $lease = (string) ($msg['lease_token'] ?? '');
        $this->cancelRequested[$attemptId] = true;
        $ok = $this->supervisor->cancel($attemptId);
        if ($ok && $lease === '') {
            $rt = $this->supervisor->get($attemptId);
            $lease = $rt?->leaseToken ?? $lease;
        }
        $this->publishSoft(MessageType::TASK_CANCEL_ACK, [
            'attempt_id' => $attemptId,
            'lease_token' => $lease,
            'state' => $ok ? 'CANCELLING' : 'NOT_FOUND',
        ]);
    }

    private function pumpChildren(): void
    {
        foreach ($this->supervisor->all() as $rt) {
            // drain live frames before exit
        }
        // ProcessSupervisor::pump drains and returns finished
        // We need mid-run frames too. pump() already drains.
        // Collect new frames since last visit via $rt->frames — use a cursor.
        foreach ($this->supervisor->all() as $rt) {
            $this->consumeFrames($rt);
        }
        $finished = $this->supervisor->pump();
        foreach ($finished as $rt) {
            $this->consumeFrames($rt);
            $this->finishAttempt($rt);
        }
    }

    private function consumeFrames(RunningAttempt $rt): void
    {
        while ($rt->frames !== []) {
            $frame = array_shift($rt->frames);
            $type = (string) ($frame['type'] ?? '');
            if ($type === 'progress') {
                $this->publishSoft(MessageType::TASK_PROGRESS, [
                    'attempt_id' => $rt->attemptId,
                    'lease_token' => $rt->leaseToken,
                    'percent' => isset($frame['percent']) ? (float) $frame['percent'] : null,
                    'message' => (string) ($frame['message'] ?? ''),
                ]);
            } elseif ($type === 'log') {
                $buf = $this->logBufs[$rt->attemptId] ?? null;
                $fields = is_array($frame['fields'] ?? null) ? $frame['fields'] : null;
                if ($buf !== null) {
                    $buf->append((string) ($frame['level'] ?? 'INFO'), (string) ($frame['message'] ?? ''), $fields);
                }
            } elseif ($type === 'checkpoint') {
                $state = $frame['state'] ?? new \stdClass();
                if (is_array($state) && $state === []) {
                    $state = new \stdClass();
                }
                $this->publishSoft(MessageType::TASK_CHECKPOINT, [
                    'attempt_id' => $rt->attemptId,
                    'lease_token' => $rt->leaseToken,
                    'state' => $state,
                ]);
            }
        }
    }

    private function finishAttempt(RunningAttempt $rt): void
    {
        $buf = $this->logBufs[$rt->attemptId] ?? null;
        if ($buf !== null) {
            $buf->closeAndFlush();
            unset($this->logBufs[$rt->attemptId]);
        }
        $finished = Json::now();
        $lease = $rt->leaseToken;
        $cancelReq = isset($this->cancelRequested[$rt->attemptId]) || $rt->cancelRequested;
        $term = $rt->childTerminal;

        if ($term === 'cancelled' || ($cancelReq && ($term === 'cancelled' || $term === null))) {
            fwrite(STDERR, "job cancelled job_code={$rt->jobCode} attempt_id={$rt->attemptId}\n");
            $this->publishTerminal($rt->attemptId, MessageType::TASK_CANCELLED, [
                'attempt_id' => $rt->attemptId,
                'lease_token' => $lease,
                'finished_at' => $finished,
                'stopped_by' => 'COOPERATIVE',
            ]);
            return;
        }
        if ($term === 'timeout') {
            $code = $cancelReq ? 'CANCEL_NOT_HONORED' : 'TIMEOUT';
            $msg = $cancelReq ? 'handler ignored cancel' : 'deadline exceeded';
            fwrite(STDERR, "job timed out job_code={$rt->jobCode} attempt_id={$rt->attemptId} code={$code}\n");
            $this->publishTerminal($rt->attemptId, MessageType::TASK_FAILED, [
                'attempt_id' => $rt->attemptId,
                'lease_token' => $lease,
                'finished_at' => $finished,
                'reason' => 'TIMEOUT',
                'error' => ['code' => $code, 'message' => $msg],
            ]);
            return;
        }
        if ($term === 'error' || $term === null) {
            $code = (string) (($rt->error['code'] ?? '') ?: 'HANDLER_ERROR');
            $message = (string) (($rt->error['message'] ?? '') ?: ('exit ' . $rt->process->getExitCode()));
            $retryable = (bool) ($rt->error['retryable'] ?? false);
            fwrite(STDERR, "job failed job_code={$rt->jobCode} attempt_id={$rt->attemptId} code={$code}\n");
            $err = ['code' => $code, 'message' => $message, 'retryable' => $retryable];
            $this->publishTerminal($rt->attemptId, MessageType::TASK_FAILED, [
                'attempt_id' => $rt->attemptId,
                'lease_token' => $lease,
                'finished_at' => $finished,
                'reason' => 'HANDLER_ERROR',
                'error' => $err,
            ]);
            return;
        }
        fwrite(STDERR, "job completed job_code={$rt->jobCode} attempt_id={$rt->attemptId}\n");
        $data = [
            'attempt_id' => $rt->attemptId,
            'lease_token' => $lease,
            'finished_at' => $finished,
        ];
        if ($rt->result !== null) {
            $data['result'] = $rt->result === [] ? new \stdClass() : $rt->result;
        }
        if ($rt->nextState !== null) {
            $data['next_state'] = $rt->nextState === [] ? new \stdClass() : $rt->nextState;
        }
        $this->publishTerminal($rt->attemptId, MessageType::TASK_COMPLETED, $data);
    }

    /** @param mixed $data */
    private function publishSoft(string $type, $data): void
    {
        if ($this->trySessionWrite($type, $data)) {
            return;
        }
        if (count($this->softBuf) >= self::SOFT_OUTBOX_LIMIT) {
            if ($type === MessageType::TASK_PROGRESS || $type === MessageType::TASK_LOG) {
                return;
            }
            $kept = [];
            $dropped = false;
            foreach ($this->softBuf as $m) {
                if (!$dropped && ($m['type'] === MessageType::TASK_PROGRESS || $m['type'] === MessageType::TASK_LOG)) {
                    $dropped = true;
                    continue;
                }
                $kept[] = $m;
            }
            $this->softBuf = $kept;
            if (count($this->softBuf) >= self::SOFT_OUTBOX_LIMIT) {
                array_shift($this->softBuf);
            }
        }
        $this->softBuf[] = ['type' => $type, 'data' => $data];
    }

    /** @param mixed $data */
    private function publishTerminal(string $attemptId, string $type, $data): void
    {
        $this->pending->save($attemptId, $type, $data);
        $this->trySessionWrite($type, $data);
    }

    /** @param mixed $data */
    private function trySessionWrite(string $type, $data): bool
    {
        if ($this->session === null || !$this->session->isOpen()) {
            return false;
        }
        try {
            $this->session->send($type, $data);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function flushSoftOutbox(): void
    {
        $buf = $this->softBuf;
        $this->softBuf = [];
        foreach ($buf as $i => $m) {
            if (!$this->trySessionWrite($m['type'], $m['data'])) {
                $this->softBuf = array_merge(array_slice($buf, $i), $this->softBuf);
                return;
            }
        }
    }

    private function replayPending(): void
    {
        foreach ($this->pending->list() as $rec) {
            if (!$this->trySessionWrite($rec['type'], $rec['data'])) {
                fwrite(STDERR, "replay pending failed attempt_id={$rec['attempt_id']} type={$rec['type']}\n");
                return;
            }
            fwrite(STDERR, "replayed pending result attempt_id={$rec['attempt_id']} type={$rec['type']}\n");
        }
    }

    /** @param array<string,mixed> $ack */
    private function onResultAck(array $ack): void
    {
        $attemptId = (string) ($ack['attempt_id'] ?? '');
        if ($attemptId === '') {
            return;
        }
        $status = (string) ($ack['status'] ?? '');
        if ($status === 'OK') {
            $this->pending->remove($attemptId);
            return;
        }
        if ($status === 'REJECTED' && ($ack['error_code'] ?? '') === 'ALREADY_FINISHED') {
            $this->pending->remove($attemptId);
            $this->supervisor->cancel($attemptId);
        }
    }

    private function doSyncRunning(): void
    {
        $items = [];
        foreach ($this->supervisor->all() as $rt) {
            $items[] = [
                'execution_id' => $rt->executionId,
                'attempt_id' => $rt->attemptId,
                'lease_token' => $rt->leaseToken,
            ];
        }
        if ($items === []) {
            return;
        }
        $this->syncAck = null;
        if (!$this->trySessionWrite(MessageType::TASK_SYNC_RUNNING, ['attempts' => $items])) {
            fwrite(STDERR, "sync_running send failed\n");
            return;
        }
        $deadline = microtime(true) + 10;
        while ($this->syncAck === null && microtime(true) < $deadline && $this->session !== null) {
            try {
                $env = $this->session->receive(1.0);
            } catch (\Throwable $e) {
                fwrite(STDERR, 'sync_running wait: ' . $e->getMessage() . "\n");
                return;
            }
            if ($env !== null) {
                $this->handleMessage($env);
            }
        }
        if ($this->syncAck === null) {
            fwrite(STDERR, "sync_running ack timeout\n");
        }
    }

    /** @param array<string,mixed> $ack */
    private function applySyncRunningAck(array $ack): void
    {
        $results = is_array($ack['results'] ?? null) ? $ack['results'] : [];
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = (string) ($r['attempt_id'] ?? '');
            $status = (string) ($r['status'] ?? '');
            if ($status === 'GONE') {
                fwrite(STDERR, "sync_running gone attempt_id={$id}\n");
                $this->supervisor->cancel($id);
                $this->pending->remove($id);
            } elseif ($status === 'REJECTED') {
                fwrite(STDERR, "sync_running rejected attempt_id={$id} msg=" . ($r['message'] ?? '') . "\n");
            }
        }
    }

    private function pumpDuringWait(float $seconds): void
    {
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until && !$this->stopping) {
            $this->pumpChildren();
            $now = microtime(true);
            if ($now >= $this->nextLogFlushAt) {
                foreach ($this->logBufs as $buf) {
                    $buf->flush();
                }
                $this->nextLogFlushAt = $now + AttemptLogBuf::FLUSH_EVERY;
            }
            usleep(100_000);
        }
    }
}
