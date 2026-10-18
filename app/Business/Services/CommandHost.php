<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\CommandResultPresenter;
use App\Domain\Console\CommandPresentation;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\ICommandProcessor;
use Throwable;

final class CommandHost
{
    public function __construct(
        private ICommandProcessor $processor,
        private CommandResultPresenter $presenter
    ) {
    }

    public function process(CommandRequest|Command|array|string $request): CommandPresentation
    {
        try {
            $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
            $result = $this->processor->process($request);
        } catch (Throwable) {
            return CommandPresentation::hostFailure('processing');
        }
        try {
            return $this->presenter->present($result);
        } catch (Throwable) {
            return CommandPresentation::hostFailure('presentation');
        }
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
