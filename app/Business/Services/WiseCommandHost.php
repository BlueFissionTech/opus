<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\CommandResultPresenter;
use App\Domain\Console\CommandPresentation;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\ICommandProcessor;

final class WiseCommandHost
{
    public function __construct(
        private ICommandProcessor $processor,
        private CommandResultPresenter $presenter
    ) {
    }

    public function process(CommandRequest|Command|array|string $request): CommandPresentation
    {
        $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);

        return $this->presenter->present($this->processor->process($request));
    }

    public function execute(Command|array|string $input, array $context = []): CommandPresentation
    {
        return $this->process(new CommandRequest($input, context: $context));
    }

    public function resume(
        string $continuationToken,
        bool $approved,
        array $context = []
    ): CommandPresentation {
        return $this->process(CommandRequest::resume($continuationToken, $approved, $context));
    }
}
