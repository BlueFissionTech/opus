<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentCapabilityMap;
use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Str;

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
        if ($owner === 'application' || !$this->manifestDeclaresAgentMap($path)) {
            return $this->empty($owner);
        }

        return $this->loadValidated(
            $path,
            $owner,
            $this->contractValidator->knownToolsFromConsoleFile(
                dirname($path) . DIRECTORY_SEPARATOR . 'console.php'
            )
        );
    }

    public function loadApplication(string $path): AgentCapabilityMap
    {
        $parsed = Arr::make($this->parser->parseFile($path));
        if (!$parsed->get('valid')) {
            return $this->empty('application');
        }
        $mapping = Arr::make((array) $parsed->get('value'));

        return $this->validatedMap($mapping, 'application', $this->declaredTools($mapping));
    }

    private function loadValidated(string $path, string $owner, array $knownTools): AgentCapabilityMap
    {
        $parsed = Arr::make($this->parser->parseFile($path));
        if (!$parsed->get('valid')) {
            return $this->empty($owner);
        }

        return $this->validatedMap(Arr::make((array) $parsed->get('value')), $owner, $knownTools);
    }

    private function validatedMap(Arr $mapping, string $owner, array $knownTools): AgentCapabilityMap
    {
        $validated = Arr::make($this->validator->validate($mapping->toArray(), $knownTools, $owner));
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

    private function manifestDeclaresAgentMap(string $path): bool
    {
        $definitionPath = dirname(dirname($path)) . DIRECTORY_SEPARATOR . 'definition.json';
        if (!FileSystem::fileExists($definitionPath)) {
            return false;
        }

        $contents = FileSystem::fileContents($definitionPath);
        if (!Str::is($contents)) {
            return false;
        }

        $definition = HTTP::jsonDecode($contents, true);

        return Arr::is($definition)
            && Arr::getPath($definition, 'agent_mapping') === 'mapping/agents.php';
    }

    private function empty(string $owner): AgentCapabilityMap
    {
        return new AgentCapabilityMap(AgentCapabilityMapValidator::VERSION, $owner, []);
    }
}
