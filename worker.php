#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use JobCenter\Client;
use JobCenter\Config;
use JobCenter\Jobs\Builtin;

// Fill in executor settings explicitly. This entrypoint does not load .env or JC_* variables.
// Optional: php worker.php /path/to/config.php  (the file must return a Config).
$cfg = new Config();
$cfg->endpoint = 'ws://127.0.0.1:8080/v1/executor/ws';
$cfg->serviceCode = 'sdk.demo';
$cfg->credentialId = '00000000-0000-0000-0000-000000000000';
$cfg->credentialSecret = 'replace-with-executor-credential-secret';
// or: $cfg->credential = 'jcs_<id>.<secret>';
$cfg->instanceKey = 'php-executor-1';
$cfg->environment = 'dev';
// $cfg->dataDir = './data';

if (isset($argv[1]) && $argv[1] !== '' && $argv[1] !== '--help' && $argv[1] !== '-h') {
    $cfg = Config::loadFromFile($argv[1]);
}

$client = new Client($cfg);
Builtin::register($client);
$client->start();
