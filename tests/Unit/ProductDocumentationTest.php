<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ProductDocumentationTest extends TestCase
{
    private const BLANKET_CAPABILITY_PATTERN =
        '/(?:\bAI(?:-powered|-first|-enabled|-driven)?\b|\bartificial intelligence\b'
        . '|\bintelligent (?:shell|platform|application|system|agent|assistant|service|tool)\b)/i';

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
        $this->assertStringNotContainsString(
            'planned, experimental, evolving, stable, deprecated, or',
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
            $root . DIRECTORY_SEPARATOR . 'composer.json',
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
            $content = $this->withoutCompatibilityIdentifiers($content);
            $this->assertDoesNotMatchRegularExpression(
                self::BLANKET_CAPABILITY_PATTERN,
                $content,
                sprintf('Use capability-specific language in %s.', $path)
            );
        }
    }

    public function testCompatibilityIdentifiersRemainValidInterfaceCopy(): void
    {
        $content = 'Run `list ai for "provider"` or `ai` with AIResource from common/config/ai.php.';

        $this->assertDoesNotMatchRegularExpression(
            self::BLANKET_CAPABILITY_PATTERN,
            $this->withoutCompatibilityIdentifiers($content)
        );
    }

    public function testOrdinaryProseCannotUseCommandVerbsToHideBlanketLanguage(): void
    {
        foreach (['help AI-powered teams', 'do AI-driven work'] as $content) {
            $this->assertMatchesRegularExpression(
                self::BLANKET_CAPABILITY_PATTERN,
                $this->withoutCompatibilityIdentifiers($content)
            );
        }
    }

    public function testStarterProfileAndAddOnOrganizationRemainExplicitlyPlanned(): void
    {
        $root = dirname(__DIR__, 2);
        $documents = [];

        foreach (['PRODUCT.md', 'PRD.md', 'ROADMAP.md'] as $path) {
            $documents[$path] = (string) file_get_contents(
                $root . DIRECTORY_SEPARATOR . $path
            );
        }

        foreach ($documents as $path => $content) {
            $this->assertStringContainsString('#119', $content, $path);
            $this->assertStringContainsString('#120', $content, $path);
        }

        $this->assertStringContainsString('Status: Planned in issue #119', $documents['PRODUCT.md']);
        $this->assertStringContainsString('Status: Planned in issue #120', $documents['PRODUCT.md']);
        $this->assertStringContainsString('does not replace manifest-declared', $documents['PRODUCT.md']);
        $this->assertStringContainsString('does not imply activation', $documents['PRODUCT.md']);
        $this->assertStringContainsString('many-to-many feature groups', $documents['PRD.md']);
        $this->assertStringContainsString('fail-closed lifecycle previews', $documents['ROADMAP.md']);
        $this->assertStringContainsString(
            '`unreleased`, `supported`, `deprecated`, `retired`, or `not_applicable`',
            $documents['ROADMAP.md']
        );
    }

    private function withoutCompatibilityIdentifiers(string $content): string
    {
        return preg_replace(
            [
                '/\bAIResource\b/',
                '#<Command>\s*(?:list|show|find|get|do|help)\s+ai\b[^<]*</Command>#i',
                '/`(?:list|show|find|get|do|help)\s+ai(?:\s+[^`]*)?`/i',
                '/`ai`/i',
                '#common/config/ai\.php#i',
            ],
            '[compatibility identifier]',
            $content
        ) ?? $content;
    }
}
