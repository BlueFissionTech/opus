<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Console;

use App\Business\Console\CodeManager;
use App\Business\Services\VibeGenerationService;
use PHPUnit\Framework\TestCase;

class CodeManagerTest extends TestCase
{
    public function testGenerateReturnsAHostNeutralCompletedResult(): void
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
        $this->assertSame('generate', $result['operation']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('Generated service Example', $result['output']);
        $this->assertSame('', $output);
    }

    public function testGenerateReturnsAHostNeutralFailureResult(): void
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
        $this->assertSame('generate', $result['operation']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame([['message' => 'Template is invalid.']], $result['errors']);
        $this->assertSame('', $output);
    }

    public function testRenderUsesTheSameOperationResultContract(): void
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
                    'output' => $source,
                    'variables' => $variables,
                ];
            }
        };

        $result = (new CodeManager($service))->render('ready', ['scope' => 'test']);

        $this->assertSame('render', $result['operation']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('ready', $result['output']);
        $this->assertSame(['scope' => 'test'], $result['variables']);
    }
}
