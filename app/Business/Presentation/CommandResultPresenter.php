<?php

declare(strict_types=1);

namespace App\Business\Presentation;

use App\Domain\Console\CommandPresentation;
use BlueFission\Arr;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use BlueFission\Wise\Cmd\CommandResult;
use JsonSerializable;
use Stringable;

final class CommandResultPresenter
{
    public function present(CommandResult $result): CommandPresentation
    {
        $outputText = $this->renderValue($result->output());
        if ($result->confirmationRequired() && Str::isEmpty($outputText)) {
            $outputText = Str::isNotEmpty($result->description())
                ? $result->description()
                : 'Confirmation required.';
        }

        $diagnosticText = Arr::make($result->diagnostics())
            ->map(fn ($diagnostic): string => $this->renderValue($diagnostic))
            ->filter(fn ($diagnostic): bool => Str::isNotEmpty((string) $diagnostic))
            ->join(PHP_EOL)
            ->val();

        return new CommandPresentation(
            $result->status(),
            $result->output(),
            $outputText,
            $result->description(),
            $result->confirmationRequired(),
            $result->exitCode(),
            $result->diagnostics(),
            $diagnosticText,
            $result->metadata(),
            $result->continuationToken()
        );
    }

    private function renderValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (Str::is($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof JsonSerializable) {
            return $this->renderJson($value->jsonSerialize());
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->renderJson($value->toArray());
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (Arr::is($value)) {
            return $this->renderJson($value);
        }

        return $this->renderJson($value);
    }

    private function renderJson(mixed $value): string
    {
        $encoded = HTTP::jsonEncode($value);

        return Str::is($encoded) ? $encoded : '';
    }
}
