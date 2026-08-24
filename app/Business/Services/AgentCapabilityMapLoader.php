<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentCapabilityMap;
use BlueFission\Arr;

final class AgentCapabilityMapLoader
{
    public function __construct(
        private ?DeclarativeArrayParser $parser = null,
        private ?AgentCapabilityMapValidator $validator = null,
        private ?AddOnContractValidator $contractValidator = null
    ) {
        $this->parser ??= new DeclarativeArrayParser();
        $this->validator ??= new AgentCapabilityMapValidator($this->parser);
        $this->contractValidator ??= new AddOnContractValidator(
            agentMapValidator: $this->validator,
            declarativeParser: $this->parser
        );
    }

    public function load(string $path, string $owner): AgentCapabilityMap
    {
        $parsed = Arr::make($this->parser->parseFile($path));
        if (!$parsed->get('valid')) {
            return $this->empty($owner);
        }

        $mapping = Arr::make((array) $parsed->get('value'));
        $knownTools = $owner === 'application'
            ? $this->declaredTools($mapping)
            : $this->contractValidator->knownToolsFromConsoleFile(
                dirname($path) . DIRECTORY_SEPARATOR . 'console.php'
            );
        $validated = Arr::make($this->validator->validate(
            $mapping->toArray(),
            $knownTools,
            $owner
        ));
        $map = $validated->get('map');

        return $validated->get('valid') && $map instanceof AgentCapabilityMap
            ? $map
            : $this->empty($owner);
    }

    private function declaredTools(Arr $mapping): array
    {
        $knownTools = Arr::make([]);
        Arr::make((array) $mapping->get('agents'))->each(function ($descriptor) use ($knownTools): void {
            if (Arr::is($descriptor)) {
                $knownTools->merge((array) Arr::make($descriptor)->get('tools'));
            }
        });

        return $knownTools->unique()->toArray();
    }

    private function empty(string $owner): AgentCapabilityMap
    {
        return new AgentCapabilityMap(AgentCapabilityMapValidator::VERSION, $owner, []);
    }
}
