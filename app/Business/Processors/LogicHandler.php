<?php

declare(strict_types=1);

namespace App\Business\Processors;

use App\Business\Services\LazyAgentCommandProcessor;
use BlueFission\Arr;
use BlueFission\Str;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use InvalidArgumentException;

/** A configured rule uses the same command authority as interactive commands. */
class LogicHandler
{
    private Arr $rule;

    public function __construct(
        private ?ICommandProcessor $commands = null,
        private array $context = []
    ) {
        $this->rule = Arr::make([]);
    }

    public function config(array $config): void
    {
        // Clear prior state before validating a replacement rule.
        $this->rule = Arr::make([]);
        foreach ($config as $key => $value) {
            if (!in_array($key, ['id', 'command', 'use_input'], true)) {
                throw new InvalidArgumentException('dynamic_rule_unsupported_field:' . $key);
            }
        }
        $command = $config['command'] ?? null;
        if ((!Str::is($command) && !Arr::is($command))
            || (Str::is($command) && Str::make($command)->trim()->val() === '')
            || (Arr::is($command) && $command === [])
            || !is_bool($config['use_input'] ?? false)
        ) {
            throw new InvalidArgumentException('dynamic_rule_command_required');
        }
        if (($config['use_input'] ?? false) && !Arr::is($command)) {
            throw new InvalidArgumentException('dynamic_input_requires_structured_command');
        }
        if (Arr::is($command)) {
            foreach ($command as $key => $value) {
                if (!in_array($key, ['verb', 'resources', 'args'], true)) {
                    throw new InvalidArgumentException('dynamic_command_unsupported_field:' . $key);
                }
            }
            if (!Str::is($command['verb'] ?? null) || Str::make($command['verb'])->trim()->val() === '') {
                throw new InvalidArgumentException('dynamic_command_verb_required');
            }
            foreach (['resources', 'args'] as $field) {
                $values = $command[$field] ?? [];
                if (!Arr::is($values) || !array_is_list($values) || ($field === 'resources' && $values === [])) {
                    throw new InvalidArgumentException('dynamic_command_' . $field . '_invalid');
                }
                foreach ($values as $value) {
                    if (($field === 'resources' && (!Str::is($value) || Str::make($value)->trim()->val() === ''))
                        || (!is_scalar($value) && $value !== null)
                    ) {
                        throw new InvalidArgumentException('dynamic_command_' . $field . '_invalid');
                    }
                }
            }
        }
        $this->rule = Arr::make($config);
    }

    public function input(mixed $input): CommandResult
    {
        $request = $this->prepareRequest($input);
        $this->commands ??= new LazyAgentCommandProcessor();
        return $this->commands->process($request);
    }

    /** Structural validation only; execution authority stays with the command processor. */
    public function prepareRequest(mixed $input): CommandRequest
    {
        if (!$this->rule->hasKey('command')) {
            throw new InvalidArgumentException('dynamic_rule_not_configured');
        }
        $command = $this->rule->get('command');
        if ($this->rule->get('use_input', false)) {
            if (!is_scalar($input) && $input !== null) {
                throw new InvalidArgumentException('dynamic_input_scalar_required');
            }
            $args = $command['args'] ?? [];
            $args[] = $input;
            $command['args'] = $args;
        }
        return new CommandRequest($command, context: $this->context);
    }
}
