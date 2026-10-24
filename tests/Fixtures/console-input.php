<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Business\Console\CliManager;
use App\Business\Presentation\CommandResultPresenter;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\CommandHost;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;

$processor = new class implements ICommandProcessor {
    public array $requests = [];
    public function process(CommandRequest|Command|array|string $request): CommandResult
    {
        $this->requests[] = [
            'input' => $request->input(),
            'continuation' => $request->isContinuation(),
            'approved' => $request->approved(),
        ];
        return $request->input() === 'confirm'
            ? CommandResult::pending('Fixture approval', null, 'fixture-token')
            : CommandResult::completed('handled');
    }
};
$query = new class implements IActivatedAddOnsQuery {
    public function fetch(): array { return []; }
};
$manager = new CliManager(new CommandHost($processor, new CommandResultPresenter()),
    new AgentCommandContextProvider($query));
$exit = $manager->cmd();
fwrite(STDERR, json_encode(['exit' => $exit, 'requests' => $processor->requests], JSON_THROW_ON_ERROR));
exit($exit);
