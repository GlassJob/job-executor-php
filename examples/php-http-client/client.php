#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Runnable Admin HTTP client example.
 *
 * Default invocation prints usage and does not call the API.
 * Read-only commands: services, jobs, executions, get, logs
 * Mutations require an explicit verb: trigger, cancel
 *
 *   php examples/php-http-client/client.php services
 *   php examples/php-http-client/client.php trigger --service-code sdk.demo --job-code demo.hello
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use JobCenter\EnvLoad;
use JobCenter\Http\ApiException;
use JobCenter\Http\Client;
use JobCenter\Http\Config;
use JobCenter\Http\InvalidConfigException;
use JobCenter\Http\TransportException;

EnvLoad::loadFirst(...EnvLoad::candidatePaths());

$args = array_slice($argv, 1);
$command = $args[0] ?? 'help';
$opts = parseExampleOpts(array_slice($args, 1));

if ($command === 'help' || $command === '--help' || $command === '-h') {
    fwrite(STDOUT, <<<'TXT'
Admin HTTP client example (does not start the Executor Worker).

Usage:
  php examples/php-http-client/client.php <command> [options]

Read-only:
  services [--keyword ...] [--page N] [--limit N]
  jobs --service-id ID | --service-code CODE [--keyword ...]
  executions --service-id ID --job-id ID | --service-code CODE --job-code CODE
  get --service-id ID --job-id ID --execution-id ID
  logs --service-id ID --job-id ID --execution-id ID [--after N] [--limit N]

Mutations (must be named explicitly):
  trigger --service-code CODE --job-code CODE [--params JSON] [--idempotency-key K] [--instance-id ID]
  cancel  --service-id ID --job-id ID --execution-id ID [--reason TEXT]

Config (distinct from Executor JC_CREDENTIAL_*):
  JC_HTTP_BASE_URL          default http://127.0.0.1:8080
  JC_HTTP_CLIENT_ID
  JC_HTTP_CLIENT_SECRET
  JC_HTTP_OPERATOR_ID       optional
  JC_HTTP_OPERATOR_NAME     optional
  JC_HTTP_TIMEOUT_SECONDS   optional

Log streaming over SSE is not supported by Job Center; use the logs command (chunk).

TXT);
    exit(0);
}

