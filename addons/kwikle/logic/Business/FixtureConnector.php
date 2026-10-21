<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Business;

use BlueFission\Services\Service;

/** Testable local capture only; no provider/network implementation. */
class FixtureConnector extends Service
{
    private array $captured = [];

    public function available(): bool { return true; }

    /** @return array{ok: bool, effects: 'none'|'committed'|'unknown'} */
    public function capture(string $receipt, string $subject): array
    {
        $this->captured[$receipt] = $subject;
        return ['ok' => true, 'effects' => 'committed'];
    }

    public function compensate(string $receipt): bool
    {
        unset($this->captured[$receipt]);
        return true;
    }

    public function count(): int { return \BlueFission\Arr::size($this->captured); }
}
