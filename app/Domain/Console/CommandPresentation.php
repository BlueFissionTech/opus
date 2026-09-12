<?php

declare(strict_types=1);

namespace App\Domain\Console;

use BlueFission\Arr;
use BlueFission\Flag;
use BlueFission\Num;
use BlueFission\Str;

final class CommandPresentation
{
    private Str $status;
    private mixed $output;
    private Str $outputText;
    private Str $description;
    private Flag $confirmationRequired;
    private Num $exitCode;
    private Arr $diagnostics;
    private Str $diagnosticText;
    private Arr $metadata;
    private ?Str $continuationToken;

    public function __construct(
        string $status,
        mixed $output,
        string $outputText,
        string $description,
        bool $confirmationRequired,
        int $exitCode,
        array $diagnostics,
        string $diagnosticText,
        array $metadata,
        ?string $continuationToken
    ) {
        $this->status = Str::make($status)->trim()->lower();
        $this->output = $output;
        $this->outputText = Str::make($outputText);
        $this->description = Str::make($description)->trim();
        $this->confirmationRequired = Flag::make($confirmationRequired);
        $this->exitCode = Num::make($exitCode);
        $this->diagnostics = Arr::make($diagnostics);
        $this->diagnosticText = Str::make($diagnosticText);
        $this->metadata = Arr::make($metadata);
        $this->continuationToken = Str::isNotEmpty((string) $continuationToken)
            ? Str::make((string) $continuationToken)->trim()
            : null;
    }

    public function status(): string
    {
        return $this->status->val();
    }

    public function output(): mixed
    {
        return $this->output;
    }

    public function outputText(): string
    {
        return $this->outputText->val();
    }

    public function description(): string
    {
        return $this->description->val();
    }

    public function confirmationRequired(): bool
    {
        return $this->confirmationRequired->isTruthy();
    }

    public function exitCode(): int
    {
        return $this->exitCode->int();
    }

    public function diagnostics(): array
    {
        return $this->diagnostics->toArray();
    }

    public function diagnosticText(): string
    {
        return $this->diagnosticText->val();
    }

    public function metadata(): array
    {
        return $this->metadata->toArray();
    }

    public function continuationToken(): ?string
    {
        return $this->continuationToken?->val();
    }

    public function message(): string
    {
        return Arr::make([$this->outputText(), $this->diagnosticText()])
            ->filter(fn ($value): bool => Str::isNotEmpty((string) $value))
            ->join(PHP_EOL)
            ->val();
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status(),
            'output' => $this->output(),
            'output_text' => $this->outputText(),
            'description' => $this->description(),
            'confirmation_required' => $this->confirmationRequired(),
            'exit_code' => $this->exitCode(),
            'diagnostics' => $this->diagnostics(),
            'diagnostic_text' => $this->diagnosticText(),
            'metadata' => $this->metadata(),
            'continuation_token' => $this->continuationToken(),
        ];
    }
}
