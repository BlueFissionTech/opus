<?php
declare(strict_types=1);

$runtimePaths = require __DIR__ . '/common/bootstrap/runtime.php';
require $runtimePaths->packageRoot() . '/common/helpers/functions.php';
require $runtimePaths->packageRoot() . '/common/helpers/settings.php';
set_time_limit(0);

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\Terminal;
use App\Business\Services\TerminalSessions;
use BlueFission\Async\Sock;

if (!Sock::isAvailable()) {
    fwrite(
        STDERR,
        "The optional terminal WebSocket transport is unavailable. "
        . "Install cboden/ratchet in a compatible host to enable it.\n"
    );
    exit(1);
}

try {
    $bootstrap = getenv('OPUS_TERMINAL_BOOTSTRAP');
    if (!is_string($bootstrap) || $bootstrap === '' || !is_file($bootstrap)) {
        fwrite(STDERR, "Configure OPUS_TERMINAL_BOOTSTRAP with a trusted host authorization bootstrap.\n");
        exit(2);
    }
    $sessions = require $bootstrap;
    if (!$sessions instanceof TerminalSessions) {
        fwrite(STDERR, "Terminal bootstrap must return TerminalSessions.\n");
        exit(2);
    }
    $port = 8080;
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new Terminal($sessions)
            )
        ),
        $port,
        '127.0.0.1'
    );

    echo "WebSocket server listening on port {$port}\n";

    $server->run();
} catch (Throwable $e) {
    fwrite(STDERR, "Terminal transport could not start.\n");
    exit(1);
}