try {
    $cfg = Config::fromEnv();
    $client = new Client($cfg);
    $code = runCommand($client, $command, $opts);
    exit($code);
} catch (InvalidConfigException $e) {
    fwrite(STDERR, '配置无效：' . $e->getMessage() . "\n");
    exit(2);
} catch (ApiException $e) {
    fwrite(STDERR, 'API 错误：' . $e->apiCode . ' HTTP ' . $e->httpStatus . ' ' . $e->getMessage() . "\n");
    exit(1);
} catch (TransportException $e) {
    fwrite(STDERR, '传输错误：' . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, '错误：' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<string,string> $opts
 */
function runCommand(Client $client, string $command, array $opts): int
{
    switch ($command) {
        case 'services':
            $page = $client->listServices(
                page: isset($opts['page']) ? (int) $opts['page'] : null,
                limit: isset($opts['limit']) ? (int) $opts['limit'] : null,
                keyword: $opts['keyword'] ?? null,
            );
            printJson(['total' => $page->total, 'list' => $page->list]);
            return 0;
        case 'jobs':
            [$serviceId] = resolveService($client, $opts);
            $page = $client->listServiceJobs($serviceId, array_filter([
                'page' => isset($opts['page']) ? (int) $opts['page'] : null,
                'limit' => isset($opts['limit']) ? (int) $opts['limit'] : null,
                'keyword' => $opts['keyword'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            printJson(['total' => $page->total, 'list' => $page->list]);
            return 0;
        case 'executions':
            [$serviceId, $jobId] = resolveServiceJob($client, $opts);
            $page = $client->listJobExecutions($serviceId, $jobId, array_filter([
                'page' => isset($opts['page']) ? (int) $opts['page'] : null,
                'limit' => isset($opts['limit']) ? (int) $opts['limit'] : null,
                'status' => $opts['status'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''));
            printJson(['total' => $page->total, 'list' => $page->list]);
            return 0;
        case 'get':
            [$serviceId, $jobId, $executionId] = resolveExecution($client, $opts);
            printJson($client->getExecution($serviceId, $jobId, $executionId));
            return 0;
        case 'logs':
            [$serviceId, $jobId, $executionId] = resolveExecution($client, $opts);
            $page = $client->getExecutionLogs(
                $serviceId,
                $jobId,
                $executionId,
                after: isset($opts['after']) ? (int) $opts['after'] : null,
                limit: isset($opts['limit']) ? (int) $opts['limit'] : null,
                attemptId: $opts['attempt-id'] ?? null,
            );
            printJson(['total' => $page->total, 'list' => $page->list]);
            return 0;
        case 'trigger':
            $params = [];
            if (isset($opts['params']) && $opts['params'] !== '') {
                $decoded = json_decode($opts['params'], true);
                if (!is_array($decoded) || array_is_list($decoded)) {
                    throw new \InvalidArgumentException('--params must be a JSON object');
                }
                $params = $decoded;
            }
            if (isset($opts['service-id'], $opts['job-id'])) {
                $out = $client->triggerExecution(
                    $opts['service-id'],
                    $opts['job-id'],
                    $params === [] ? null : $params,
                    $opts['idempotency-key'] ?? null,
                    $opts['instance-id'] ?? null,
                );
            } else {
                $serviceCode = $opts['service-code'] ?? '';
                $jobCode = $opts['job-code'] ?? '';
                if ($serviceCode === '' || $jobCode === '') {
                    throw new \InvalidArgumentException('trigger requires --service-code and --job-code, or --service-id and --job-id');
                }
                $out = $client->triggerExecutionByCode(
                    $serviceCode,
                    $jobCode,
                    $params === [] ? null : $params,
                    $opts['idempotency-key'] ?? null,
                    $opts['instance-id'] ?? null,
                );
            }
            printJson($out);
            return 0;
        case 'cancel':
            [$serviceId, $jobId, $executionId] = resolveExecution($client, $opts);
            printJson($client->cancelExecution($serviceId, $jobId, $executionId, $opts['reason'] ?? null));
            return 0;
        default:
            fwrite(STDERR, "未知命令：{$command}（使用 help 查看用法）\n");
            return 2;
    }
}

/**
 * @param array<string,string> $opts
 * @return array{0:string}
 */
function resolveService(Client $client, array $opts): array
{
    if (!empty($opts['service-id'])) {
        return [$opts['service-id']];
    }
    if (empty($opts['service-code'])) {
        throw new \InvalidArgumentException('require --service-id or --service-code');
    }
    $svc = $client->findServiceByCode($opts['service-code']);
    if ($svc === null) {
        throw new \RuntimeException('service not found: ' . $opts['service-code']);
    }
    return [(string) $svc['id']];
}

/**
 * @param array<string,string> $opts
 * @return array{0:string,1:string}
 */
function resolveServiceJob(Client $client, array $opts): array
{
    if (!empty($opts['service-id']) && !empty($opts['job-id'])) {
        return [$opts['service-id'], $opts['job-id']];
    }
    [$serviceId] = resolveService($client, $opts);
    if (empty($opts['job-code']) && empty($opts['job-id'])) {
        throw new \InvalidArgumentException('require --job-id or --job-code');
    }
    if (!empty($opts['job-id'])) {
        return [$serviceId, $opts['job-id']];
    }
    $job = $client->findJobByCode($serviceId, $opts['job-code']);
    if ($job === null) {
        throw new \RuntimeException('job not found: ' . $opts['job-code']);
    }
    return [$serviceId, (string) $job['id']];
}

/**
 * @param array<string,string> $opts
 * @return array{0:string,1:string,2:string}
 */
function resolveExecution(Client $client, array $opts): array
{
    if (empty($opts['execution-id'])) {
        throw new \InvalidArgumentException('require --execution-id');
    }
    [$serviceId, $jobId] = resolveServiceJob($client, $opts);
    return [$serviceId, $jobId, $opts['execution-id']];
}

/**
 * @param list<string> $argv
 * @return array<string,string>
 */
function parseExampleOpts(array $argv): array
{
    $opts = [];
    $n = count($argv);
    for ($i = 0; $i < $n; $i++) {
        $arg = $argv[$i];
        if (!str_starts_with($arg, '--')) {
            throw new \InvalidArgumentException('unexpected argument: ' . $arg);
        }
        $name = substr($arg, 2);
        $value = '1';
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
        } elseif ($i + 1 < $n && !str_starts_with($argv[$i + 1], '--')) {
            $value = $argv[++$i];
        }
        $opts[$name] = $value;
    }
    return $opts;
}

function printJson(mixed $value): void
{
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}
