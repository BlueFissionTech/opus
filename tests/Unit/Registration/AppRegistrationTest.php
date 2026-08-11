<?php

declare(strict_types=1);

namespace Tests\Unit\Registration;

use App\Business\Services\VibeThemeRenderer;
use App\Registration\AppRegistration;
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
}
