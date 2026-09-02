<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ProductDocumentationTest extends TestCase
{
    public function testProductDocumentsPublishTheRequiredStatusModel(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['PRODUCT.md', 'PRD.md', 'ROADMAP.md', 'docs/terminology.md'] as $path) {
            $this->assertFileExists($root . DIRECTORY_SEPARATOR . $path);
        }

        $roadmap = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'ROADMAP.md');
        foreach (['Available', 'Partial', 'Planned', 'Exploratory'] as $status) {
            $this->assertStringContainsString($status, $roadmap);
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\|\s*(?:Available|Partial|Planned|Exploratory)\s+(?:as|for|in|external)\b/i',
            $roadmap
        );

        $product = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'PRODUCT.md');
        $this->assertStringNotContainsString('| External |', $product);

        $readme = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'README.md');
        foreach (['PRODUCT.md', 'PRD.md', 'ROADMAP.md', 'docs/terminology.md'] as $path) {
            $this->assertStringContainsString($path, $readme);
        }
    }

    public function testPublicOverviewAndInterfaceCopyUseSpecificCapabilityLanguage(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            $root . DIRECTORY_SEPARATOR . 'README.md',
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Business'
                . DIRECTORY_SEPARATOR . 'Prompts' . DIRECTORY_SEPARATOR . 'ConsoleResponse.php',
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Business'
                . DIRECTORY_SEPARATOR . 'Prompts' . DIRECTORY_SEPARATOR . 'GenericResponse.php',
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Business'
                . DIRECTORY_SEPARATOR . 'Prompts' . DIRECTORY_SEPARATOR . 'ObservationResponse.php',
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Business'
                . DIRECTORY_SEPARATOR . 'Prompts' . DIRECTORY_SEPARATOR . 'InsightResponse.php',
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Business'
                . DIRECTORY_SEPARATOR . 'Prompts' . DIRECTORY_SEPARATOR . 'CriticismResponse.php',
            $root . DIRECTORY_SEPARATOR . 'resource' . DIRECTORY_SEPARATOR . 'markup'
                . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'assets'
                . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'custom.js',
        ];
        $markupRoot = $root . DIRECTORY_SEPARATOR . 'resource' . DIRECTORY_SEPARATOR . 'markup';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($markupRoot));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'vibe') {
                $paths[] = $file->getPathname();
            }
        }

        foreach ($paths as $path) {
            $content = (string) file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression(
                '/\bAI(?:-powered|-first|-enabled|-driven)?\b/i',
                $content,
                sprintf('Use capability-specific language in %s.', $path)
            );
        }
    }
}
