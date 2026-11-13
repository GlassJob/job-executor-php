#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use JobCenter\Client;
use JobCenter\Config;
use JobCenter\EnvLoad;
use JobCenter\Jobs\Builtin;

EnvLoad::loadFirst(...EnvLoad::candidatePaths());

$cfg = Config::fromEnv();
if ($cfg->instanceKey === gethostname() . '-' . getmypid()) {
    $cfg->instanceKey = 'php-executor-1';
}
if ($cfg->credentialId === '' || $cfg->credentialSecret === '') {
    fwrite(STDERR, "缺少凭据：设置 JC_CREDENTIAL_ID + JC_CREDENTIAL_SECRET，或 JC_CREDENTIAL=jcs_<id>.<secret>\n");
    exit(1);
}

$client = new Client($cfg);
Builtin::register($client);
$client->start();
