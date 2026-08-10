<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\VibeThemeRenderer;
use BlueFission\Vibrato\Reader;
use PHPUnit\Framework\TestCase;

final class VibeThemeRendererTest extends TestCase
{
    private string $markupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markupDirectory = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'resource' . DIRECTORY_SEPARATOR . 'markup';
    }

    public function testItRendersTheDefaultLayoutWithNamedRegionsAndEscapedVariables(): void
    {
        $reader = new RecordingReader();
        $renderer = $this->renderer('default', $reader);

        $output = $renderer->render('default', 'default.vibe', [
            'name' => 'Opus <script>alert(1)</script>',
            'title' => 'Build & coordinate',
            'url' => 'https://example.com/?a=1&b=2',
            'csrfToken' => 'token"value',
        ]);

        $this->assertStringContainsString('<!doctype html>', $output);
        $this->assertStringContainsString('aria-label="Primary"', $output);
        $this->assertStringContainsString('Opus &lt;script&gt;alert(1)&lt;/script&gt;', $output);
        $this->assertStringContainsString('Build &amp; coordinate', $output);
        $this->assertStringNotContainsString('@template(', $output);
        $this->assertStringNotContainsString('@output(', $output);
        $this->assertSame(false, $reader->runConfig['run_backend'] ?? null);
        $this->assertSame(true, $reader->runConfig['validate_syntax'] ?? null);
    }

    public function testItRendersAdminIncludesAndOnlyAllowsExplicitTrustedMarkup(): void
    {
        $renderer = $this->renderer('admin');
        $navigation = '<li class="sidebar-item">Overview</li>';

        $output = $renderer->render(
            'admin',
            'default.vibe',
            [
                'appName' => 'Opus & Company',
                'title' => '<Admin>',
                'url' => '/admin',
                'csrfToken' => 'token',
                'sideNav' => $navigation,
            ],
            ['sideNav']
        );

        $this->assertStringContainsString($navigation, $output);
        $this->assertStringContainsString('Opus &amp; Company', $output);
        $this->assertStringContainsString('&lt;Admin&gt;', $output);
        $this->assertStringContainsString('id="servicesDropdown"', $output);
        $this->assertStringNotContainsString('@include(', $output);
    }

    public function testItRejectsNonVibeAndTraversalPaths(): void
    {
        $renderer = $this->renderer('default');

        foreach (['default.html', '../default/default.vibe', '/default.vibe'] as $file) {
            try {
                $renderer->render('default', $file);
                $this->fail("Expected '{$file}' to be rejected.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItRendersStructuredNavigationWithEscapedLoopValues(): void
    {
        $renderer = $this->renderer('admin');

        $output = $renderer->render('admin', 'modules/menu.vibe', [
            'menuItems' => [
                ['label' => 'Users <admin>', 'action' => '/users?a=1&b=2'],
                ['label' => 'Add-ons', 'action' => '/addons'],
            ],
        ], [], ['menuItems']);

        $this->assertStringContainsString('Users &lt;admin&gt;', $output);
        $this->assertStringContainsString('/users?a=1&amp;b=2', $output);
        $this->assertSame(2, substr_count($output, 'class="sidebar-item"'));
    }

    public function testItRejectsMissingRequiredContextWithoutEmittingTemplateSource(): void
    {
        $renderer = $this->renderer('default');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("requires variable 'name'");

        $renderer->render('default', 'default.vibe', [], [], ['name']);
    }

    private function renderer(string $themeName, ?Reader $reader = null): VibeThemeRenderer
    {
        $themeDirectory = $this->markupDirectory . DIRECTORY_SEPARATOR . $themeName;

        return new VibeThemeRenderer(
            static fn (string $requestedTheme): object => (object) [
                'location' => $themeDirectory,
                'name' => $requestedTheme,
            ],
            $reader ? static fn (): Reader => $reader : null
        );
    }
}

final class RecordingReader extends Reader
{
    /** @var array<string, mixed> */
    public array $runConfig = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    public function run(array $config = []): array
    {
        $this->runConfig = $config;

        return parent::run($config);
    }
}
