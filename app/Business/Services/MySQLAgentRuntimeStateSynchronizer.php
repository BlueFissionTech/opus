<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use BlueFission\Arr;
use BlueFission\Connections\Database\MySQLLink;
use BlueFission\Security\Hash;
use BlueFission\Str;
use RuntimeException;

final class MySQLAgentRuntimeStateSynchronizer implements IAgentRuntimeStateSynchronizer
{
    private static ?\WeakMap $heldLocks = null;

    public function __construct(private MySQLLink $link, private int $timeoutSeconds = 5)
    {
        self::$heldLocks ??= new \WeakMap();
        if (!isset(self::$heldLocks[$this->link])) {
            self::$heldLocks[$this->link] = Arr::make([]);
        }
    }

    public function synchronized(string $scope, callable $operation): mixed
    {
        $lockName = Str::make('opus_agent_state_')
            ->append(Hash::value($scope))
            ->sub(0, 64);
        $heldLocks = self::$heldLocks[$this->link];
        if ($heldLocks->hasKey($lockName)) {
            throw new RuntimeException('agent_runtime_state_lock_unavailable');
        }

        $timeout = max(0, $this->timeoutSeconds);
        $this->link->open();
        $result = $this->link
            ->query("SELECT GET_LOCK('{$lockName}', {$timeout}) AS acquired")
            ->result();
        $row = is_object($result) && method_exists($result, 'fetch_assoc')
            ? (array) $result->fetch_assoc()
            : [];
        if ((int) Arr::getPath($row, 'acquired', 0) !== 1) {
            throw new RuntimeException('agent_runtime_state_lock_unavailable');
        }
        $heldLocks->set($lockName, true);

        try {
            return $operation();
        } finally {
            $this->link->query("SELECT RELEASE_LOCK('{$lockName}')");
            $heldLocks->delete($lockName);
        }
    }
}
