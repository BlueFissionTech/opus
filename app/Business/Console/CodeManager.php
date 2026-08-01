<?php

declare(strict_types=1);

namespace App\Business\Console;

use App\Business\Services\VibeGenerationService;
use BlueFission\Arr;
use BlueFission\Services\Service;
use BlueFission\Str;

class CodeManager extends Service
{
    protected VibeGenerationService $generationService;

    public function __construct(?VibeGenerationService $generationService = null)
    {
        $this->generationService = $generationService ?? new VibeGenerationService();

        parent::__construct();
    }

    public function generate(string $type, string $name, string $prompt): array
    {
        print "Please wait...\n";
        $result = Arr::make($this->generationService->renderSource($prompt, [
            'type' => $type,
            'name' => $name,
        ]));

        if ($result->get('valid')) {
            if ($result->get('output') !== '') {
                print $result->get('output') . "\n";
            }
            print "Generation completed.\n";
        } else {
            print $this->formatErrors(Arr::make($result->get('errors'))->val());
        }

        return $result->val();
    }

    public function render(string $source, array $variables = []): array
    {
        return $this->generationService->renderSource($source, $variables);
    }

    public function renderFile(string $sourcePath, ?string $outputPath = null, array $variables = []): array
    {
        if (Str::isNotEmpty($outputPath)) {
            return $this->generationService->writeRenderedFile($sourcePath, $outputPath, $variables);
        }

        return $this->generationService->renderFile($sourcePath, $variables);
    }

    private function formatErrors(array $errors): string
    {
        $errors = Arr::make($errors);
        if ($errors->isEmpty()) {
            return "Generation failed.\n";
        }

        $lines = Arr::make(["Generation failed:"]);
        foreach ($errors as $error) {
            $error = Arr::make($error);
            $lines->push('- ' . ($error->get('message') ?? 'Unknown error'));
        }

        return $lines->join("\n")->append("\n")->val();
    }
}
