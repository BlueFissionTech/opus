<?php

namespace App\Business\Console;

use App\Business\Services\VibeGenerationService;
use BlueFission\Services\Service;

class CodeManager extends Service
{
    protected VibeGenerationService $generationService;

    public function __construct(?VibeGenerationService $generationService = null)
    {
        $this->generationService = $generationService ?? new VibeGenerationService();

        parent::__construct();
    }

    public function generate($type, $name, $prompt): array
    {
        print "Please wait...\n";
        $result = $this->generationService->renderSource($prompt, [
            'type' => $type,
            'name' => $name,
        ]);

        if ($result['valid']) {
            if ($result['output'] !== '') {
                print $result['output'] . "\n";
            }
            print "Generation completed.\n";
        } else {
            print $this->formatErrors($result['errors']);
        }

        return $result;
    }

    public function render(string $source, array $variables = []): array
    {
        return $this->generationService->renderSource($source, $variables);
    }

    public function renderFile(string $sourcePath, ?string $outputPath = null, array $variables = []): array
    {
        if ($outputPath) {
            return $this->generationService->writeRenderedFile($sourcePath, $outputPath, $variables);
        }

        return $this->generationService->renderFile($sourcePath, $variables);
    }

    private function formatErrors(array $errors): string
    {
        if ($errors === []) {
            return "Generation failed.\n";
        }

        $lines = ["Generation failed:"];
        foreach ($errors as $error) {
            $lines[] = '- ' . ($error['message'] ?? 'Unknown error');
        }

        return implode("\n", $lines) . "\n";
    }
}
