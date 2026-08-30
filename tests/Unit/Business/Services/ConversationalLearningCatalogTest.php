<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\ConversationalLearningCatalog;
use App\Domain\Agents\WiseProfile;
use BlueFission\Arr;
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

    private function catalog(): ConversationalLearningCatalog
    {
        return new ConversationalLearningCatalog(
            (array) require dirname(__DIR__, 4) . '/mapping/conversation.php'
        );
    }
}
