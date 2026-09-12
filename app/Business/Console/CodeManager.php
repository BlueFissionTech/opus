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
        return $this->result('generate', $this->generationService->renderSource($prompt, [
            'type' => $type,
            'name' => $name,
        ]));
    }

    public function render(string $source, array $variables = []): array
    {
        return $this->result('render', $this->generationService->renderSource($source, $variables));
    }

    public function renderFile(string $sourcePath, ?string $outputPath = null, array $variables = []): array
    {
        if (Str::isNotEmpty($outputPath)) {
            return $this->result(
                'render_file',
                $this->generationService->writeRenderedFile($sourcePath, $outputPath, $variables)
            );
        }

        return $this->result('render_file', $this->generationService->renderFile($sourcePath, $variables));
    }

    private function result(string $operation, array $result): array
    {
        $result = Arr::make($result);
        $valid = (bool) $result->get('valid');
        $result->set('operation', $operation);
        $result->set('status', $valid ? 'completed' : 'failed');
        $result->set('exit_code', $valid ? 0 : 1);

        return $result->toArray();
    }
}
