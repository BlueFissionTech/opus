<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\ApplicationIntakePromptRenderer;
use App\Domain\Onboarding\ApplicationIntakeSession;
use App\Registration\AppRegistration;
use BlueFission\DevElation;
use BlueFission\Services\Application;
use BlueFission\Services\Service;
use BlueFission\Vibrato\Reader;
use BlueFission\Vibrato\Runtime\RuntimeEvent;
use BlueFission\Vibrato\Runtime\RuntimeSignal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ApplicationIntakePromptRendererTest extends TestCase
{
    public function testNewIntakeAsksOnlyForTheRequiredProjectName(): void
    {
        $output = (new ApplicationIntakePromptRenderer())->render();
        self::assertStringContainsString('Intake prompt 1.0', $output);
        self::assertStringContainsString('project_name: What is the project name?', $output);
        self::assertStringNotContainsString('project_type:', $output);
        self::assertStringContainsString('Only the project name is required.', $output);
        self::assertStringContainsString('does not authorize implementation', $output);
        self::assertStringNotContainsString('{#if', $output);
    }

    public function testExistingIntakeAsksUnansweredOptionalFieldsInStableOrder(): void
    {
        $session = ApplicationIntakeSession::start('Private Project');
        $output = (new ApplicationIntakePromptRenderer())->render($session);
        self::assertStringNotContainsString('project_name:', $output);
        self::assertStringNotContainsString('Private Project', $output);
        $previous = -1;
        foreach ($session->unanswered() as $field) {
            $position = strpos($output, $field . ':');
            self::assertNotFalse($position, $field);
            self::assertGreaterThan($previous, $position, $field);
            $previous = $position;
        }
        self::assertStringContainsString('You may skip any optional question or complete intake now.', $output);
    }

    public function testAnswersAndSkippedFieldsStayDistinctAcrossReload(): void
    {
        $session = ApplicationIntakeSession::start('Project')
            ->answer('description', 'Private answer')->skip('industry');
        $before = $session->toArray();
        $renderer = new ApplicationIntakePromptRenderer();
        $first = $renderer->render($session);
        $reloaded = ApplicationIntakeSession::fromArray($before);
        self::assertSame($first, (new ApplicationIntakePromptRenderer())->render($reloaded));
        self::assertSame($before, $session->toArray());
        self::assertSame($before, $reloaded->toArray());
        self::assertStringNotContainsString('description:', $first);
        self::assertStringNotContainsString('industry:', $first);
        self::assertStringContainsString('audience:', $first);
        self::assertSame(['industry'], $session->skipped());
        self::assertSame('Private answer', $session->answers()['description']);
    }

    public function testPausedAndCompletedIntakeNeverResumesByRendering(): void
    {
        $initial = ApplicationIntakeSession::start('Project');
        foreach ([$initial->pause(), $initial->complete()] as $session) {
            $before = $session->toArray();
            $output = (new ApplicationIntakePromptRenderer())->render($session);
            self::assertStringContainsString('Explicitly resume', $output);
            self::assertStringNotContainsString('project_type:', $output);
            self::assertSame($before, $session->toArray());
            self::assertStringContainsString('project_type:', (new ApplicationIntakePromptRenderer())->render($session->resume()));
        }
    }

    public function testAllOptionalQuestionsCanBeSkippedWithoutStartingWork(): void
    {
        $session = ApplicationIntakeSession::start('Project');
        foreach ($session->unanswered() as $field) { $session = $session->skip($field); }
        $before = $session->toArray();
        $output = (new ApplicationIntakePromptRenderer())->render($session);
        self::assertStringContainsString('No unanswered questions remain.', $output);
        self::assertStringNotContainsString('project_type:', $output);
        self::assertSame(ApplicationIntakeSession::IN_PROGRESS, $session->status());
        self::assertSame($before, $session->toArray());
    }

    public function testUnsupportedStoredVersionCannotSelectAFileOrConstructAReader(): void
    {
        $calls = 0;
        $renderer = new ApplicationIntakePromptRenderer(static function () use (&$calls): Reader {
            $calls++;
            return new Reader(null);
        });
        foreach (['2.0', '../private', '', '1.0.vibe'] as $version) {
            $session = ApplicationIntakeSession::start('Project', promptVersion: $version);
            try {
                $renderer->render($session);
                self::fail('Unsupported prompt version must fail closed.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('intake_prompt_version_unsupported', $exception->getMessage());
            }
        }
        self::assertSame(0, $calls);
    }

    public function testNativeRuntimeReceivesNoPrivateAnswersOrIdentifiers(): void
    {
        $secret = 'PRIVATE_SENTINEL_{#let injected=1}_{=private_provider_call()}';
        $session = ApplicationIntakeSession::start($secret, 'private-tenant', 'private-slug',
            actor: ['id' => 'private-actor'], correlationId: 'private-correlation',
            defaults: ['description' => 'private-default'])
            ->answer('description', $secret);
        $before = $session->toArray();
        $signals = [];
        $generation = 0;
        DevElation::action(RuntimeEvent::RENDER_COMPLETE, static function (RuntimeSignal $signal) use (&$signals): void {
            $signals[] = $signal->snapshot();
        });
        DevElation::action(RuntimeEvent::GENERATION_START, static function () use (&$generation): void { $generation++; });
        DevElation::up();
        $reader = new IntakePromptRecordingReader();
        $output = (new ApplicationIntakePromptRenderer(static fn (): Reader => $reader))->render($session);
        self::assertCount(1, $signals);
        self::assertSame(0, $generation);
        self::assertNull($reader->getLLM());
        self::assertSame(['validate_syntax' => true, 'run_backend' => false], $reader->runConfig);
        $observed = json_encode([$signals, $reader->variables(), $reader->runtime()->source(), $output], JSON_THROW_ON_ERROR);
        foreach ([$secret, 'private-tenant', 'private-slug', 'private-actor', 'private-correlation', 'private-default', $session->sessionId()] as $private) {
            self::assertStringNotContainsString($private, $observed);
        }
        self::assertSame($before, $session->toArray());
    }

    public function testFailedRenderingDoesNotExposeDetailsOrChangeIntake(): void
    {
        $session = ApplicationIntakeSession::start('Project')->pause();
        $before = $session->toArray();
        $renderer = new ApplicationIntakePromptRenderer(static function (): Reader {
            throw new RuntimeException('private-provider-or-path');
        });
        try {
            $renderer->render($session);
            self::fail('Render failure must not become a successful prompt.');
        } catch (RuntimeException $exception) {
            self::assertSame('intake_prompt_unavailable', $exception->getMessage());
        }
        self::assertSame($before, $session->toArray());
    }

    public function testNativeApplicationRegistrationExposesTheReadOnlyService(): void
    {
        $application = new IntakePromptApplication(['name' => 'intake-prompt-fixture']);
        $readers = 0;
        $application->bindArgs(['readerFactory' => static function () use (&$readers): Reader {
            $readers++;
            return new Reader(null);
        }], ApplicationIntakePromptRenderer::class);
        $reflection = new ReflectionClass(AppRegistration::class);
        $registration = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('_app')->setValue($registration, $application);
        $registration->bindings();
        $registration->registrations();
        $service = $application->service('intake.prompt');
        self::assertInstanceOf(ApplicationIntakePromptRenderer::class, $service);
        self::assertInstanceOf(Service::class, $service);
        self::assertSame(0, $readers, 'Registration must not construct the template runtime.');
        self::assertStringContainsString('project_name:', $service->render());
        self::assertSame(1, $readers);
    }
}

final class IntakePromptRecordingReader extends Reader
{
    public array $runConfig = [];
    public function __construct() { parent::__construct(null); }
    public function run(array $config = []): array
    {
        $this->runConfig = $config;
        return parent::run($config);
    }
}

final class IntakePromptApplication extends Application
{
    public function delegate($name, $reference = null, $args = null)
    {
        // Keep actual native resolution/delegation; exclude unrelated database startup.
        return $name === 'intake.prompt' ? parent::delegate($name, $reference, $args) : $this;
    }
}
