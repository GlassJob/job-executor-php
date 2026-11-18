# job-executor-php

GlassJob Executor PHP SDK（WebSocket 协议 **1.1**）。克隆后 `composer install`，复制 `.env.example` → `.env` 填入凭据即可独立运行。

父进程 Worker 负责连中心、鉴权与上报；任务在子进程 Runner 中执行（不依赖 Swoole / PHP-FPM）。

## 能力

- HMAC `auth.request`（secret 不进帧）
- `service.register` / `heartbeat` / `unregister`
- `task.execute` → ack / started / completed|failed
- `JobContext`：`bindState` / `checkpoint` / `setNextState`（对接中心 `job_states.key=default`）
- `$job->log()`：独立上报 `task.log`（默认不打本机；缓冲 100 / 1s flush）
- `task.cancel` → cancel_ack / cancelled（配合 Admin 取消）
- WS 断线续跑：子进程任务不随断线取消；重连 `task.sync_running`；终态写入 `{JC_DATA_DIR}/pending_results` 直至 `result_ack OK`

示例 Job：`demo.hello` / `demo.fail` / `demo.sleep` / `demo.timeout` / `demo.counter`（状态累加）/ `demo.progress` / `demo.logs`

## 快速开始

在仓库根目录：

```bash
composer install
cp .env.example .env   # 填入 JC_CREDENTIAL_ID / JC_CREDENTIAL_SECRET 或 JC_CREDENTIAL
php worker.php
```

示例会自动加载 `.env`（真实环境变量优先）。

Admin 触发：`POST /v1/admin/services/:serviceId/jobs/:jobId/executions`  
取消：`POST .../executions/:executionId/cancel`（`demo.sleep` 便于联调）

## 配置

| 字段 | 说明 |
|------|------|
| `Endpoint` / `JC_WS_ENDPOINT` | `ws(s)://.../v1/executor/ws` |
| `ServiceCode` / `JC_SERVICE_CODE` | 服务业务编码 `code` |
| `CredentialID` / `CredentialSecret` | 凭据（`JC_CREDENTIAL_ID` / `JC_CREDENTIAL_SECRET`） |
| `Credential` / `JC_CREDENTIAL` | 可选 `jcs_<id>.<secret>` |
| `InstanceKey` / `JC_INSTANCE_KEY` | 稳定实例键（≤128） |
| `DataDir` / `JC_DATA_DIR` | 终态 pending 目录（默认 `./data`） |

协议类型与 center `pkg/protocol` 以及 `job-executor-go` 对齐。PHP ≥ 8.4。

### 断线 / Center 宕机

- 短断：本地子进程继续，重连后 sync + 补报。
- 任务已跑完而 Center 未上线：终态先落盘，Center 恢复或执行器重启后重放，直到 ack。

## 在业务项目中使用

```php
$cfg = JobCenter\Config::fromEnv();
$client = new JobCenter\Client($cfg);
$client->registerJob(new JobCenter\Job(
    code: 'my.job',
    name: 'My Job',
    handlerVersion: '1.0.0',
    handler: MyJobHandler::class,
));
$client->start();
```

Handler 实现 `JobCenter\JobHandler`，在子进程中运行：

```php
final class MyJobHandler implements JobCenter\JobHandler
{
    public function handle(JobCenter\JobContext $job): void
    {
        $state = $job->bindState();
        $job->log()->info('running', ['version' => $job->stateVersion()]);
        $job->checkpoint(['cursor' => 1]);
        $job->setNextState(['cursor' => 2]);
        $job->setResult(['ok' => true]);
    }
}
```

## License

本项目采用 [Apache License 2.0](LICENSE)。第三方依赖仍按各自许可证授权，详见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。分发包含 `vendor/` 的安装包或镜像时，请保留依赖的版权与许可声明。
