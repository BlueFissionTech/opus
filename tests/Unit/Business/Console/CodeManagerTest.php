<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Console;

use App\Business\Console\CodeManager;
use App\Business\Services\VibeGenerationService;
use PHPUnit\Framework\TestCase;

class CodeManagerTest extends TestCase
{
    public function testGenerateReturnsResultAndPrintsRenderedOutput(): void
    {
        $service = new class extends VibeGenerationService {
            public function renderSource(
                string $source,
                array $variables = [],
                array $includePaths = [],
                array $options = []
            ): array {
                return [
                    'valid' => true,
                    'errors' => [],
                    'output' => "Generated {$variables['type']} {$variables['name']}",
                    'variables' => $variables,
                ];
            }
        };
        $manager = new CodeManager($service);

        ob_start();
        $result = $manager->generate('service', 'Example', 'ignored');
        $output = (string) ob_get_clean();

        $this->assertTrue($result['valid']);
        $this->assertSame('Generated service Example', $result['output']);
        $this->assertStringContainsString('Generated service Example', $output);
        $this->assertStringContainsString('Generation completed.', $output);
    }

    public function testGeneratePrintsStructuredValidationErrors(): void
    {
        $service = new class extends VibeGenerationService {
            public function renderSource(
                string $source,
                array $variables = [],
                array $includePaths = [],
                array $options = []
            ): array {
                return [
                    'valid' => false,
                    'errors' => [
                        ['message' => 'Template is invalid.'],
                    ],
                    'output' => '',
                    'variables' => [],
                ];
            }
        };
        $manager = new CodeManager($service);

        ob_start();
        $result = $manager->generate('service', 'Example', 'ignored');
        $output = (string) ob_get_clean();

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Generation failed:', $output);
        $this->assertStringContainsString('- Template is invalid.', $output);
    }
}
