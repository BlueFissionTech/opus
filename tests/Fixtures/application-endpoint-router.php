<?php

declare(strict_types=1);

$path = $_SERVER['REQUEST_URI'];
$mode = getenv('OPUS_FIXTURE_MODE');
file_put_contents(getenv('OPUS_FIXTURE_REQUESTS'), $path . "\n", FILE_APPEND);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$body = match ($path) {
    '/health' => ['ok' => true, 'status' => 'live'],
    '/ready' => ['ok' => true, 'status' => 'ready'],
    '/api/info' => ['ok' => true, 'profile' => 'api'],
    default => ['ok' => false, 'error' => 'route_not_found'],
};
if ($path === '/health') {
    if ($mode === 'bad_status') { http_response_code(503); }
    if ($mode === 'redirect') { header('Location: /ready', true, 302); }
    if ($mode === 'html') { header('Content-Type: text/html'); }
    if ($mode === 'bad_contract') { $body = ['ok' => 'true', 'status' => 'live']; }
    if ($mode === 'bad_json') { echo 'private-fixture-not-json'; return; }
    if ($mode === 'oversize') { echo str_repeat('private-fixture', 10000); return; }
}
if ($path === '/ready' && $mode === 'not_ready') { http_response_code(503); }
if ($path === '/api/info' && ($mode === 'wrong_profile' || $mode === 'dashboard')) { $body['profile'] = 'dashboard'; }
if (($body['error'] ?? null) === 'route_not_found') { http_response_code(404); }
echo json_encode($body);
