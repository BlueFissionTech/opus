<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\WiseProfile;
use BlueFission\Arr;
use BlueFission\DevElation;
use BlueFission\Security\Hash;
use BlueFission\Str;
use InvalidArgumentException;

final class ConversationalLearningCatalog
{
    private Arr $catalog;
    private Arr $settings;
    private Arr $intents;
    private Arr $fallbacks;
    private Arr $classifier;
    private Arr $learning;
    private Arr $review;

    public function __construct(array $configuration)
    {
        $configuration = Arr::make($configuration);
        $this->catalog = Arr::make((array) $configuration->get('catalog'));
        $this->settings = Arr::make((array) $configuration->get('settings'));
        $this->intents = Arr::make((array) $configuration->get('intents'));
        $this->fallbacks = Arr::make((array) $configuration->get('fallbacks'));
        $this->classifier = Arr::make((array) $configuration->get('classifier'));
        $this->learning = Arr::make((array) $configuration->get('learning'));
        $this->review = Arr::make((array) $configuration->get('review'));

        $this->validate();
    }

    public function id(): string
    {
        return (string) $this->catalog->get('id');
    }

    public function version(): int
    {
        return (int) $this->catalog->get('version');
    }

    public function intents(): array
    {
        return $this->intents->toArray();
    }

    public function fallbacks(): array
    {
        return $this->fallbacks->toArray();
    }

    public function settingsFor(array $application = [], array $tenant = [], array $principal = []): array
    {
        $settings = Arr::make($this->settings->toArray())
            ->mergeRecursive($application)
            ->mergeRecursive($tenant)
            ->mergeRecursive($principal);

        $filtered = DevElation::apply('opus.conversation.settings', [
            'settings' => $settings->toArray(),
            'layers' => [
                'application' => $application,
                'tenant' => $tenant,
                'principal' => $principal,
            ],
        ]);
        $settings = Arr::make(
            Arr::is($filtered) && Arr::is($filtered['settings'] ?? null)
                ? Arr::toArray($filtered['settings'], true)
                : $settings->toArray()
        );

        if (!Arr::make(['shadow', 'review', 'active'])->has($settings->get('mode'), true)) {
            $settings->set('mode', 'shadow');
        }
        if ((bool) $this->settings->get('promotion_requires_review')) {
            $settings->set('promotion_requires_review', true);
        }
        $settings->set('capture_private_conversations', false);
        $settings->set('capture_provider_payloads', false);

        return $settings->toArray();
    }

    public function configurationFor(
        WiseProfile $profile,
        array $application = [],
        array $tenant = [],
        array $principal = []
    ): array {
        $cacheKey = Hash::value([
            'catalog_id' => $this->id(),
            'catalog_version' => $this->version(),
            'profile_type' => $profile->type(),
            'principal_id' => $profile->principalId(),
            'tenant_id' => $profile->tenantId(),
            'intents' => $this->intents(),
        ], 'sha1');

        $settings = $this->settingsFor($application, $tenant, $principal);
        $routes = [
            'status' => 'immutable',
            'intents' => $this->intents(),
            'fallbacks' => $this->fallbacks(),
        ];
        $configuration = [
            'scope' => $profile->key(),
            'dataset' => $this->catalog->toArray(),
            'settings' => $settings,
            'routes' => $routes,
            'classifier' => Arr::merge($this->classifier->toArray(), [
                'cache_key' => $cacheKey,
                'artifact_key' => 'conversation/models/' . $cacheKey . '/intent_naive_bayes.phpml',
            ]),
            'learning' => $this->learning->toArray(),
            'review' => $this->review->toArray(),
        ];

        $filtered = DevElation::apply('opus.conversation.configuration', [
            'configuration' => $configuration,
            'profile' => [
                'scope' => $profile->key(),
                'type' => $profile->type(),
                'principal_id' => $profile->principalId(),
                'tenant_id' => $profile->tenantId(),
            ],
        ]);
        if (Arr::is($filtered) && Arr::is($filtered['configuration'] ?? null)) {
            $configuration = Arr::toArray($filtered['configuration'], true);
        }

        $classifier = Arr::is($configuration['classifier'] ?? null)
            ? Arr::toArray($configuration['classifier'], true)
            : [];
        $learning = Arr::is($configuration['learning'] ?? null)
            ? Arr::toArray($configuration['learning'], true)
            : [];
        $review = Arr::is($configuration['review'] ?? null)
            ? Arr::toArray($configuration['review'], true)
            : [];

        $configuration['scope'] = $profile->key();
        $configuration['dataset'] = $this->catalog->toArray();
        $configuration['settings'] = $settings;
        $configuration['routes'] = $routes;
        $configuration['classifier'] = Arr::merge($classifier, [
            'cache_key' => $cacheKey,
            'artifact_key' => 'conversation/models/' . $cacheKey . '/intent_naive_bayes.phpml',
        ]);
        $configuration['learning'] = Arr::merge($learning, ['automatic_observation' => false]);
        $configuration['review'] = Arr::merge($review, [
            'generated_fallbacks_are_training_data' => false,
            'promotion_requires_approval' => true,
        ]);

        return $configuration;
    }

    public function toArray(): array
    {
        return [
            'catalog' => $this->catalog->toArray(),
            'settings' => $this->settingsFor(),
            'intents' => $this->intents(),
            'fallbacks' => $this->fallbacks(),
            'classifier' => $this->classifier->toArray(),
            'learning' => $this->learning->toArray(),
            'review' => $this->review->toArray(),
        ];
    }

    private function validate(): void
    {
        if (!Str::isNotEmpty((string) $this->catalog->get('id')) || $this->version() < 1) {
            throw new InvalidArgumentException('Conversational catalog requires a stable id and version.');
        }
        if ($this->settings->get('mode') !== 'shadow'
            || $this->settings->get('promotion_requires_review') !== true
        ) {
            throw new InvalidArgumentException('Conversational defaults must begin in reviewed shadow mode.');
        }
        if ($this->intents->isEmpty()) {
            throw new InvalidArgumentException('Conversational catalog requires deterministic intent seeds.');
        }
        if ($this->learning->get('automatic_observation') !== false
            || $this->review->get('promotion_requires_approval') !== true
        ) {
            throw new InvalidArgumentException('Conversational learning must require explicit promotion.');
        }

        $this->intents->each(function ($definition, $intent): void {
            $definition = Arr::make((array) $definition);
            $examples = Arr::make((array) $definition->get('examples'));
            $command = $definition->get('command');
            if (!Str::is($intent)
                || !Str::make((string) $intent)->matches('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/')
                || !Str::is($command)
                || !Str::make((string) $command)->matches('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/')
                || $examples->isEmpty()
            ) {
                throw new InvalidArgumentException('Conversational intent seed is invalid.');
            }
        });
    }
}
