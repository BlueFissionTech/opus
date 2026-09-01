<?php

declare(strict_types=1);

namespace Tests\Unit\Registration;

use App\Business\Services\VibeThemeRenderer;
use App\Business\Services\AgentScopedCommandProcessor;
use App\Domain\Onboarding\IApplicationIntakeRepository;
use App\Domain\Onboarding\Repositories\ApplicationIntakeRepositorySql;
use App\Registration\AppRegistration;
use BlueFission\BlueCore\Theme;
use BlueFission\Str;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AppRegistrationTest extends TestCase
{
    public function testItRegistersOneRendererUnderCanonicalAndCompatibilityNames(): void
    {
        $app = new class {
            /** @var array<string, mixed> */
            public array $delegates = [];

            public function delegate(string $name, mixed $reference): void
            {
                $this->delegates[$name] = $reference;
            }
        };
        $reflection = new ReflectionClass(AppRegistration::class);
        $registration = $reflection->newInstanceWithoutConstructor();
        $appProperty = $reflection->getProperty('_app');
        $appProperty->setValue($registration, $app);

        $registration->registrations();

        $this->assertInstanceOf(VibeThemeRenderer::class, $app->delegates['template']);
        $this->assertSame($app->delegates['template'], $app->delegates['vibe.theme']);
    }

    public function testItBindsWiseCommandsThroughTheAgentScopedProcessor(): void
    {
        $app = new class {
            /** @var array<string, string> */
            public array $bindings = [];

            public function bind(string $abstract, string $concrete): void
            {
                $this->bindings[$abstract] = $concrete;
            }
        };
        $reflection = new ReflectionClass(AppRegistration::class);
        $registration = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('_app')->setValue($registration, $app);

        $registration->bindings();

        $this->assertSame(
            AgentScopedCommandProcessor::class,
            $app->bindings[ICommandProcessor::class]
        );
        $this->assertSame(
            ApplicationIntakeRepositorySql::class,
            $app->bindings[IApplicationIntakeRepository::class]
        );
    }

    public function testBuiltInThemesRenderFromPackageOwnedResources(): void
    {
        $app = new class {
            /** @var array<string, Theme> */
            public array $themes = [];

            public function addTheme(Theme $theme): void
            {
                $this->themes[$theme->name] = $theme;
            }
        };
        $reflection = new ReflectionClass(AppRegistration::class);
        $registration = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('_app')->setValue($registration, $app);

        $registration->themes();

        $this->assertArrayHasKey('app/default', $app->themes);
        $this->assertArrayHasKey('app/admin', $app->themes);
        $this->assertStringContainsString(
            '/resource/markup/default',
            Str::replace($app->themes['app/default']->location, '\\', '/')
        );
        $this->assertStringContainsString(
            '/resource/markup/admin',
            Str::replace($app->themes['app/admin']->location, '\\', '/')
        );

        $renderer = new VibeThemeRenderer(
            static fn (string $name): ?Theme => $app->themes['app/' . $name] ?? null
        );
        $login = $renderer->render('default', 'login.vibe', ['url' => '/login']);
        $administration = $renderer->render('admin', 'login.vibe', [
            'csrfToken' => 'token',
            'appName' => 'Opus',
            'url' => '/admin',
        ]);

        $this->assertStringContainsString('<title>Signin</title>', $login);
        $this->assertStringContainsString('Sign In | Opus', $administration);
    }
}
