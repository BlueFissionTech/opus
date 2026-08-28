<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Obj;
use BlueFission\Str;

final class AgentDescriptor extends Obj
{
    private Str $id;
    private Str $owner;
    private Str $mode;
    private Str $description;
    private Str $profile;
    private Arr $tools;
    private Arr $imports;
    private Arr $exports;
    private Arr $permissions;
    private Arr $lifecycleStates;

    public function __construct(string $id, string $owner, array $data)
    {
        parent::__construct();

        $data = Arr::make($data);

        $this->id = Str::make($id);
        $this->owner = Str::make($owner);
        $this->mode = Str::make((string) $data->get('mode'))->lower();
        $this->description = Str::make((string) $data->get('description'));
        $this->profile = Str::make((string) $data->get('profile'));
        $this->tools = Arr::make((array) $data->get('tools'));
        $this->imports = Arr::make((array) $data->get('imports'));
        $this->exports = Arr::make((array) $data->get('exports'));
        $this->permissions = Arr::make((array) $data->get('permissions'));
        $lifecycle = Arr::make((array) $data->get('lifecycle'));
        $this->lifecycleStates = Arr::make((array) $lifecycle->get('states'));
    }

    public function id(): string
    {
        return $this->id->val();
    }

    public function owner(): string
    {
        return $this->owner->val();
    }

    public function mode(): string
    {
        return $this->mode->val();
    }

    public function description(): string
    {
        return $this->description->val();
    }

    public function profile(): string
    {
        return $this->profile->val();
    }

    public function tools(): array
    {
        return $this->tools->toArray();
    }

    public function imports(): array
    {
        return $this->imports->toArray();
    }

    public function exports(): array
    {
        return $this->exports->toArray();
    }

    public function permissions(): array
    {
        return $this->permissions->toArray();
    }

    public function lifecycleStates(): array
    {
        return $this->lifecycleStates->toArray();
    }
}
