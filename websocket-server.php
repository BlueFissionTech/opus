<?php
require 'vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;
use App\Terminal;
use BlueFission\Async\Sock;

if (!Sock::isAvailable()) {
    fwrite(
        STDERR,
        "The optional terminal WebSocket transport is unavailable. "
        . "Install cboden/ratchet in a compatible host to enable it.\n"
    );
    exit(1);
}

$loop = Factory::create();
$port = 8080;

try {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new Terminal($loop)
            )
        ),
        $port,
        '0.0.0.0'
    );

    echo "WebSocket server listening on port {$port}\n";

    $server->run();
} catch (Exception $e) {
    echo "Error starting WebSocket server: " . $e->getMessage() . "\n";
}
