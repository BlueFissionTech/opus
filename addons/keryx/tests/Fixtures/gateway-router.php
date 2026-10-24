<?php
declare(strict_types=1);

// Synthetic loopback fixture only; never forwards a request to a real daemon.
file_put_contents((string) getenv('KERYX_FIXTURE_REQUESTS'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
    'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
switch (getenv('KERYX_FIXTURE_MODE')) {
    case 'redirect':
        header('Location: /not-admitted', true, 302);
        echo '{"redirect":true}';
        break;
    case 'error':
        http_response_code(503);
        echo '{"unavailable":true}';
        break;
    case 'oversized':
        echo str_repeat('x', 1048577);
        break;
    case 'interrupted':
        header('Content-Length: 4096');
        echo '{"data":[]}';
        break;
    case 'timeout':
        sleep(7);
        echo '{"data":[]}';
        break;
    case 'empty':
        break;
    default:
        echo '{"data":[{"repoName":"sample","severity":"info","countTotal":3,"countUnread":2}]}';
}
