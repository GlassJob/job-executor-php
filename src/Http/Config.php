<?php
declare(strict_types=1);

namespace JobCenter\Http;

/**
 * Admin HTTP client configuration. Distinct from Executor WS credentials.
 * Pass values explicitly to the constructor; this class does not read the environment.
 */
final class Config
{
    public const USER_AGENT = 'glassjob-job-executor-php-http/0.3.0';

    public function __construct(
        public string $baseUrl = 'http://127.0.0.1:8080',
        public string $clientId = '',
        private string $clientSecret = '',
        public string $operatorId = '',
        public string $operatorName = '',
        public float $timeoutSeconds = 15.0,
    ) {
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function validate(): void
    {
        $this->baseUrl = rtrim(trim($this->baseUrl), '/');
        if ($this->baseUrl === '') {
            throw new InvalidConfigException('baseUrl is required');
        }
        $parts = parse_url($this->baseUrl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidConfigException('baseUrl must be an absolute http(s) URL');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidConfigException('baseUrl must use http or https, not ' . $scheme);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidConfigException('baseUrl must not contain userinfo');
        }
        $this->clientId = trim($this->clientId);
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new InvalidConfigException('clientId and clientSecret are required');
        }
        if ($this->timeoutSeconds <= 0) {
            throw new InvalidConfigException('timeoutSeconds must be greater than 0');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret === '' ? '' : '***',
            'operatorId' => $this->operatorId,
            'operatorName' => $this->operatorName,
            'timeoutSeconds' => $this->timeoutSeconds,
        ];
    }
}
