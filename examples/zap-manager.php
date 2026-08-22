<?php

declare(strict_types=1);

use App\Business\Managers\ZapManager;
use BlueFission\Net\HTTP;
use BlueFission\Str;

require dirname(__DIR__) . '/vendor/autoload.php';

$apiKey = getenv('ZAPIER_API_KEY');
if (!Str::is($apiKey) || Str::isEmpty($apiKey)) {
    fwrite(STDERR, "ZAPIER_API_KEY is required.\n");
    exit(2);
}

$response = (new ZapManager($apiKey))->searchZaps('example');
fwrite(STDOUT, HTTP::jsonEncode($response->toArray()) . PHP_EOL);
exit($response->ok() ? 0 : 1);
