<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\ConsultingGuidanceService;
use App\Domain\Guidance\ConsultingGuidanceInterface;
use App\Domain\Guidance\GuidanceRequest;
use App\Domain\Guidance\GuidanceOutcome;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsultingGuidanceServiceTest extends TestCase
{
    private function request(array $changes = []): GuidanceRequest
    {
        return new GuidanceRequest(array_replace([
            'id' => 'decision-1',
            'question' => 'Which maintenance work should receive attention first?',
            'scope' => ['application' => 'operations', 'tenant' => 'team-a', 'principal' => 'operator-1'],
            'evidence' => [['id' => 'survey', 'reference' => 'record:survey-1', 'status' => 'current', 'expires_at' => 200]],
            'constraints' => ['maximum_hours' => 8],
            'regime' => ['maintenance' => true],
            'efficacy' => ['confidence' => null],
            'candidates' => [['id' => 'accessibility'], ['id' => 'indexing']],
            'approval_policy' => ['required' => true],
        ], $changes));
    }

    private function outcome(GuidanceRequest $request, array $changes = []): GuidanceOutcome
    {
        $input = $request->toArray();
        return new GuidanceOutcome(array_replace([
            'request_id' => $input['id'], 'scope' => $input['scope'],
            'recommended' => 'accessibility',
            'alternatives' => [['id' => 'accessibility', 'score' => 0.9], ['id' => 'indexing', 'score' => 0.6]],
            'reasons' => ['Remove a reported barrier to access.'],
            'tradeoffs' => ['Indexing performance improvement waits.'],
            'confidence' => 0.75, 'risk' => 'low', 'expires_at' => 200,
            'value_gates' => [['id' => 'agency', 'status' => 'pass']],
            'constraint_gates' => [['id' => 'capacity', 'status' => 'pass']],
            'audit' => [['provider' => 'deterministic-fixture', 'revision' => '1']],
        ], $changes));
    }

    private function provider(GuidanceOutcome $outcome): ConsultingGuidanceInterface
    {
        return new class($outcome) implements ConsultingGuidanceInterface {
            public int $calls = 0;
            public function __construct(private GuidanceOutcome $outcome) {}
            public function advise(GuidanceRequest $request): GuidanceOutcome
            {
                ++$this->calls;
                return $this->outcome;
            }
        };
    }

    public function testRankingAndReasonsArePreservedWithoutGrantingAuthority(): void
    {
        $request = $this->request();
        $providerOutput = $this->outcome($request, ['authority' => 'execute']);
        $provider = $this->provider($providerOutput);
        $before = json_encode($request, JSON_THROW_ON_ERROR);
        $result = (new ConsultingGuidanceService($provider))->advise($request, 100)->toArray();

        self::assertSame(['accessibility', 'indexing'], array_column($result['alternatives'], 'id'));
        self::assertSame('accessibility', $result['recommended']);
        self::assertSame('review_required', $result['status']);
        self::assertSame(['host_policy'], $result['required_approvals']);
        self::assertSame('none', $result['authority']);
        self::assertSame(0.75, $result['confidence']);
        self::assertSame($providerOutput->toArray()['reasons'], $result['reasons']);
        self::assertSame($providerOutput->toArray()['tradeoffs'], $result['tradeoffs']);
        self::assertSame($before, json_encode($request, JSON_THROW_ON_ERROR));
        self::assertSame(1, $provider->calls);
    }

    public function testAbsentAndFailingProvidersPreserveUnknownsAndHostApprovalPolicy(): void
    {
        $failing = new class implements ConsultingGuidanceInterface {
            public function advise(GuidanceRequest $request): GuidanceOutcome
            {
                throw new RuntimeException('private-provider-credential');
            }
        };
        foreach ([new ConsultingGuidanceService(), new ConsultingGuidanceService($failing)] as $service) {
            $data = $service->advise($this->request(), 100)->toArray();
            self::assertSame('unavailable', $data['status']);
            self::assertNull($data['confidence']);
            self::assertNull($data['recommended']);
            self::assertSame('unknown', $data['risk']);
            self::assertSame(['host_policy'], $data['required_approvals']);
            self::assertStringNotContainsString('private-provider', json_encode($data));
        }
    }

    public function testStaleMissingUnknownAndExpiredEvidenceCannotManufactureConfidence(): void
    {
        foreach (['stale', 'missing', 'unknown', 'current'] as $status) {
            $request = $this->request(['evidence' => [
                ['id' => 'survey', 'reference' => 'record:survey-1', 'status' => $status, 'expires_at' => 100],
            ]]);
            $output = $this->outcome($request, ['missing_evidence' => ['survey', 'other']]);
            $data = (new ConsultingGuidanceService($this->provider($output)))->advise($request, 100)->toArray();
            self::assertSame(['survey', 'other'], $data['missing_evidence']);
            self::assertNull($data['confidence']);
            self::assertSame('review_required', $data['status']);
            self::assertSame($status, $request->toArray()['evidence'][0]['status']);
        }
    }

    public function testExpiryAtTheBoundaryRemovesActionableRecommendation(): void
    {
        $request = $this->request();
        $service = new ConsultingGuidanceService($this->provider($this->outcome($request)));
        $data = $service->advise($request, 200)->toArray();
        self::assertSame('expired', $data['status']);
        self::assertNull($data['recommended']);
        self::assertSame([], $data['alternatives']);
        self::assertSame(['host_policy'], $data['required_approvals']);
        self::assertSame(['survey'], $data['missing_evidence']);
    }

    public function testScopeAndCandidateMismatchesAreRejectedWithoutLeakingProviderData(): void
    {
        $request = $this->request();
        foreach ([
            ['request_id' => 'another-decision'],
            ['scope' => ['application' => 'operations', 'tenant' => 'team-b', 'principal' => 'operator-1']],
            ['scope' => ['application' => 'other', 'tenant' => 'team-a', 'principal' => 'operator-1']],
            ['scope' => ['application' => 'operations', 'tenant' => 'team-a', 'principal' => 'operator-2']],
            ['recommended' => 'not-in-request'],
            ['alternatives' => [['id' => 'unexpected', 'score' => 1]]],
            ['alternatives' => [['id' => 'indexing', 'score' => 1], ['id' => 'indexing', 'score' => 0.5]]],
        ] as $changes) {
            $output = $this->outcome($request, $changes + ['reasons' => ['private-other-scope-text']]);
            $data = (new ConsultingGuidanceService($this->provider($output)))->advise($request, 100)->toArray();
            self::assertSame('invalid', $data['status']);
            self::assertNull($data['recommended']);
            self::assertSame($request->toArray()['scope'], $data['scope']);
            self::assertSame(['host_policy'], $data['required_approvals']);
            self::assertStringNotContainsString('private-other-scope', json_encode($data));
        }
    }

    public function testFailedOrUnknownGatesStillRequireReviewWhenAutomaticApprovalIsDisabled(): void
    {
        $request = $this->request(['approval_policy' => ['required' => false]]);
        foreach (['value_gates', 'constraint_gates'] as $field) {
            foreach (['fail', 'unknown'] as $status) {
                $output = $this->outcome($request, [$field => [['id' => 'test-gate', 'status' => $status]]]);
                $data = (new ConsultingGuidanceService($this->provider($output)))->advise($request, 100)->toArray();
                self::assertSame('review_required', $data['status']);
                self::assertSame($status, $data[$field][0]['status']);
                self::assertSame('none', $data['authority']);
            }
        }
        $data = (new ConsultingGuidanceService($this->provider($this->outcome($request))))->advise($request, 100)->toArray();
        self::assertSame('advisory', $data['status']);
        self::assertSame('none', $data['authority']);
    }

    public function testOverrideIsAuditedWithoutInvokingOrMutatingTheProvider(): void
    {
        $request = $this->request();
        $output = $this->outcome($request);
        $provider = $this->provider($output);
        $before = json_encode($output, JSON_THROW_ON_ERROR);
        $service = new ConsultingGuidanceService($provider);
        $data = $service->override($request, $output, 'indexing', 'The access work is already assigned.', 100)->toArray();
        self::assertSame(0, $provider->calls);
        self::assertSame($before, json_encode($output, JSON_THROW_ON_ERROR));
        self::assertSame('indexing', $data['recommended']);
        self::assertSame('accessibility', $data['audit'][1]['prior_recommended']);
        self::assertSame('operator-1', $data['audit'][1]['principal']);
        self::assertSame(['host_policy', 'execution_policy'], $data['required_approvals']);
        self::assertSame('review_required', $data['status']);
        self::assertSame('none', $data['authority']);
        self::assertSame('expired', $service->override($request, $output, 'indexing', 'Review later.', 200)->toArray()['status']);
    }

    public function testOverrideCannotIntroduceAnUnscopedCandidate(): void
    {
        $request = $this->request();
        $this->expectException(InvalidArgumentException::class);
        (new ConsultingGuidanceService())->override($request, $this->outcome($request), 'foreign', 'Reason', 100);
    }

    public function testRecordsRoundTripAndDetachPhpReferences(): void
    {
        $question = 'Original question';
        $data = $this->request()->toArray();
        $data['question'] = &$question;
        $request = new GuidanceRequest($data);
        $question = 'Changed question';
        self::assertSame('Original question', $request->toArray()['question']);
        self::assertSame($request->toArray(), (new GuidanceRequest(json_decode(json_encode($request), true)))->toArray());
        $output = $this->outcome($request);
        self::assertSame($output->toArray(), (new GuidanceOutcome(json_decode(json_encode($output), true)))->toArray());
    }

    public function testObjectSerializationIsNeverInvoked(): void
    {
        $object = new class implements \JsonSerializable {
            public bool $called = false;
            public function jsonSerialize(): mixed { $this->called = true; return 'unsafe'; }
        };
        try {
            $this->request(['regime' => ['payload' => $object]]);
            self::fail('An executable object was accepted.');
        } catch (InvalidArgumentException) {
            self::assertFalse($object->called);
        }
    }

    public function testInvalidInputIsRejectedAtTheBoundary(): void
    {
        foreach ([['version' => '2.0'], ['scope' => ['application' => 'app', 'principal' => 'owner']],
            ['approval_policy' => ['required' => 'false']], ['candidates' => [['id' => 'a'], ['id' => 'a']]],
            ['candidates' => [2 => ['id' => 'a']]], ['question' => '   '], ['efficacy' => ['score' => INF]],
        ] as $changes) {
            try { $this->request($changes); self::fail('Invalid request accepted.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
