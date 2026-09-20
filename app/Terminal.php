<?php

declare(strict_types=1);

namespace App;

use App\Business\Services\TerminalSessions;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

final class Terminal implements MessageComponentInterface
{
    public function __construct(private TerminalSessions $sessions) { }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->sessions->open($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->sessions->message($from, $msg);
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->sessions->close($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->sessions->error($conn);
    }
}
