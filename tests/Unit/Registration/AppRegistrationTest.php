<?php

declare(strict_types=1);

namespace Tests\Unit\Registration;

use App\Business\Services\VibeThemeRenderer;
use App\Business\Services\ConsultingGuidanceService;
use App\Business\Services\UnavailableConsultingGuidance;
use App\Domain\Guidance\ConsultingGuidanceInterface;
use App\Domain\Guidance\GuidanceRequest;
use App\Domain\Guidance\GuidanceOutcome;
use BlueFission\Services\Application;
use App\Business\Services\ConversationalLearningCatalog;
use App\Business\Services\LazyAgentCommandProcessor;
use App\Business\Services\RuntimePathResolver;
use App\Business\Services\WiseProfilePolicyResolver;
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

        $this->assertSame(ConsultingGuidanceService::class, $app->delegates['guidance']);

        $this->assertInstanceOf(VibeThemeRenderer::class, $app->delegates['template']);
        $this->assertSame($app->delegates['template'], $app->delegates['vibe.theme']);
        $this->assertInstanceOf(
            WiseProfilePolicyResolver::class,
            $app->delegates['wise.profile.policy']
        );
        $this->assertInstanceOf(
            ConversationalLearningCatalog::class,
            $app->delegates['conversation.catalog']
        );
    }

    public function testItBindsWiseCommandsThroughTheLazyAgentProcessor(): void
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

        $this->assertSame(UnavailableConsultingGuidance::class, $app->bindings[ConsultingGuidanceInterface::class]);

        $this->assertSame(
            LazyAgentCommandProcessor::class,
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

    public function testHostFrontendSelectionPrefersAdaAndKeepsAdminPackageOwned(): void
    {
        $host = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-theme-' . bin2hex(random_bytes(6));
        $markup = $host . DIRECTORY_SEPARATOR . 'resource' . DIRECTORY_SEPARATOR . 'markup';
        foreach (['ada', 'alternate'] as $name) {
            mkdir($markup . DIRECTORY_SEPARATOR . $name, 0777, true);
            file_put_contents(
                $markup . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'default.vibe',
                '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<link rel="stylesheet" href="/assets/themes/ada/theme.css"></head><body>'
                . '<a href="#main">Skip to content</a><main id="main"><h1>Test</h1>'
                . '<a href="/login">Sign in</a></main></body></html>'
            );
            file_put_contents(
                $markup . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'login.vibe',
                '<main><h1>Login</h1><form><label for="email">Email</label>'
                . '<input id="email" type="email"><button type="submit">Sign in</button></form></main>'
            );
        }
        $previousPaths = $GLOBALS['OPUS_RUNTIME_PATHS'] ?? null;
        $hadPaths = array_key_exists('OPUS_RUNTIME_PATHS', $GLOBALS);
        $previousTheme = $GLOBALS['OPUS_FRONTEND_THEME'] ?? null;
        $hadTheme = array_key_exists('OPUS_FRONTEND_THEME', $GLOBALS);
        $GLOBALS['OPUS_RUNTIME_PATHS'] = RuntimePathResolver::discover(dirname(__DIR__, 3), $host);
        unset($GLOBALS['OPUS_FRONTEND_THEME']);

        try {
            $themes = $this->registeredThemes();
            $this->assertSame(realpath($markup . DIRECTORY_SEPARATOR . 'ada'), rtrim($themes['app/default']->location, '\\/'));
            $this->assertStringContainsString('resource/markup/admin', Str::replace($themes['app/admin']->location, '\\', '/'));
            $renderer = new VibeThemeRenderer(static fn (string $name): ?Theme => $themes['app/' . $name] ?? null);
            $default = $renderer->render('default', 'default.vibe');
            $login = $renderer->render('default', 'login.vibe');
            $this->assertStringContainsString('<h1>Test</h1>', $default);
            $this->assertStringContainsString('href="#main">Skip to content</a>', $default);
            $this->assertStringContainsString('href="/assets/themes/ada/theme.css"', $default);
            $this->assertStringContainsString('name="viewport"', $default);
            $this->assertStringContainsString('<h1>Login</h1>', $login);
            $this->assertStringContainsString('<label for="email">Email</label>', $login);
            $this->assertStringContainsString('<button type="submit">Sign in</button>', $login);

            $GLOBALS['OPUS_FRONTEND_THEME'] = 'alternate';
            $this->assertSame(realpath($markup . DIRECTORY_SEPARATOR . 'alternate'), rtrim($this->registeredThemes()['app/default']->location, '\\/'));

            unlink($markup . DIRECTORY_SEPARATOR . 'alternate' . DIRECTORY_SEPARATOR . 'login.vibe');
            $this->assertSame(realpath($markup . DIRECTORY_SEPARATOR . 'ada'), rtrim($this->registeredThemes()['app/default']->location, '\\/'));

            unlink($markup . DIRECTORY_SEPARATOR . 'ada' . DIRECTORY_SEPARATOR . 'login.vibe');
            $this->assertStringContainsString('resource/markup/default', Str::replace($this->registeredThemes()['app/default']->location, '\\', '/'));

            $GLOBALS['OPUS_FRONTEND_THEME'] = '../outside';
            $this->expectException(\InvalidArgumentException::class);
            $this->registeredThemes();
        } finally {
            if ($hadPaths) { $GLOBALS['OPUS_RUNTIME_PATHS'] = $previousPaths; }
            else { unset($GLOBALS['OPUS_RUNTIME_PATHS']); }
            if ($hadTheme) { $GLOBALS['OPUS_FRONTEND_THEME'] = $previousTheme; }
            else { unset($GLOBALS['OPUS_FRONTEND_THEME']); }
            foreach (['ada', 'alternate'] as $name) {
                foreach (['default.vibe', 'login.vibe'] as $file) {
                    $path = $markup . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . $file;
                    if (is_file($path)) { unlink($path); }
                }
                rmdir($markup . DIRECTORY_SEPARATOR . $name);
            }
            rmdir($markup);
            rmdir($host . DIRECTORY_SEPARATOR . 'resource');
            rmdir($host);
        }
    }

    public function testGuidanceProviderResolvesThroughTheApplicationContainer(): void
    {
        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(AppRegistration::class);
        $registration = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('_app')->setValue($registration, $app);
        $registration->bindings();
        $request = new GuidanceRequest([
            'id' => 'container-proof', 'question' => 'What evidence is missing?',
            'scope' => ['application' => 'test', 'tenant' => null, 'principal' => 'operator'],
        ]);

        $default = $app->getDynamicInstance(ConsultingGuidanceService::class)->advise($request, 1)->toArray();
        self::assertSame('unavailable', $default['status']);
        self::assertSame(['guidance_provider_unavailable'], $default['reasons']);

        $app->bind(ConsultingGuidanceInterface::class, RegistrationGuidanceProvider::class);
        $custom = $app->getDynamicInstance(ConsultingGuidanceService::class)->advise($request, 1)->toArray();
        self::assertSame(['application_provider'], $custom['reasons']);
        self::assertSame(['host_policy'], $custom['required_approvals']);
    }

    /** @return array<string, Theme> */
    private function registeredThemes(): array
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

        return $app->themes;
    }
}

final class RegistrationGuidanceProvider implements ConsultingGuidanceInterface
{
    public function advise(GuidanceRequest $request): GuidanceOutcome
    {
        return GuidanceOutcome::unavailable($request, 'application_provider');
    }
}
