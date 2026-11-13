<?php
declare(strict_types=1);

namespace JobCenter\Protocol;

final class MessageType
{
    public const AUTH_REQUEST = 'auth.request';
    public const AUTH_RESPONSE = 'auth.response';
    public const SERVICE_REGISTER = 'service.register';
    public const SERVICE_REGISTER_ACK = 'service.register_ack';
    public const SERVICE_HEARTBEAT = 'service.heartbeat';
    public const SERVICE_HEARTBEAT_ACK = 'service.heartbeat_ack';
    public const SERVICE_UNREGISTER = 'service.unregister';
    public const TASK_EXECUTE = 'task.execute';
    public const TASK_EXECUTE_ACK = 'task.execute_ack';
    public const TASK_STARTED = 'task.started';
    public const TASK_PROGRESS = 'task.progress';
    public const TASK_LOG = 'task.log';
    public const TASK_CHECKPOINT = 'task.checkpoint';
    public const TASK_COMPLETED = 'task.completed';
    public const TASK_FAILED = 'task.failed';
    public const TASK_RESULT_ACK = 'task.result_ack';
    public const TASK_CANCEL = 'task.cancel';
    public const TASK_CANCEL_ACK = 'task.cancel_ack';
    public const TASK_CANCELLED = 'task.cancelled';
    public const TASK_SYNC_RUNNING = 'task.sync_running';
    public const TASK_SYNC_RUNNING_ACK = 'task.sync_running_ack';
    public const SYSTEM_ERROR = 'system.error';
    public const SYSTEM_NOTICE = 'system.notice';
}
