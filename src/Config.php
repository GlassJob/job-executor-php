<?php
declare(strict_types=1);

namespace JobCenter;

final class Config
{
    public const SDK_VERSION = '0.3.0';

    public string $endpoint = '';
    public string $serviceCode = '';
    public string $credentialId = '';
    public string $credentialSecret = '';
    public string $credential = '';
    public string $environment = 'dev';
    public string $instanceKey = '';
    public int $maxConcurrency = 0;
    public string $version = '0.3.0';
    public string $gitCommit = '';
    public string $buildTime = '';
    public float $shutdownTimeoutSeconds = 30.0;
    public string $sdkVersion = self::SDK_VERSION;
    public string $dataDir = '';
    public string $registryPath = '';
    public string $runnerBin = '';
    public string $phpBinary = PHP_BINARY;

    /**
     * Load a PHP file that returns a Config instance. The file is not an env loader.
     */
    public static function loadFromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('config file not found: ' . $path);
        }
        $cfg = require $path;
        if (!$cfg instanceof self) {
            throw new \InvalidArgumentException('config file must return a JobCenter\\Config instance');
        }
        return $cfg;
    }

    public function applyDefaults(): void
    {
        if ($this->credential !== '' && ($this->credentialId === '' || $this->credentialSecret === '')) {
            [$id, $secret] = self::parseCredentialToken($this->credential);
            if ($this->credentialId === '') {
                $this->credentialId = $id;
            }
            if ($this->credentialSecret === '') {
                $this->credentialSecret = $secret;
            }
        }
        if ($this->endpoint === '' || $this->serviceCode === '' || $this->credentialId === '' || $this->credentialSecret === '') {
            throw new \InvalidArgumentException('endpoint, service_code, credential_id and credential_secret are required');
        }
        if ($this->environment === '') {
            $this->environment = 'dev';
        }
        if ($this->maxConcurrency <= 0) {
            $n = (int) (function_exists('swoole_cpu_num') ? 0 : 0);
            $n = (int) shell_exec('nproc 2>/dev/null') ?: 1;
            $this->maxConcurrency = max(1, $n);
        }
        if ($this->shutdownTimeoutSeconds <= 0) {
            $this->shutdownTimeoutSeconds = 30.0;
        }
        if ($this->version === '') {
            $this->version = '0.0.0-dev';
        }
        if ($this->sdkVersion === '') {
            $this->sdkVersion = self::SDK_VERSION;
        }
        if ($this->instanceKey === '') {
            $host = gethostname() ?: 'host';
            $this->instanceKey = $host . '-' . getmypid();
        }
        if (strlen($this->instanceKey) > 128) {
            $this->instanceKey = substr($this->instanceKey, 0, 128);
        }
        if ($this->dataDir === '') {
            $this->dataDir = './data';
        }
        $root = dirname(__DIR__);
        if ($this->registryPath === '') {
            $this->registryPath = $root . '/src/Jobs/registry.php';
        }
        if ($this->runnerBin === '') {
            $this->runnerBin = $root . '/bin/jobcenter-runner';
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    public static function parseCredentialToken(string $token): array
    {
        $token = trim($token);
        if (!str_starts_with($token, 'jcs_')) {
            throw new \InvalidArgumentException('credential token must start with jcs_');
        }
        $rest = substr($token, 4);
        $dot = strpos($rest, '.');
        if ($dot === false || $dot === 0 || $dot >= strlen($rest) - 1) {
            throw new \InvalidArgumentException('credential token must be jcs_<id>.<secret>');
        }
        return [substr($rest, 0, $dot), substr($rest, $dot + 1)];
    }
}
