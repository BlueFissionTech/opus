<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Str;
use InvalidArgumentException;

/** Immutable installation identity; possession is not an authorization grant. */
final class AgentApplicationScope
{
    private Str $id;

    public function __construct(string $id)
    {
        $value = Str::make($id);
        if ($value->len() > 128 || !$value->matches('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D')) {
            throw new InvalidArgumentException('agent_application_identity_invalid');
        }
        $this->id = $value;
    }

    public function id(): string
    {
        return $this->id->val();
    }

    public function key(): string
    {
        return 'application:' . Str::make($this->id())->encrypt('sha256')->val();
    }
}
