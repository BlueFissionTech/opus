<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

use BlueFission\Synematic\Orchestration\GraphDocument;
use InvalidArgumentException;

/** Immutable host scope around a native, portable Synematic graph. */
final readonly class Workflow
{
    private array $definition;

    public function __construct(public string $id, public string $application, public string $tenant,
        public Trigger $trigger, public Action $action, public RetryPolicy $retry = new RetryPolicy(),
        ?GraphDocument $graph = null)
    {
        foreach ([$id, $application, $tenant] as $value) { Identifier::check($value); }
        $graph ??= new GraphDocument([
            'id' => $id, 'entry' => 'trigger', 'max_steps' => 3,
            'nodes' => [
                ['id' => 'trigger', 'resolver' => $trigger->type],
                ['id' => 'map', 'resolver' => 'fixture.subject'],
                ['id' => 'capture', 'resolver' => $action->type],
            ],
            'edges' => [
                ['from' => ['node' => 'trigger'], 'to' => ['node' => 'map']],
                ['from' => ['node' => 'map'], 'to' => ['node' => 'capture']],
            ],
        ]);
        $this->definition = $graph->assertValid()->toArray();
    }

    public function graph(): GraphDocument
    {
        $graph = (new GraphDocument($this->definition))->assertValid();
        // Mutable native objects and extension filters cannot rewrite the approved snapshot.
        if ($graph->toArray() !== $this->definition) {
            throw new InvalidArgumentException('Workflow definition changed.');
        }
        return $graph;
    }
}
