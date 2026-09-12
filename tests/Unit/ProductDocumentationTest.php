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
        $content = 'Run `/list ai for "provider"`, <Command>/help ai</Command>, or `/ai` '
            . 'with AIResource from common/config/ai.php.';

        $this->assertDoesNotMatchRegularExpression(
            self::BLANKET_CAPABILITY_PATTERN,
            $this->withoutCompatibilityIdentifiers($content)
        );
    }

    public function testOrdinaryProseCannotUseCommandVerbsToHideBlanketLanguage(): void
    {
        foreach ([
            'help AI-powered teams',
            'do AI-driven work',
            'Run `help ai for AI-powered applications`.',
            '<Command>help ai for AI-powered applications</Command>',
            'Run `help ai-powered applications`.',
            '<Command>help ai-powered applications</Command>',
            'Build `AI`-powered applications.',
            'Configure `AI` for this application.',
            'Run `help AI`.',
            '<Command>help AI</Command>',
        ] as $content) {
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
        $this->assertStringContainsString(
            'Before installation, activation, resume, suspension, deactivation, removal, or',
            $documents['PRODUCT.md']
        );
        $this->assertStringContainsString('many-to-many feature groups', $documents['PRD.md']);
        $this->assertStringContainsString('complete #120 impact contract', $documents['PRD.md']);
        $this->assertStringContainsString(
            'install, activate, suspend, resume, upgrade, deactivate, and',
            $documents['PRD.md']
        );
        $this->assertStringContainsString('fail-closed lifecycle previews', $documents['ROADMAP.md']);
        $this->assertStringContainsString(
            '`unreleased`, `supported`, `deprecated`, `retired`, or `not_applicable`',
            $documents['ROADMAP.md']
        );
    }

    public function testAddOnsMustProveStandaloneValueBeforeServicePromotion(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'README.md');
        $product = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'PRODUCT.md');
        $requirements = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'PRD.md');
        $roadmap = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'ROADMAP.md');

        $this->assertStringContainsString('### Add-On-First Service Promotion', $product);
        $this->assertStringContainsString('marketplace-ready artifact', $product);
        $this->assertStringContainsString('issue #123 owns that native control plane', $product);
        $this->assertStringContainsString('Issue #124 owns the', $product);
        $this->assertStringContainsString('installation, activation, suspension, resumption', $product);
        $this->assertStringContainsString(
            'Reusable presentation behavior and assets remain in the',
            $product
        );
        $this->assertStringNotContainsString('environment-specific presentation', $product);
        $this->assertStringContainsString('### Optional Runtime Availability And Recovery', $product);
        $this->assertStringContainsString('issue #125 owns the complete host resilience', $product);
        $this->assertStringContainsString('side-effect-free health probe', $product);
        $this->assertStringContainsString('explicit host-level egress allowlist', $product);
        $this->assertStringContainsString('Provider-free deterministic operation', $product);
        $this->assertStringContainsString('separate provider-free mode', $readme);
        $this->assertStringNotContainsString('deterministic providers', $readme);
        $this->assertStringContainsString('machine-readable path', $product);
        $this->assertStringContainsString('path-ownership and conflict policy', $requirements);
        $this->assertStringContainsString('standalone and Opus-hosted execution', $requirements);
        $this->assertStringContainsString('first external conformance pilot', $roadmap);
        $this->assertStringContainsString('unrelated-history merges', $roadmap);
        $this->assertStringContainsString(
            'The service repository is never the authoritative source for',
            $product
        );
        $this->assertStringContainsString('### Promote An Add-On To A Service', $requirements);
        $this->assertStringContainsString(
            '### FR-17 Add-On Ecosystem And Service Promotion',
            $requirements
        );
        $this->assertStringContainsString('Current status: Partial under #123.', $requirements);
        $this->assertStringContainsString('#124 owns the complete operator surface', $requirements);
        $this->assertStringContainsString('generic reviewed Opus release', $requirements);
        $this->assertStringContainsString('### Production Consumer Calibration', $roadmap);
        $this->assertStringContainsString('A production consumer is a demand signal', $roadmap);
        $this->assertStringNotContainsString('MorPro', $roadmap);
        $this->assertStringNotContainsString('Pelorus', $product);
        $this->assertStringContainsString('feature-flagged degraded or', $roadmap);
        $this->assertStringContainsString('authoritative add-on repository', $roadmap);
        $this->assertStringContainsString('### 0-30 Day Contract Window', $roadmap);
        $this->assertStringContainsString('### 31-90 Day Product Window', $roadmap);
        $this->assertStringContainsString('resettable synthetic tenant host conformance in #122', $roadmap);
        $this->assertStringContainsString('Planned under #122', $requirements);
        $this->assertStringContainsString('### FR-18 Optional Runtime Degradation And Recovery', $requirements);
        $this->assertStringContainsString('Current status: Partial under #125.', $requirements);
        $this->assertStringContainsString('recovery behavior for optional runtimes in #125', $roadmap);
        $this->assertStringContainsString('feature, declared dependent, capability consumer', $requirements);
        $this->assertStringNotContainsString('feature, declared dependency, capability consumer', $requirements);
    }

    private function withoutCompatibilityIdentifiers(string $content): string
    {
        return preg_replace(
            [
                '/\bAIResource\b/',
                '#(<Command>\s*/?(?:list|show|find|get|do|help)\s+)ai(?=\s|</Command>)#',
                '/(`\/?(?:list|show|find|get|do|help)\s+)ai(?=\s|`)/',
                '/(?<![A-Za-z0-9_-])`\/?ai`(?![A-Za-z0-9_-])/',
                '#common/config/ai\.php#i',
            ],
            [
                '[compatibility identifier]',
                '$1[compatibility identifier]',
                '$1[compatibility identifier]',
                '[compatibility identifier]',
                '[compatibility identifier]',
            ],
            $content
        ) ?? $content;
    }
}
