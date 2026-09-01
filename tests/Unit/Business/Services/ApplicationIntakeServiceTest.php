<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\ApplicationIntakeService;
use App\Domain\Onboarding\ApplicationIntakeSession;
use App\Domain\Onboarding\IApplicationIntakeRepository;
use BlueFission\DevElation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationIntakeServiceTest extends TestCase
{
    public function testProjectNameIsTheOnlyRequiredStartingAnswer(): void
    {
        $service = new ApplicationIntakeService($this->repository());

        $session = $service->start('Professional Services Hub');
        $data = $session->toArray();

        $this->assertSame('professional-services-hub', $session->applicationSlug());
        $this->assertSame(['project_name' => 'Professional Services Hub'], $session->answers());
        $this->assertSame('Opus', $session->defaults()['agent_name']);
        $this->assertSame('general_web_application', $session->resolvedAnswers()['project_type']);
        $this->assertContains('project_type', $data['unanswered']);
        $this->assertSame(ApplicationIntakeSession::IN_PROGRESS, $session->status());
    }

    public function testStartIsIdempotentWithinAnApplicationScope(): void
    {
        $repository = $this->repository();
        $service = new ApplicationIntakeService($repository);

        $first = $service->start('Service Desk', 'tenant-a');
        $second = $service->start('Service Desk', 'tenant-a', actor: ['id' => 'actor-b']);
        $otherTenant = $service->start('Service Desk', 'tenant-b');
        $applicationScope = $service->start('Service Desk');
        $tenantNamedApplication = $service->start('Service Desk', 'application');

        $this->assertSame($first->sessionId(), $second->sessionId());
        $this->assertNotSame($first->sessionId(), $otherTenant->sessionId());
        $this->assertNotSame($applicationScope->sessionId(), $tenantNamedApplication->sessionId());
        $this->assertSame(4, $repository->writes);
    }

    public function testSlugCollisionsFailClosed(): void
    {
        $service = new ApplicationIntakeService($this->repository());
        $service->start('Service Desk');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('application_slug_conflict');
        $service->start('Different Project', applicationSlug: 'service-desk');
    }

    public function testConcurrentStartsReturnThePersistedSession(): void
    {
        $winner = ApplicationIntakeSession::start('Service Desk', 'tenant-a');
        $repository = new class($winner) implements IApplicationIntakeRepository {
            private bool $firstSave = true;

            public function __construct(private ApplicationIntakeSession $winner)
            {
            }

            public function find(string $sessionId): ?ApplicationIntakeSession
            {
                return $this->firstSave ? null : $this->winner;
            }

            public function save(ApplicationIntakeSession $session): ApplicationIntakeSession
            {
                $this->firstSave = false;
                throw new RuntimeException('intake_session_already_exists');
            }
        };

        $session = (new ApplicationIntakeService($repository))->start('Service Desk', 'tenant-a');

        $this->assertSame($winner->toArray(), $session->toArray());
    }

    public function testAnswersDefaultsSkipsAndUnansweredFieldsRemainDistinct(): void
    {
        $service = new ApplicationIntakeService($this->repository());
        $session = $service->start('Operations');

        $skipped = $service->skip($session->sessionId(), 'audience');
        $answered = $service->answer($skipped->sessionId(), 'audience', 'internal_operators');

        $this->assertContains('audience', $skipped->skipped());
        $this->assertNotContains('audience', $skipped->unanswered());
        $this->assertSame('b2c_consumers', $skipped->resolvedAnswers()['audience']);
        $this->assertNotContains('audience', $answered->skipped());
        $this->assertSame('internal_operators', $answered->answers()['audience']);
        $this->assertSame('internal_operators', $answered->resolvedAnswers()['audience']);
    }

    public function testPausedAndCompletedSessionsCanResumeWithoutExecutingWork(): void
    {
        $service = new ApplicationIntakeService($this->repository());
        $session = $service->start('Automation Studio');

        $paused = $service->pause($session->sessionId(), ['id' => 'operator-a'], 'pause-a');
        $completed = $service->complete($paused->sessionId(), ['id' => 'operator-a'], 'complete-a');
        $resumed = $service->resume($completed->sessionId(), ['id' => 'operator-b'], 'resume-b');

        $this->assertSame(ApplicationIntakeSession::PAUSED, $paused->status());
        $this->assertSame(ApplicationIntakeSession::COMPLETED, $completed->status());
        $this->assertNotNull($completed->toArray()['completed_at']);
        $this->assertSame(ApplicationIntakeSession::IN_PROGRESS, $resumed->status());
        $this->assertNull($resumed->toArray()['completed_at']);
        $this->assertSame('resume-b', $resumed->toArray()['correlation_id']);
    }

    public function testRequiredAndUnknownFieldsFailClosed(): void
    {
        $service = new ApplicationIntakeService($this->repository());

        try {
            $service->start('   ');
            $this->fail('A blank project name should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('project_name_required', $exception->getMessage());
        }

        $session = $service->start('Knowledge Base');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown_intake_field');
        $service->answer($session->sessionId(), 'provider_api_key', 'secret');
    }

    public function testProjectNameAnswersAreTrimmedStrings(): void
    {
        $service = new ApplicationIntakeService($this->repository());
        $session = $service->start('Knowledge Base');

        $renamed = $service->answer($session->sessionId(), 'project_name', '  Knowledge Studio  ');
        $this->assertSame('Knowledge Studio', $renamed->answers()['project_name']);

        foreach ([[], ['invalid'], '   '] as $invalidName) {
            try {
                $service->answer($session->sessionId(), 'project_name', $invalidName);
                $this->fail('Project names must be non-empty strings.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('project_name_required', $exception->getMessage());
            }
        }
    }

    public function testDefaultsCanBeFilteredWithoutReplacingAuthoritativeIdentity(): void
    {
        $this->withDevElationHooks(function (): void {
            DevElation::filter('opus.intake.defaults', static function (array $payload): array {
                $payload['defaults']['agent_name'] = 'Atlas';
                $payload['context']['project_name'] = 'Replaced';
                $payload['context']['tenant_id'] = 'tenant-b';
                $payload['context']['application_slug'] = 'replaced';

                return $payload;
            });

            $session = (new ApplicationIntakeService($this->repository()))->start(
                'Service Studio',
                'tenant-a'
            );

            $this->assertSame('Atlas', $session->defaults()['agent_name']);
            $this->assertSame('Service Studio', $session->answers()['project_name']);
            $this->assertSame('tenant-a', $session->tenantId());
            $this->assertSame('service-studio', $session->applicationSlug());
        });
    }

    public function testPersistedTransitionsPublishOneStableActionPayload(): void
    {
        $this->withDevElationHooks(function (): void {
            $transitions = [];
            DevElation::action(
                'opus.intake.session.transitioned',
                static function (array $payload) use (&$transitions): void {
                    $transitions[] = $payload;
                }
            );

            $service = new ApplicationIntakeService($this->repository());
            $session = $service->start('Workflow Studio', 'tenant-a');
            $service->start('Workflow Studio', 'tenant-a');
            $session = $service->answer($session->sessionId(), 'audience', 'operators');
            $session = $service->skip($session->sessionId(), 'timeline');
            $session = $service->pause($session->sessionId());
            $session = $service->resume($session->sessionId());
            $service->complete($session->sessionId());

            $this->assertSame(
                ['started', 'answered', 'skipped', 'paused', 'resumed', 'completed'],
                array_column($transitions, 'transition')
            );
            $this->assertSame('tenant-a', $transitions[0]['session']['tenant_id']);
            $this->assertSame('workflow-studio', $transitions[5]['session']['application_slug']);
            $this->assertSame(ApplicationIntakeSession::COMPLETED, $transitions[5]['session']['status']);
        });
    }

    public function testMissingSessionsReturnAStableFailure(): void
    {
        $service = new ApplicationIntakeService($this->repository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('intake_session_not_found');
        $service->resume('missing');
    }

    private function repository(): object
    {
        return new class implements IApplicationIntakeRepository {
            /** @var array<string, ApplicationIntakeSession> */
            public array $sessions = [];
            public int $writes = 0;

            public function find(string $sessionId): ?ApplicationIntakeSession
            {
                return $this->sessions[$sessionId] ?? null;
            }

            public function save(ApplicationIntakeSession $session): ApplicationIntakeSession
            {
                $this->sessions[$session->sessionId()] = $session;
                $this->writes++;

                return $session;
            }
        };
    }

    private function withDevElationHooks(callable $test): void
    {
        $reflection = new \ReflectionClass(DevElation::class);
        $active = $reflection->getProperty('_isActive');
        $filters = $reflection->getProperty('_filters');
        $actions = $reflection->getProperty('_actions');
        $originalActive = $active->getValue();
        $originalFilters = $filters->getValue();
        $originalActions = $actions->getValue();

        try {
            DevElation::up();
            $test();
        } finally {
            $active->setValue(null, $originalActive);
            $filters->setValue(null, $originalFilters);
            $actions->setValue(null, $originalActions);
        }
    }
}
