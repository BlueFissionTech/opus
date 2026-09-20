<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Processors;

use App\Business\Processors\DynamicProcessor;
use App\Business\Processors\LogicHandler;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DynamicProcessorTest extends TestCase
{
    private function command(): array
    {
        return ['verb' => 'create', 'resources' => ['note'], 'args' => ['prefix']];
    }

    private function recorder(?CommandResult $first = null): ICommandProcessor
    {
        return new class($first) implements ICommandProcessor {
            public array $requests = [];
            public function __construct(private ?CommandResult $first) {}
            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->requests[] = $request;
                return $this->first ?? CommandResult::completed(count($this->requests));
            }
        };
    }

    public function testRulesUseTheSharedProcessorAndHostContextWithoutInterpolatingInput(): void
    {
        $commands = $this->recorder();
        $context = ['actor' => 'operator', 'tenant_id' => 'team-a', 'agent_id' => 'principal'];
        $rules = [
            ['command' => $this->command(), 'use_input' => true],
            ['command' => 'list notes'],
        ];
        $processor = new DynamicProcessor(['rules' => $rules], new LogicHandler($commands, $context));
        $input = 'a title; delete all users';
        self::assertSame(2, $processor->execute($input)->output());
        self::assertCount(2, $commands->requests);
        self::assertSame(['prefix', $input], $commands->requests[0]->input()['args']);
        self::assertSame('list notes', $commands->requests[1]->input());
        foreach ($commands->requests as $request) {
            self::assertSame($context, $request->context());
            self::assertNull($request->approved());
            self::assertFalse($request->isContinuation());
            self::assertTrue($request->shouldExecute());
        }
        self::assertSame(['prefix'], $rules[0]['command']['args']);
    }

    public function testHostRuleLoaderReceivesTheConfiguredIdentity(): void
    {
        $commands = $this->recorder();
        $loaded = [];
        $processor = new DynamicProcessor((object) ['logic_id' => 7], new LogicHandler($commands),
            function ($id) use (&$loaded) { $loaded[] = $id; return [['command' => 'list notes']]; });
        self::assertSame(1, $processor->execute(null)->output());
        self::assertSame([7], $loaded);
    }

    public function testEveryNonCompletedOutcomeStopsTheSequence(): void
    {
        foreach ([CommandResult::invalid('denied'), CommandResult::failure('failed'),
            CommandResult::pending('approval', null, 'opaque-token'), CommandResult::parsed(new Command())] as $outcome) {
            $commands = $this->recorder($outcome);
            $processor = new DynamicProcessor(['rules' => [
                ['command' => 'create note'], ['command' => 'delete note'],
            ]], new LogicHandler($commands));
            self::assertSame($outcome, $processor->execute('input'));
            self::assertCount(1, $commands->requests);
        }
    }

    public function testMalformedTailAndInputBindingFailBeforeDispatch(): void
    {
        foreach ([
            ['command' => ['verb' => 'create', 'resources' => ['note'], 'args' => 'bad']],
            ['command' => ['verb' => '', 'resources' => ['note']]],
            ['command' => ['verb' => 'create', 'resources' => [new \stdClass()]]],
            ['command' => ['verb' => 'create', 'resources' => ['note'], 'approved' => true]],
            ['command' => $this->command(), 'use_input' => true],
            ['command' => 'create note', 'context' => ['admin' => true]],
            ['command' => 'create note', 'function' => 'system'],
            ['command' => 'create note', 'api' => 'https://invalid.example'],
            ['command' => 'create note', 'condition' => false],
            ['command' => 'create note', 'use_input' => true],
        ] as $tail) {
            $commands = $this->recorder();
            try {
                (new DynamicProcessor(['rules' => [['command' => 'create note'], $tail]],
                    new LogicHandler($commands)))->execute(new \stdClass());
                self::fail('Malformed rule or input accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $commands->requests);
            }
        }
    }

    public function testInvalidSourcesFailExplicitly(): void
    {
        foreach ([null, 3, [], ['logic_id' => 1], ['rules' => []], ['rules' => [null]],
            ['rules' => array_fill(0, 101, ['command' => 'list notes'])]] as $config) {
            try { (new DynamicProcessor($config))->execute(null); self::fail('Invalid source accepted.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testFailedReconfigurationCannotExecuteThePreviousRule(): void
    {
        $commands = $this->recorder();
        $handler = new LogicHandler($commands);
        $handler->config(['command' => 'list notes']);
        try { $handler->config(['api' => 'legacy']); }
        catch (InvalidArgumentException) {}
        try { $handler->input(null); self::fail('Prior rule remained executable.'); }
        catch (InvalidArgumentException $error) {
            self::assertSame('dynamic_rule_not_configured', $error->getMessage());
            self::assertSame([], $commands->requests);
        }
    }
}
