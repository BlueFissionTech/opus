<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\VibeValue;
use BlueFission\Arr;
use BlueFission\Str;
use BlueFission\Val;
use BlueFission\Utils\File;
use BlueFission\Utils\Path;
use BlueFission\Vibrato\Reader;

final class VibeThemeRenderer
{
    private \Closure $themeResolver;

    private \Closure $readerFactory;

    private VibeContextPolicy $contextPolicy;

    public function __construct(
        ?callable $themeResolver = null,
        ?callable $readerFactory = null,
        ?VibeContextPolicy $contextPolicy = null
    )
    {
        $this->themeResolver = $themeResolver instanceof \Closure
            ? $themeResolver
            : \Closure::fromCallable($themeResolver ?? static fn (string $name): mixed => instance()->theme($name));
        $this->readerFactory = $readerFactory instanceof \Closure
            ? $readerFactory
            : \Closure::fromCallable($readerFactory ?? static fn (): Reader => new Reader(null));
        $this->contextPolicy = $contextPolicy ?? new VibeContextPolicy();
    }

    /**
     * @param array<string, mixed> $variables
     * @param list<string> $trustedVariables
     * @param list<string> $requiredVariables
     */
    public function render(
        string $themeName,
        string $file,
        array $variables = [],
        array $trustedVariables = [],
        array $requiredVariables = []
    ): string {
        $theme = ($this->themeResolver)($themeName);
        if (!$theme || Val::isEmpty($theme->location ?? null)) {
            throw new \InvalidArgumentException("Theme '{$themeName}' is not registered.");
        }

        $relativeFile = $this->validateRelativeFile($file);
        $themeDirectory = Path::normalize((string) $theme->location);
        $templatePath = Path::normalize($themeDirectory . DIRECTORY_SEPARATOR . $relativeFile);

        if (!(new File())->exists($templatePath)) {
            throw new \InvalidArgumentException("Vibe template '{$relativeFile}' was not found in theme '{$themeName}'.");
        }

        foreach ($requiredVariables as $requiredVariable) {
            if (!Arr::hasKey($variables, $requiredVariable)) {
                throw new \InvalidArgumentException(
                    "Vibe template '{$relativeFile}' requires variable '{$requiredVariable}'."
                );
            }
        }

        $reader = ($this->readerFactory)();
        if (!$reader instanceof Reader) {
            throw new \UnexpectedValueException('The Vibe reader factory must return a Vibrato Reader.');
        }

        $reader
            ->setIncludePaths([
                'templates' => $themeDirectory,
                'modules' => $themeDirectory,
                'includes' => $themeDirectory,
            ])
            ->setVariables($this->escapeVariables($variables, $trustedVariables))
            ->inputFile($templatePath)
            ->run([
                'validate_syntax' => true,
                'run_backend' => false,
            ]);

        return $reader->output();
    }

    private function validateRelativeFile(string $file): string
    {
        $relativeFile = (string) Str::make($file)->trim();
        if (Val::isEmpty($relativeFile)) {
            throw new \InvalidArgumentException('Vibe template file cannot be empty.');
        }

        if (
            preg_match('#^(?:[A-Za-z]:)?[\\\\/]#', $relativeFile) === 1
            || preg_match('#(?:^|[\\\\/])\.\.(?:[\\\\/]|$)#', $relativeFile) === 1
        ) {
            throw new \InvalidArgumentException('Vibe template file must remain inside its theme.');
        }

        if (!Str::make($relativeFile)->endsWith('.vibe')) {
            throw new \InvalidArgumentException('Vibe template files must use the .vibe extension.');
        }

        return Path::normalize($relativeFile);
    }

    /**
     * @param array<string, mixed> $variables
     * @param list<string> $trustedVariables
     * @return array<string, mixed>
     */
    private function escapeVariables(array $variables, array $trustedVariables): array
    {
        $trusted = Arr::make($trustedVariables);

        return Arr::toArray(Arr::make($variables)->map(
            fn (mixed $value, string|int $name): mixed => $trusted->contains((string) $name)
                ? $this->protectLegacyTrustedMarkup($value)
                : $this->contextPolicy->protect($value)
        ), true);
    }

    private function protectLegacyTrustedMarkup(mixed $value): string
    {
        if (!Str::is($value)) {
            throw new \InvalidArgumentException('Trusted Vibe markup must be a string.');
        }

        return $this->contextPolicy->protect(VibeValue::trustedMarkup((string) $value));
    }
}
