<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\ConversationalLearningCatalog;
use App\Domain\Agents\WiseProfile;
use BlueFission\Arr;
use BlueFission\DevElation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConversationalLearningCatalogTest extends TestCase
{
    public function testDefaultCatalogIsVersionedDeterministicAndShadowOnly(): void
    {
        $catalog = $this->catalog();
        $settings = $catalog->settingsFor();

        $this->assertSame('opus.default', $catalog->id());
        $this->assertSame(1, $catalog->version());
        $this->assertSame('shadow', $settings['mode']);
        $this->assertTrue($settings['promotion_requires_review']);
        $this->assertFalse($settings['capture_private_conversations']);
        $this->assertFalse($settings['capture_provider_payloads']);
        $this->assertArrayHasKey('opus.command.discovery', $catalog->intents());
        $this->assertSame(
            'command.list',
            Arr::make($catalog->intents()['opus.command.discovery'])->get('command')
        );
    }

    public function testScopedSettingsLayerWithoutWeakeningProtectedDefaults(): void
    {
        $settings = $this->catalog()->settingsFor(
            ['mode' => 'review'],
            ['capture_unknown_intents' => false],
            [
                'mode' => 'active',
                'promotion_requires_review' => false,
                'capture_private_conversations' => true,
                'capture_provider_payloads' => true,
            ]
        );

        $this->assertSame('active', $settings['mode']);
        $this->assertFalse($settings['capture_unknown_intents']);
        $this->assertTrue($settings['promotion_requires_review']);
        $this->assertFalse($settings['capture_private_conversations']);
        $this->assertFalse($settings['capture_provider_payloads']);
    }

    public function testInvalidScopedModeFallsBackToShadow(): void
    {
        $settings = $this->catalog()->settingsFor(principal: ['mode' => 'unbounded']);

        $this->assertSame('shadow', $settings['mode']);
    }

    public function testScopedTrainingConfigurationSeparatesArtifactsAndDisablesAutomaticLearning(): void
    {
        $catalog = $this->catalog();
        $first = $catalog->configurationFor(
            new WiseProfile(WiseProfile::CENTRAL_AGENT, 'opus.central', 'tenant-a')
        );
        $repeat = $catalog->configurationFor(
            new WiseProfile(WiseProfile::CENTRAL_AGENT, 'opus.central', 'tenant-a')
        );
        $other = $catalog->configurationFor(
            new WiseProfile(WiseProfile::USER, 'user-a', 'tenant-a')
        );

        $this->assertNotEmpty($first['classifier']['cache_key']);
        $this->assertSame($first['classifier']['cache_key'], $repeat['classifier']['cache_key']);
        $this->assertNotSame($first['classifier']['cache_key'], $other['classifier']['cache_key']);
        $this->assertStringContainsString(
            $first['classifier']['cache_key'],
            $first['classifier']['artifact_key']
        );
        $this->assertFalse($first['learning']['automatic_observation']);
        $this->assertFalse($first['review']['generated_fallbacks_are_training_data']);
        $this->assertTrue($first['review']['promotion_requires_approval']);
    }

    public function testCatalogRejectsUnsafeDefaultsAndEmptySeeds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConversationalLearningCatalog([
            'catalog' => ['id' => 'unsafe', 'version' => 1],
            'settings' => [
                'mode' => 'active',
                'promotion_requires_review' => false,
            ],
            'intents' => [],
        ]);
    }

    public function testFiltersExtendScopedConfigurationWithoutWeakeningInvariants(): void
    {
        $this->withDevElationFilters(function (): void {
            DevElation::filter('opus.conversation.settings', static function (array $payload): array {
                $payload['settings']['mode'] = 'active';
                $payload['settings']['custom_review_queue'] = 'priority';
                $payload['settings']['promotion_requires_review'] = false;
                $payload['settings']['capture_private_conversations'] = true;
                $payload['settings']['capture_provider_payloads'] = true;

                return $payload;
            });
            DevElation::filter('opus.conversation.configuration', static function (array $payload): array {
                $payload['configuration']['scope'] = 'other-tenant';
                $payload['configuration']['dataset'] = ['id' => 'replacement'];
                $payload['configuration']['routes'] = ['status' => 'mutable'];
                $payload['configuration']['settings']['configuration_review_queue'] = 'expedited';
                $payload['configuration']['settings']['promotion_requires_review'] = false;
                $payload['configuration']['settings']['capture_private_conversations'] = true;
                $payload['configuration']['settings']['capture_provider_payloads'] = true;
                $payload['configuration']['classifier'] = [
                    'cache_key' => 'shared',
                    'artifact_key' => 'shared.phpml',
                    'confidence_threshold' => 0.85,
                ];
                $payload['configuration']['learning']['automatic_observation'] = true;
                $payload['configuration']['review']['generated_fallbacks_are_training_data'] = true;
                $payload['configuration']['review']['promotion_requires_approval'] = false;

                return $payload;
            });

            $profile = new WiseProfile(WiseProfile::CENTRAL_AGENT, 'opus.central', 'tenant-a');
            $configuration = $this->catalog()->configurationFor($profile);

            $this->assertSame($profile->key(), $configuration['scope']);
            $this->assertSame('opus.default', $configuration['dataset']['id']);
            $this->assertSame('immutable', $configuration['routes']['status']);
            $this->assertSame('active', $configuration['settings']['mode']);
            $this->assertSame('priority', $configuration['settings']['custom_review_queue']);
            $this->assertSame('expedited', $configuration['settings']['configuration_review_queue']);
            $this->assertTrue($configuration['settings']['promotion_requires_review']);
            $this->assertFalse($configuration['settings']['capture_private_conversations']);
            $this->assertFalse($configuration['settings']['capture_provider_payloads']);
            $this->assertSame(0.85, $configuration['classifier']['confidence_threshold']);
            $this->assertNotSame('shared', $configuration['classifier']['cache_key']);
            $this->assertStringContainsString(
                $configuration['classifier']['cache_key'],
                $configuration['classifier']['artifact_key']
            );
            $this->assertFalse($configuration['learning']['automatic_observation']);
            $this->assertFalse($configuration['review']['generated_fallbacks_are_training_data']);
            $this->assertTrue($configuration['review']['promotion_requires_approval']);
        });
    }

    private function catalog(): ConversationalLearningCatalog
    {
        return new ConversationalLearningCatalog(
            (array) require dirname(__DIR__, 4) . '/mapping/conversation.php'
        );
    }

    private function withDevElationFilters(callable $test): void
    {
        $reflection = new \ReflectionClass(DevElation::class);
        $active = $reflection->getProperty('_isActive');
        $filters = $reflection->getProperty('_filters');
        $originalActive = $active->getValue();
        $originalFilters = $filters->getValue();

        try {
            DevElation::up();
            $test();
        } finally {
            $active->setValue(null, $originalActive);
            $filters->setValue(null, $originalFilters);
        }
    }
}
