<?php
declare(strict_types=1);

namespace JobCenter;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class ProcessSupervisor
{
    /** @var array<string,RunningAttempt> */
    private array $running = [];

    public function __construct(
        private string $phpBinary,
        private string $runnerBin,
        private string $registryPath,
        private string $cwd,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function start(string $attemptId, array $payload): RunningAttempt
    {
        if (isset($this->running[$attemptId])) {
            throw new \RuntimeException('attempt already running: ' . $attemptId);
        }
        $stdin = new InputStream();
        $env = $_ENV + getenv() + [
            'JC_REGISTRY' => $this->registryPath,
        ];
        // getenv() may return array; normalize string env
        $clean = [];
        foreach ($env as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $clean[$k] = (string) $v;
            }
        }
        $clean['JC_REGISTRY'] = $this->registryPath;
        $process = new Process(
            [$this->phpBinary, $this->runnerBin],
            $this->cwd,
            $clean,
            $stdin,
        );
        $process->setTimeout(null);
        $process->start();
        $stdin->write(Json::encode($payload) . "\n");
        $rt = new RunningAttempt(
            attemptId: $attemptId,
            executionId: (string) ($payload['execution_id'] ?? ''),
            leaseToken: (string) ($payload['lease_token'] ?? ''),
            jobCode: (string) ($payload['job_code'] ?? ''),
            process: $process,
            stdin: $stdin,
        );
        $this->running[$attemptId] = $rt;
        return $rt;
    }

    public function cancel(string $attemptId): bool
    {
        $rt = $this->running[$attemptId] ?? null;
        if ($rt === null) {
            return false;
        }
        $rt->cancelRequested = true;
        try {
            $rt->stdin->write(ChildProtocol::encode(['type' => 'cancel']));
        } catch (\Throwable) {
        }
        $rt->process->isRunning(); // flush stdin
        if ($rt->process->isRunning()) {
            $rt->process->signal(\SIGTERM);
        }
        return true;
    }

    public function get(string $attemptId): ?RunningAttempt
    {
        return $this->running[$attemptId] ?? null;
    }

    /** @return array<string,RunningAttempt> */
    public function all(): array
    {
        return $this->running;
    }

    public function count(): int
    {
        return count($this->running);
    }

    /**
     * Drain stdout of all children and collect finished attempts.
     *
     * @return list<RunningAttempt> finished (removed from running)
     */
    public function pump(): array
    {
        $finished = [];
        foreach ($this->running as $id => $rt) {
            $this->drain($rt);
            $running = $rt->process->isRunning();
            $this->drain($rt);
            if (!$running) {
                $rt->stdin->close();
                $finished[] = $rt;
                unset($this->running[$id]);
            }
        }
        return $finished;
    }

    private function drain(RunningAttempt $rt): void
    {
        $chunk = $rt->process->getIncrementalOutput();
        if ($chunk !== '') {
            $rt->stdoutBuf .= $chunk;
            while (($pos = strpos($rt->stdoutBuf, "\n")) !== false) {
                $line = substr($rt->stdoutBuf, 0, $pos + 1);
                $rt->stdoutBuf = substr($rt->stdoutBuf, $pos + 1);
                $frame = ChildProtocol::tryDecodeLine($line);
                if ($frame === null) {
                    continue;
                }
                $rt->frames[] = $frame;
                $type = (string) ($frame['type'] ?? '');
                if ($type === 'checkpoint' || $type === 'progress' || $type === 'log') {
                    continue;
                }
                if ($type === 'done') {
                    $rt->childTerminal = 'done';
                    $rt->result = is_array($frame['result'] ?? null) ? $frame['result'] : null;
                    $rt->nextState = is_array($frame['next_state'] ?? null) ? $frame['next_state'] : null;
                } elseif ($type === 'error') {
                    $rt->childTerminal = 'error';
                    $rt->error = $frame;
                } elseif ($type === 'cancelled') {
                    $rt->childTerminal = 'cancelled';
                } elseif ($type === 'timeout') {
                    $rt->childTerminal = 'timeout';
                    $rt->error = $frame;
                }
            }
        }
    }
}
