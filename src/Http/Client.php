<?php
declare(strict_types=1);

namespace JobCenter\Http;

use JobCenter\Json;

/**
 * Job Center Admin HTTP client (HMAC, protocol 1.1). Independent of the Executor Worker.
 *
 * Log retrieval is chunk-based (GET .../logs). The current center does not expose SSE.
 */
final class Client
{
    /** @var \Closure():int */
    private \Closure $clock;

    /** @var \Closure():string */
    private \Closure $nonce;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport = new StreamTransport(),
        ?\Closure $clock = null,
        ?\Closure $nonce = null,
    ) {
        $this->config->validate();
        $this->clock = $clock ?? static fn(): int => time();
        $this->nonce = $nonce ?? static fn(): string => bin2hex(random_bytes(16));
    }

    /**
     * @return array<string,mixed>
     */
    public function listServices(?int $page = null, ?int $limit = null, ?string $keyword = null): ListPage
    {
        return $this->getList('/v1/admin/services', self::pageQuery($page, $limit, $keyword));
    }

    /**
     * @return array<string,mixed>
     */
    public function getService(string $serviceId): array
    {
        return $this->getObject('/v1/admin/services/' . rawurlencode($this->id($serviceId, 'serviceId')));
    }

    /**
     * Resolve a service by exact `code`, using list + keyword then filtering.
     *
     * @return array<string,mixed>|null
     */
    public function findServiceByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException('service code is required');
        }
        $pageNo = 1;
        $seen = 0;
        do {
            $page = $this->listServices(page: $pageNo, limit: 100, keyword: $code);
            foreach ($page->list as $row) {
                if (($row['code'] ?? '') === $code) {
                    return $row;
                }
            }
            $seen += count($page->list);
            $pageNo++;
        } while ($page->list !== [] && $seen < $page->total);
        return null;
    }

    /**
     * Global job list: GET /v1/admin/jobs
     *
     * @param array{
     *   page?:int, limit?:int, keyword?:string, service_id?:string,
     *   status?:string, enabled?:bool|string
     * } $query
     */
    public function listJobs(array $query = []): ListPage
    {
        return $this->getList('/v1/admin/jobs', self::filterQuery($query, [
            'page', 'limit', 'keyword', 'service_id', 'status', 'enabled',
        ]));
    }

    /**
     * Jobs under a service: GET /v1/admin/services/{serviceId}/jobs
     *
     * `status` and `enabled` are accepted by the current center handler.
     *
     * @param array{page?:int, limit?:int, keyword?:string, status?:string, enabled?:bool|string} $query
     */
    public function listServiceJobs(string $serviceId, array $query = []): ListPage
    {
        $sid = $this->id($serviceId, 'serviceId');
        return $this->getList(
            '/v1/admin/services/' . rawurlencode($sid) . '/jobs',
            self::filterQuery($query, ['page', 'limit', 'keyword', 'status', 'enabled']),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function getJob(string $serviceId, string $jobId): array
    {
        $sid = $this->id($serviceId, 'serviceId');
        $jid = $this->id($jobId, 'jobId');
        return $this->getObject(
            '/v1/admin/services/' . rawurlencode($sid) . '/jobs/' . rawurlencode($jid),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findJobByCode(string $serviceId, string $jobCode): ?array
    {
        $jobCode = trim($jobCode);
        if ($jobCode === '') {
            throw new \InvalidArgumentException('job code is required');
        }
        $sid = $this->id($serviceId, 'serviceId');
        $pageNo = 1;
        $seen = 0;
        do {
            $page = $this->listServiceJobs($sid, ['page' => $pageNo, 'limit' => 100, 'keyword' => $jobCode]);
            foreach ($page->list as $row) {
                if (($row['code'] ?? '') === $jobCode) {
                    return $row;
                }
            }
            $seen += count($page->list);
            $pageNo++;
        } while ($page->list !== [] && $seen < $page->total);
        return null;
    }

    /**
     * POST /v1/admin/services/{serviceId}/jobs/{jobId}/executions
     *
     * Targeting is `instance_id` when the center supports preferring an instance.
     * `idempotency_key` is unique per job; a replay is indicated by `idempotent_replay`.
     * This method is not retried by the client.
     *
     * @param array<string,mixed>|object|null $params
     * @return array<string,mixed>
     */
    public function triggerExecution(
        string $serviceId,
        string $jobId,
        array|object|null $params = null,
        ?string $idempotencyKey = null,
        ?string $instanceId = null,
    ): array {
        $sid = $this->id($serviceId, 'serviceId');
        $jid = $this->id($jobId, 'jobId');
        $payload = [];
        if ($params !== null) {
            $payload['params'] = Json::asObject($params);
        }
        $key = $idempotencyKey !== null ? trim($idempotencyKey) : '';
        if ($key !== '') {
            $payload['idempotency_key'] = $key;
        }
        $inst = $instanceId !== null ? trim($instanceId) : '';
        if ($inst !== '') {
            $payload['instance_id'] = $inst;
        }
        $body = Json::encode($payload === [] ? new \stdClass() : $payload, true);
        return $this->requestObject(
            'POST',
            '/v1/admin/services/' . rawurlencode($sid) . '/jobs/' . rawurlencode($jid) . '/executions',
            '',
            $body,
        );
    }

    /**
     * Discover IDs by service/job code, then trigger. Still one POST; lookup uses GET only.
     *
     * @param array<string,mixed>|object|null $params
     * @return array<string,mixed>
     */
    public function triggerExecutionByCode(
        string $serviceCode,
        string $jobCode,
        array|object|null $params = null,
        ?string $idempotencyKey = null,
        ?string $instanceId = null,
    ): array {
        $svc = $this->findServiceByCode($serviceCode);
        if ($svc === null || empty($svc['id'])) {
            throw new ApiException('service not found: ' . $serviceCode, 404, 40400);
        }
        $job = $this->findJobByCode((string) $svc['id'], $jobCode);
        if ($job === null || empty($job['id'])) {
            throw new ApiException('job not found: ' . $jobCode, 404, 40400);
        }
        return $this->triggerExecution((string) $svc['id'], (string) $job['id'], $params, $idempotencyKey, $instanceId);
    }

    /**
     * @return array<string,mixed>
     */
    public function getExecution(string $serviceId, string $jobId, string $executionId): array
    {
        $sid = $this->id($serviceId, 'serviceId');
        $jid = $this->id($jobId, 'jobId');
        $eid = $this->id($executionId, 'executionId');
        return $this->getObject(
            '/v1/admin/services/' . rawurlencode($sid) . '/jobs/' . rawurlencode($jid) . '/executions/' . rawurlencode($eid),
        );
    }

    /**
     * Global execution list: GET /v1/admin/executions
     *
     * @param array{
     *   page?:int, limit?:int, keyword?:string, service_id?:string, job_id?:string,
     *   status?:string, instance_id?:string, created_from?:string, created_to?:string
     * } $query
     */
    public function listExecutions(array $query = []): ListPage
    {
        return $this->getList('/v1/admin/executions', self::filterQuery($query, [
            'page', 'limit', 'keyword', 'service_id', 'job_id', 'status',
            'instance_id', 'created_from', 'created_to',
        ]));
    }

    /**
     * @param array{
     *   page?:int, limit?:int, keyword?:string, status?:string,
     *   instance_id?:string, created_from?:string, created_to?:string
     * } $query
     */
    public function listJobExecutions(string $serviceId, string $jobId, array $query = []): ListPage
    {
        $sid = $this->id($serviceId, 'serviceId');
        $jid = $this->id($jobId, 'jobId');
        return $this->getList(
            '/v1/admin/services/' . rawurlencode($sid) . '/jobs/' . rawurlencode($jid) . '/executions',
            self::filterQuery($query, [
                'page', 'limit', 'keyword', 'status', 'instance_id', 'created_from', 'created_to',
            ]),
        );
    }

    /**
     * POST .../executions/{executionId}/cancel. Not retried by the client.
     * Omit $reason to send an empty body (the center accepts a missing body).
     *
     * @return array<string,mixed>
     */
    public function cancelExecution(string $serviceId, string $jobId, string $executionId, ?string $reason = null): array
    {
        $sid = $this->id($serviceId, 'serviceId');
        $jid = $this->id($jobId, 'jobId');
        $eid = $this->id($executionId, 'executionId');
        $body = '';
        if ($reason !== null) {
            $body = Json::encode(['reason' => $reason], true);
        }
        return $this->requestObject(
            'POST',
            '/v1/admin/services/' . rawurlencode($sid) . '/jobs/' . rawurlencode($jid)
            . '/executions/' . rawurlencode($eid) . '/cancel',
            '',
            $body,
        );
    }

    /**
     * GET .../executions/{executionId}/logs (chunk, not SSE).
     * `after` is the sequence cursor; the center returns entries with sequence greater than it.
     */
    public function getExecutionLogs(
        string $serviceId,
        string $jobId,
        string $executionId,
        ?int $after = null,
        ?int $limit = null,
        ?string $attemptId = null,
    ): ListPage {
        $sid = $this->id($serviceId, 'serviceId');
        $jid = $this->id($jobId, 'jobId');
        $eid = $this->id($executionId, 'executionId');
        $query = [];
        if ($after !== null) {
            $query['after'] = $after;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
        }
        if ($attemptId !== null && $attemptId !== '') {
            $query['attempt_id'] = $attemptId;
        }
        return $this->getList(
            '/v1/admin/services/' . rawurlencode($sid) . '/jobs/' . rawurlencode($jid)
            . '/executions/' . rawurlencode($eid) . '/logs',
            $query,
        );
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query
     */
    private function getList(string $path, array $query): ListPage
    {
        $rawQuery = Signer::encodeQuery($query);
        $data = $this->requestData('GET', $path, $rawQuery, '', $this->signedPath($path));
        if (!is_array($data) || !array_key_exists('list', $data) || !array_key_exists('total', $data)) {
            throw new TransportException('response data is not a list envelope');
        }
        $list = $data['list'];
        if (!is_array($list)) {
            throw new TransportException('response list is invalid');
        }
        $rows = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                throw new TransportException('response list item is invalid');
            }
            /** @var array<string,mixed> $row */
            $rows[] = $row;
        }
        return new ListPage($rows, (int) $data['total']);
    }

    /**
     * @return array<string,mixed>
     */
    private function getObject(string $path): array
    {
        return $this->requestObject('GET', $path, '', '');
    }

    /**
     * @return array<string,mixed>
     */
    private function requestObject(string $method, string $path, string $rawQuery, string $body): array
    {
        $data = $this->requestData($method, $path, $rawQuery, $body, $this->signedPath($path));
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new TransportException('response data is not an object');
        }
        /** @var array<string,mixed> $data */
        return $data;
    }

    private function requestData(string $method, string $path, string $rawQuery, string $body, string $signedPath): mixed
    {
        $envelope = $this->send($method, $path, $rawQuery, $body, $signedPath);
        return $envelope['data'] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, string $rawQuery, string $body, string $signedPath): array
    {
        $timestamp = (string) ($this->clock)();
        $nonce = ($this->nonce)();
        $headers = Signer::headers(
            $method,
            $signedPath,
            $rawQuery,
            $this->config->clientId,
            $this->config->clientSecret(),
            $timestamp,
            $nonce,
            $this->config->operatorId,
            $this->config->operatorName,
            $body,
        );
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = Config::USER_AGENT;
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }
        $url = $this->config->baseUrl . $path;
        if ($rawQuery !== '') {
            $url .= '?' . $rawQuery;
        }
        try {
            $resp = $this->transport->request($method, $url, $headers, $body, $this->config->timeoutSeconds);
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException('HTTP transport failed', 0, $e);
        }
        return $this->decodeEnvelope($resp);
    }

    /**
     * Signed PATH is the decoded path (UUIDs and raw IDs). Wire URL uses encoded segments.
     */
    private function signedPath(string $encodedPath): string
    {
        $parts = explode('/', $encodedPath);
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            $parts[$i] = rawurldecode($part);
        }
        return implode('/', $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeEnvelope(TransportResponse $resp): array
    {
        $raw = $resp->body;
        if ($raw === '') {
            throw new TransportException('empty HTTP response body (status ' . $resp->status . ')');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException('invalid JSON in HTTP response (status ' . $resp->status . ')', 0, $e);
        }
        if (!is_array($decoded) || !array_key_exists('code', $decoded)) {
            if ($resp->status >= 400) {
                throw new ApiException('HTTP ' . $resp->status, $resp->status, $resp->status);
            }
            throw new TransportException('response is not a Job Center envelope');
        }
        /** @var array<string,mixed> $decoded */
        $code = (int) $decoded['code'];
        $message = isset($decoded['message']) ? (string) $decoded['message'] : '';
        if ($code !== 0) {
            throw new ApiException(
                $message !== '' ? $message : ('API error ' . $code),
                $resp->status,
                $code,
                $decoded,
            );
        }
        if ($resp->status >= 400) {
            throw new ApiException(
                $message !== '' ? $message : ('HTTP ' . $resp->status),
                $resp->status,
                $code,
                $decoded,
            );
        }
        return $decoded;
    }

    private function id(string $value, string $what): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($what . ' is required');
        }
        return $value;
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function pageQuery(?int $page, ?int $limit, ?string $keyword): array
    {
        $q = [];
        if ($page !== null) {
            $q['page'] = $page;
        }
        if ($limit !== null) {
            $q['limit'] = $limit;
        }
        if ($keyword !== null && $keyword !== '') {
            $q['keyword'] = $keyword;
        }
        return $q;
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $allowed
     * @return array<string, scalar|null>
     */
    private static function filterQuery(array $query, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $query) || $query[$key] === null || $query[$key] === '') {
                continue;
            }
            $out[$key] = $query[$key];
        }
        return $out;
    }
}
