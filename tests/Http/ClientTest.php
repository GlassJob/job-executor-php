<?php
declare(strict_types=1);

namespace JobCenter\Tests\Http;

use JobCenter\Http\ApiException;
use JobCenter\Http\Client;
use JobCenter\Http\Config;
use JobCenter\Http\InvalidConfigException;
use JobCenter\Http\Signer;
use JobCenter\Http\TransportException;
use JobCenter\Http\TransportResponse;
use JobCenter\Job;
use JobCenter\JobHandler;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const SECRET = 'test-secret-abc';
    private const CLIENT_ID = '01a1d2f9-2ce6-78c3-bb19-6097a905f79a';
    private const TS = '1700000000';
    private const NONCE = 'n1';

    private function config(): Config
    {
        $cfg = new Config(
            baseUrl: 'http://127.0.0.1:8080',
            clientId: self::CLIENT_ID,
            clientSecret: self::SECRET,
        );
        $cfg->validate();
        return $cfg;
    }

    private function client(FakeTransport $transport): Client
    {
        return new Client(
            $this->config(),
            $transport,
            static fn(): int => (int) self::TS,
            static fn(): string => self::NONCE,
        );
    }

    public function testConstructorDoesNotTouchTransport(): void
    {
        $t = new FakeTransport();
        $this->client($t);
        self::assertSame([], $t->requests);
    }

    public function testConstructorDoesNotStartExecutorWorker(): void
    {
        self::assertTrue(class_exists(\JobCenter\Client::class));
        $t = new FakeTransport();
        $http = $this->client($t);
        self::assertInstanceOf(Client::class, $http);
        self::assertSame([], $t->requests);
    }

    public function testListServicesSignsGetAndParsesList(): void
    {
        $t = new FakeTransport();
        $t->enqueueEnvelope(200, 0, [
            'list' => [['id' => 'svc-1', 'code' => 'sdk.demo']],
            'total' => 1,
        ]);
        $page = $this->client($t)->listServices(page: 1, limit: 20, keyword: 'hello');
        self::assertSame(1, $page->total);
        self::assertSame('sdk.demo', $page->list[0]['code']);
        self::assertCount(1, $t->requests);
        $req = $t->requests[0];
        self::assertSame('GET', $req['method']);
        self::assertSame('http://127.0.0.1:8080/v1/admin/services?keyword=hello&limit=20&page=1', $req['url']);
        self::assertSame('', $req['body']);
        $this->assertSignature($req, 'GET', '/v1/admin/services', 'keyword=hello&limit=20&page=1', '');
    }

    public function testTriggerExecutionSignsJsonBodyAndDoesNotRetry(): void
    {
        $t = new FakeTransport();
        $t->enqueueEnvelope(500, 50000, null, '服务端错误');
        $t->enqueueEnvelope(200, 0, ['execution_id' => 'should-not-be-used', 'status' => 'DISPATCHING']);
        $client = $this->client($t);
        try {
            $client->triggerExecution('s1', 'j1', ['name' => '你好', 'note' => 'café'], 'idem-1', 'inst-1');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(50000, $e->apiCode);
            self::assertSame(500, $e->httpStatus);
            self::assertSame('服务端错误', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        self::assertCount(1, $t->requests, 'non-idempotent POST must not be retried');
        $req = $t->requests[0];
        self::assertSame('POST', $req['method']);
        self::assertSame('http://127.0.0.1:8080/v1/admin/services/s1/jobs/j1/executions', $req['url']);
        $body = $req['body'];
        self::assertStringContainsString('"name":"你好"', $body);
        self::assertStringContainsString('"note":"café"', $body);
        self::assertStringContainsString('"idempotency_key":"idem-1"', $body);
        self::assertStringContainsString('"instance_id":"inst-1"', $body);
        $this->assertSignature($req, 'POST', '/v1/admin/services/s1/jobs/j1/executions', '', $body);
        self::assertSame('application/json', $req['headers']['Content-Type']);
    }

    public function testTriggerEmptyPayloadIsEmptyObject(): void
    {
        $t = new FakeTransport();
        $t->enqueueEnvelope(200, 0, ['execution_id' => 'e1', 'status' => 'QUEUED']);
        $out = $this->client($t)->triggerExecution('s1', 'j1');
        self::assertSame('e1', $out['execution_id']);
        self::assertSame('{}', $t->requests[0]['body']);
        $this->assertSignature($t->requests[0], 'POST', '/v1/admin/services/s1/jobs/j1/executions', '', '{}');
    }

    public function testGetAndListExecutionsAndLogs(): void
    {
        $t = new FakeTransport();
        $t->enqueueEnvelope(200, 0, ['id' => 'e1', 'status' => 'SUCCEEDED']);
        $t->enqueueEnvelope(200, 0, ['list' => [['id' => 'e1']], 'total' => 1]);
        $t->enqueueEnvelope(200, 0, ['list' => [['id' => 'e1', 'status' => 'RUNNING']], 'total' => 1]);
        $t->enqueueEnvelope(200, 0, [
            'list' => [['sequence' => 2, 'message' => 'hello', 'level' => 'INFO']],
            'total' => 1,
        ]);
        $client = $this->client($t);
        $got = $client->getExecution('s1', 'j1', 'e1');
        self::assertSame('SUCCEEDED', $got['status']);
        $global = $client->listExecutions([
            'page' => 1,
            'limit' => 20,
            'service_id' => 's1',
            'job_id' => 'j1',
            'status' => 'SUCCEEDED',
            'created_from' => '2026-01-01T00:00:00Z',
        ]);
        self::assertSame(1, $global->total);
        $jobList = $client->listJobExecutions('s1', 'j1', ['status' => 'RUNNING']);
        self::assertSame('RUNNING', $jobList->list[0]['status']);
        $logs = $client->getExecutionLogs('s1', 'j1', 'e1', after: 1, limit: 50, attemptId: 'a1');
        self::assertSame('hello', $logs->list[0]['message']);

        self::assertSame('GET', $t->requests[0]['method']);
        self::assertSame('http://127.0.0.1:8080/v1/admin/services/s1/jobs/j1/executions/e1', $t->requests[0]['url']);
        $this->assertSignature($t->requests[0], 'GET', '/v1/admin/services/s1/jobs/j1/executions/e1', '', '');

        self::assertStringContainsString('created_from=2026-01-01T00%3A00%3A00Z', $t->requests[1]['url']);
        self::assertStringContainsString('job_id=j1', $t->requests[1]['url']);
        self::assertStringContainsString('service_id=s1', $t->requests[1]['url']);

        self::assertSame(
            'http://127.0.0.1:8080/v1/admin/services/s1/jobs/j1/executions?status=RUNNING',
            $t->requests[2]['url'],
        );
        self::assertSame(
            'http://127.0.0.1:8080/v1/admin/services/s1/jobs/j1/executions/e1/logs?after=1&attempt_id=a1&limit=50',
            $t->requests[3]['url'],
        );
        $this->assertSignature(
            $t->requests[3],
            'GET',
            '/v1/admin/services/s1/jobs/j1/executions/e1/logs',
            'after=1&attempt_id=a1&limit=50',
            '',
        );
    }

    public function testCancelEmptyBodyAndReason(): void
    {
        $t = new FakeTransport();
        $t->enqueueEnvelope(200, 0, ['execution_id' => 'e1', 'status' => 'CANCELLED']);
        $t->enqueueEnvelope(200, 0, ['execution_id' => 'e1', 'status' => 'RUNNING', 'cancel_state' => 'CANCELLING']);
        $client = $this->client($t);
        $client->cancelExecution('s1', 'j1', 'e1');
        $client->cancelExecution('s1', 'j1', 'e1', 'stop now');
        self::assertSame('', $t->requests[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $t->requests[0]['headers']);
        $this->assertSignature(
            $t->requests[0],
            'POST',
            '/v1/admin/services/s1/jobs/j1/executions/e1/cancel',
            '',
            '',
        );
        self::assertSame('{"reason":"stop now"}', $t->requests[1]['body']);
        $this->assertSignature(
            $t->requests[1],
            'POST',
            '/v1/admin/services/s1/jobs/j1/executions/e1/cancel',
            '',
            '{"reason":"stop now"}',
        );
    }

    public function testBusinessEnvelopes(): void
    {
        $t = new FakeTransport();
        $t->enqueueEnvelope(401, 40100, null, '签名校验失败');
        $t->enqueueEnvelope(404, 40400, null, '执行不存在');
        $t->enqueueEnvelope(409, 40900, null, '执行已结束（SUCCEEDED）');
        $client = $this->client($t);
        try {
            $client->listServices();
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(40100, $e->apiCode);
            self::assertSame(401, $e->httpStatus);
            self::assertSame('签名校验失败', $e->getMessage());
        }
        try {
            $client->getExecution('s1', 'j1', 'missing');
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(40400, $e->apiCode);
        }
        try {
            $client->cancelExecution('s1', 'j1', 'e1');
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(40900, $e->apiCode);
            self::assertStringContainsString('SUCCEEDED', $e->getMessage());
        }
        self::assertCount(3, $t->requests);
    }

    public function testTransportErrorAndInvalidJson(): void
    {
        $t = new FakeTransport();
        $t->enqueue(new TransportException('connection failed to 127.0.0.1:8080'));
        $t->enqueue(new TransportResponse(502, '<html>bad gateway</html>'));
        $client = $this->client($t);
        try {
            $client->listExecutions();
            self::fail();
        } catch (TransportException $e) {
            self::assertStringContainsString('connection failed', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        try {
            $client->getService('s1');
            self::fail();
        } catch (TransportException $e) {
            self::assertStringContainsString('invalid JSON', $e->getMessage());
        }
    }

    public function testFindServiceAndJobByExactCode(): void
    {
        $t = new FakeTransport();
        $t->enqueueEnvelope(200, 0, [
            'list' => [
                ['id' => 's-other', 'code' => 'sdk.demo.extra'],
                ['id' => 's1', 'code' => 'sdk.demo'],
            ],
            'total' => 2,
        ]);
        $t->enqueueEnvelope(200, 0, [
            'list' => [
                ['id' => 'j-other', 'code' => 'demo.hello2'],
                ['id' => 'j1', 'code' => 'demo.hello'],
            ],
            'total' => 2,
        ]);
        $t->enqueueEnvelope(200, 0, ['execution_id' => 'e1', 'status' => 'DISPATCHING']);
        $client = $this->client($t);
        $out = $client->triggerExecutionByCode('sdk.demo', 'demo.hello', ['ok' => true]);
        self::assertSame('e1', $out['execution_id']);
        self::assertCount(3, $t->requests);
        self::assertStringContainsString('/v1/admin/services?keyword=sdk.demo', $t->requests[0]['url']);
        self::assertStringContainsString('/v1/admin/services/s1/jobs?keyword=demo.hello', $t->requests[1]['url']);
        self::assertSame('http://127.0.0.1:8080/v1/admin/services/s1/jobs/j1/executions', $t->requests[2]['url']);
        self::assertStringContainsString('"ok":true', $t->requests[2]['body']);
    }

    public function testTriggerByCodeMissingService(): void
    {
        $t = new FakeTransport();
        $t->enqueueEnvelope(200, 0, ['list' => [], 'total' => 0]);
        $this->expectException(ApiException::class);
        $this->client($t)->triggerExecutionByCode('no.such', 'demo.hello');
    }

    public function testInvalidIdsAndConfig(): void
    {
        $t = new FakeTransport();
        $client = $this->client($t);
        try {
            $client->getExecution(' ', 'j1', 'e1');
            self::fail();
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('serviceId', $e->getMessage());
        }
        self::assertSame([], $t->requests);
        $this->expectException(InvalidConfigException::class);
        new Client(new Config(baseUrl: 'http://127.0.0.1:8080', clientId: '', clientSecret: 'x'), $t);
    }

    public function testExistingExecutorTypesStillAutoload(): void
    {
        self::assertTrue(class_exists(\JobCenter\Client::class));
        self::assertTrue(class_exists(\JobCenter\Config::class));
        self::assertTrue(interface_exists(JobHandler::class));
        self::assertTrue(class_exists(Job::class));
        self::assertNotSame(\JobCenter\Client::class, Client::class);
    }

    /**
     * @param array{method:string,url:string,headers:array<string,string>,body:string,timeout:float} $req
     */
    private function assertSignature(array $req, string $method, string $path, string $query, string $body): void
    {
        $headers = $req['headers'];
        $canonical = Signer::canonical(
            $method,
            $path,
            $query,
            $headers[Signer::HEADER_CLIENT_ID],
            $headers[Signer::HEADER_TIMESTAMP],
            $headers[Signer::HEADER_NONCE],
            $headers[Signer::HEADER_OPERATOR_ID] ?? '',
            $headers[Signer::HEADER_OPERATOR_NAME] ?? '',
            $body,
        );
        self::assertSame(self::TS, $headers[Signer::HEADER_TIMESTAMP]);
        self::assertSame(self::NONCE, $headers[Signer::HEADER_NONCE]);
        self::assertSame(Signer::sign(self::SECRET, $canonical), $headers[Signer::HEADER_SIGNATURE]);
        $blob = json_encode($req);
        self::assertIsString($blob);
        self::assertStringNotContainsString(self::SECRET, $blob);
    }
}
