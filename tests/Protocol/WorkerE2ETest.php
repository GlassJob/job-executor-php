<?php
declare(strict_types=1);

namespace JobCenter\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Mock Job Center covering HMAC auth, register, execute hello, and cancel sleep.
 */
final class WorkerE2ETest extends TestCase
{
    public function testAuthRegisterHelloAndCancelSleep(): void
    {
        $root = dirname(__DIR__, 2);
        $portFile = tempnam(sys_get_temp_dir(), 'jc-port-');
        $okFile = tempnam(sys_get_temp_dir(), 'jc-ok-');
        @unlink($okFile);
        $dataDir = sys_get_temp_dir() . '/jc-data-' . bin2hex(random_bytes(4));
        mkdir($dataDir, 0755, true);

        $service = 'sdk.demo';
        $credId = 'cred-e2e';
        $secret = 's3cret';
        $instance = 'php-e2e-1';

        $server = new Process([
            PHP_BINARY,
            $root . '/tests/Support/mock-center.php',
            $root . '/vendor/autoload.php',
            $portFile,
            $okFile,
            $service,
            $credId,
            $secret,
        ], $root);
        $server->start();

        $worker = null;
        try {
            $deadline = microtime(true) + 5;
            $port = 0;
            while (microtime(true) < $deadline) {
                $raw = is_file($portFile) ? trim((string) file_get_contents($portFile)) : '';
                if ($raw !== '' && ctype_digit($raw) && (int) $raw > 0) {
                    $port = (int) $raw;
                    break;
                }
                usleep(20_000);
            }
            self::assertGreaterThan(0, $port, 'mock center did not publish a port: ' . $server->getErrorOutput());

            $worker = new Process([PHP_BINARY, $root . '/worker.php'], $root, [
                'JC_WS_ENDPOINT' => "ws://127.0.0.1:{$port}/v1/executor/ws",
                'JC_SERVICE_CODE' => $service,
                'JC_CREDENTIAL_ID' => $credId,
                'JC_CREDENTIAL_SECRET' => $secret,
                'JC_INSTANCE_KEY' => $instance,
                'JC_ENVIRONMENT' => 'dev',
                'JC_DATA_DIR' => $dataDir,
                'JC_MAX_CONCURRENCY' => '2',
            ]);
            $worker->start();

            $until = microtime(true) + 12;
            while (microtime(true) < $until && !is_file($okFile)) {
                usleep(50_000);
            }
            self::assertFileExists($okFile, "e2e did not finish.\nserver stderr: {$server->getErrorOutput()}\nworker stderr: " . ($worker?->getErrorOutput() ?? ''));
        } finally {
            if ($worker !== null && $worker->isRunning()) {
                $worker->stop(2, SIGTERM);
            }
            if ($server->isRunning()) {
                $server->stop(1, SIGTERM);
            }
        }
    }
}
