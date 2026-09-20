<?php

declare(strict_types=1);

namespace App\Business\Processors;

use BlueFission\Arr;
use BlueFission\Wise\Cmd\CommandResult;
use InvalidArgumentException;
use Closure;

class DynamicProcessor extends Processor
{
    private ?Closure $loadRules;
    private LogicHandler $handler;

    public function __construct(mixed $config, ?LogicHandler $handler = null, ?callable $loadRules = null)
    {
        parent::__construct($config);
        $this->handler = $handler ?? new LogicHandler();
        $this->loadRules = $loadRules === null ? null : Closure::fromCallable($loadRules);
    }

    public function execute($input)
    {
        if (!Arr::is($this->config) && !$this->config instanceof \stdClass) {
            throw new InvalidArgumentException('dynamic_config_invalid');
        }
        $config = Arr::is($this->config) ? $this->config : get_object_vars($this->config);
        $rules = $config['rules'] ?? null;
        if ($rules === null && $this->loadRules !== null && isset($config['logic_id'])) {
            $rules = ($this->loadRules)($config['logic_id']);
        }
        if (!Arr::is($rules) || !array_is_list($rules) || $rules === [] || Arr::make($rules)->count() > 100) {
            throw new InvalidArgumentException('dynamic_rules_required');
        }
        // Preflight record shapes and input binding before dispatching any command.
        $validator = new LogicHandler();
        foreach ($rules as $rule) {
            if (!Arr::is($rule)) {
                throw new InvalidArgumentException('dynamic_rule_invalid');
            }
            $validator->config($rule);
            $validator->prepareRequest($input);
        }
        foreach ($rules as $rule) {
            $this->handler->config($rule);
            $result = $this->handler->input($input);
            if ($result->status() !== CommandResult::COMPLETED) {
                return $result;
            }
        }
        return $result;
    }
}
