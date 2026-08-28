<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapCatalog;
use App\Business\Services\AgentCapabilityMapLoader;
use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCapabilityMapValidator;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\AgentScopedCommandProcessor;
use App\Business\Services\WiseCommandHost;
use App\Business\Services\AddOnContractValidator;
use App\Business\Services\DeclarativeArrayParser;
use App\Business\Presentation\CommandResultPresenter;
use App\Business\Middleware\ProcessesCommandMiddleware;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\IAgentContinuationScopeStore;
use App\Domain\Console\CommandPresentation;
use BlueFission\Arr;
use BlueFission\BlueCore\Business\Managers\CommandManager;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\DevElation;
use BlueFission\Obj;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Wise\Cmd\ICommandProcessor;
use PHPUnit\Framework\TestCase;

final class AgentCapabilityMapTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-agent-map-' . bin2hex(random_bytes(5));
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workspace);
    }

    public function testAgentDescriptorsParticipateInTheDevelationObjectLifecycle(): void
    {
        $descriptor = new AgentDescriptor('addon.sample', 'sample', [
            'mode' => 'specialist',
            'tools' => ['sample.run'],
        ]);

        $this->assertInstanceOf(Obj::class, $descriptor);
        $this->assertSame('addon.sample', $descriptor->id());
        $this->assertSame(['sample.run'], $descriptor->tools());
    }

    public function testDeclarativeParserRejectsExecutionAndDuplicateAgentIdentifiers(): void
    {
        $executable = $this->workspace . '/executable.php';
        file_put_contents($executable, "<?php\nreturn build_agent_map();\n");
        $duplicates = $this->workspace . '/duplicates.php';
        file_put_contents($duplicates, <<<'PHP'
<?php

return [
    'version' => 1,
    'agents' => [
        'addon.sample' => [],
        'addon.sample' => [],
    ],
];
PHP
        );

        $parser = new DeclarativeArrayParser();
        $executionResult = $parser->parseFile($executable);
        $duplicateResult = $parser->parseFile($duplicates);

        $this->assertFalse($executionResult['valid']);
        $this->assertSame('mapping_declarative', $executionResult['errors'][0]['code']);
        $this->assertFalse($duplicateResult['valid']);
        $this->assertSame('mapping_duplicate_key', $duplicateResult['errors'][0]['code']);
    }

    public function testDeclarativeParserAllowsOnlyExactStrictTypesDeclaration(): void
    {
        $path = $this->workspace . '/strict.php';
        file_put_contents($path, "<?php\ndeclare(ticks=1);\nreturn [];\n");

        $result = (new DeclarativeArrayParser())->parseFile($path);

        $this->assertFalse($result['valid']);
        $this->assertSame('mapping_declarative', $result['errors'][0]['code']);
    }

    public function testDeclarativeParserAcceptsAnOptionalClosingTag(): void
    {
        $path = $this->workspace . '/closing-tag.php';
        file_put_contents($path, "<?php\nreturn ['resources' => ['sample' => ['list']]];\n?>");

        $result = (new DeclarativeArrayParser())->parseFile($path);

        $this->assertTrue($result['valid']);
        $this->assertSame(['sample' => ['list']], $result['value']['resources']);
    }

    public function testTopLevelParserRecognizesParenthesizedLiteralKeys(): void
    {
        $path = $this->workspace . '/parenthesized-key.php';
        file_put_contents(
            $path,
            "<?php\nreturn [('resources') => ['sample' => ['list']]];\n"
        );

        $result = (new DeclarativeArrayParser())->parseTopLevelKey($path, 'resources');

        $this->assertTrue($result['valid']);
        $this->assertSame(['sample' => ['list']], $result['value']);
    }

    public function testDeclarativeParserRejectsKeysThatPhpNormalizesToTheSameOffset(): void
    {
        $path = $this->workspace . '/normalized-duplicates.php';
        file_put_contents($path, <<<'PHP'
<?php

return [
    '1' => 'first',
    1 => 'second',
];
PHP
        );

        $result = (new DeclarativeArrayParser())->parseFile($path);

        $this->assertFalse($result['valid']);
        $this->assertSame('mapping_duplicate_key', $result['errors'][0]['code']);
    }

    public function testLongArrayConsoleMappingsProvideKnownAgentTools(): void
    {
        $path = $this->workspace . '/console.php';
        file_put_contents($path, <<<'PHP'
<?php
return array(
    'resources' => array(
        'sample' => array('0' => 'list', 'show'),
    ),
);
PHP
        );

        $tools = (new AddOnContractValidator())->knownToolsFromConsoleFile($path);

        $this->assertSame(['sample.list', 'sample.show'], $tools);
    }

    public function testDeclarativeConsoleToolsRemainAvailableAlongsideSafeRuntimeValues(): void
    {
        $path = $this->workspace . '/console-runtime-values.php';
        file_put_contents($path, <<<'PHP'
<?php
return [
    'resources' => [
        ' Sample ' => [' List ', 'SHOW'],
    ],
    'root' => __DIR__,
    'guard' => static fn (): bool => true,
];
PHP
        );

        $tools = (new AddOnContractValidator())->knownToolsFromConsoleFile($path);

        $this->assertSame(['sample.list', 'sample.show'], $tools);
    }

    public function testDeclarativeConsoleToolsRemainAvailableAfterAnUnkeyedSafeValue(): void
    {
        $path = $this->workspace . '/console-unkeyed-runtime-value.php';
        file_put_contents($path, <<<'PHP'
<?php
return [
    static fn (): bool => true,
    'resources' => [
        'sample' => ['list'],
    ],
];
PHP
        );

        $tools = (new AddOnContractValidator())->knownToolsFromConsoleFile($path);

        $this->assertSame(['sample.list'], $tools);
    }

    public function testKnownConsoleToolsDiscardInvalidIdentifiers(): void
    {
        $tools = (new AgentCapabilityMapValidator())->knownToolsFromConsole([
            'resources' => [
                ' Valid_Resource ' => [' List-Items ', 'invalid action'],
                'invalid resource' => ['list'],
            ],
        ]);

        $this->assertSame(['valid_resource.list-items'], $tools);
    }

    public function testRuntimeLoaderFailsClosedForMalformedMaps(): void
    {
        $valid = $this->workspace . '/valid.php';
        $invalid = $this->workspace . '/invalid.php';
        file_put_contents($valid, <<<'PHP'
<?php
return [
    'version' => 1,
    'owner' => 'application',
    'agents' => [
        'opus.central' => [
            'mode' => 'central',
            'description' => 'Central test agent.',
            'profile' => 'test.profile',
            'tools' => ['command.list'],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => ['states' => ['active']],
        ],
    ],
];
PHP
        );
        file_put_contents($invalid, "<?php\nreturn build_map();\n");

        $loader = new AgentCapabilityMapLoader();

        $this->assertSame(['command.list'], $loader->loadApplication($valid)->agent('opus.central')?->tools());
        $this->assertSame([], $loader->load($invalid, 'application')->agents());
    }

    public function testResolverLoadsOnlyRequestedActiveAddOnMapsFromTheCatalog(): void
    {
        $root = $this->workspace . DIRECTORY_SEPARATOR . 'addons';
        $mapping = $root . DIRECTORY_SEPARATOR . 'sample_tools' . DIRECTORY_SEPARATOR . 'mapping';
        mkdir($mapping, 0777, true);
        file_put_contents($mapping . DIRECTORY_SEPARATOR . 'agents.php', <<<'PHP'
<?php
return [
    'version' => 1,
    'owner' => 'sample_tools',
    'agents' => [
        'addon.sample_tools' => [
            'mode' => 'specialist',
            'description' => 'Sample specialist.',
            'profile' => 'sample.profile',
            'tools' => ['sample.list'],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => ['states' => ['active']],
        ],
    ],
];
PHP
        );
        file_put_contents($mapping . DIRECTORY_SEPARATOR . 'console.php', <<<'PHP'
<?php
return [
    'resources' => [
        'sample' => ['list'],
    ],
];
PHP
        );
        file_put_contents(
            dirname($mapping) . DIRECTORY_SEPARATOR . 'definition.json',
            '{"agent_mapping":"mapping/agents.php"}'
        );

        try {
            $application = $this->map(
                $this->mapping('application', 'opus.central', 'central', ['command.list']),
                ['command.list']
            );
            $resolver = new AgentCapabilityMapResolver(
                $application,
                catalog: new AgentCapabilityMapCatalog($root)
            );

            $inactive = $resolver->resolve('addon.sample_tools');
            $active = $resolver->resolve(
                'addon.sample_tools',
                ['sample_tools'],
                ['sample_tools' => 'active']
            );
            $this->assertSame([], $inactive->tools());
            $this->assertSame(['sample.list'], $active->tools());
            $this->assertSame(['sample_tools' => 1], $active->versions());
        } finally {
            unlink($mapping . DIRECTORY_SEPARATOR . 'agents.php');
            unlink($mapping . DIRECTORY_SEPARATOR . 'console.php');
            unlink(dirname($mapping) . DIRECTORY_SEPARATOR . 'definition.json');
            rmdir($mapping);
            rmdir(dirname($mapping));
            rmdir($root);
        }
    }

    public function testResolverDropsCatalogMapsAfterAddOnDeactivation(): void
    {
        $root = $this->workspace . DIRECTORY_SEPARATOR . 'addons';
        $mapping = $root . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'mapping';
        mkdir($mapping, 0777, true);
        file_put_contents($mapping . DIRECTORY_SEPARATOR . 'agents.php', <<<'PHP'
<?php
return [
    'version' => 1,
    'owner' => 'shared',
    'agents' => [
        'addon.shared' => [
            'mode' => 'central',
            'description' => 'Central-owned shared tools.',
            'profile' => 'shared.profile',
            'tools' => ['shared.status'],
            'imports' => [],
            'exports' => ['opus.central' => ['shared.status']],
            'permissions' => [],
            'lifecycle' => ['states' => ['active']],
        ],
    ],
];
PHP
        );
        file_put_contents($mapping . DIRECTORY_SEPARATOR . 'console.php', <<<'PHP'
<?php
return ['resources' => ['shared' => ['status']]];
PHP
        );
        file_put_contents(
            dirname($mapping) . DIRECTORY_SEPARATOR . 'definition.json',
            '{"agent_mapping":"mapping/agents.php"}'
        );

        try {
            $application = $this->map(
                $this->mapping(
                    'application',
                    'opus.central',
                    'central',
                    ['command.list'],
                    ['addon.shared' => ['shared.status']]
                ),
                ['command.list', 'shared.status']
            );
            $resolver = new AgentCapabilityMapResolver(
                $application,
                catalog: new AgentCapabilityMapCatalog($root)
            );

            $active = $resolver->resolve(
                'opus.central',
                ['shared'],
                ['shared' => 'active']
            );
            $inactive = $resolver->resolve('opus.central');

            $this->assertSame(['command.list', 'shared.status'], $active->tools());
            $this->assertSame([], $inactive->tools());
            $this->assertSame(
                'agent_relationship_invalid',
                Arr::make($inactive->decisions())->pop()['reason']
            );
        } finally {
            unlink($mapping . DIRECTORY_SEPARATOR . 'agents.php');
            unlink($mapping . DIRECTORY_SEPARATOR . 'console.php');
            unlink(dirname($mapping) . DIRECTORY_SEPARATOR . 'definition.json');
            rmdir($mapping);
            rmdir(dirname($mapping));
            rmdir($root);
        }
    }

    public function testRuntimeLoaderRejectsAddOnToolsMissingFromTheConsoleMapping(): void
    {
        $mapping = $this->workspace . DIRECTORY_SEPARATOR . 'mapping';
        mkdir($mapping, 0777, true);
        file_put_contents($mapping . DIRECTORY_SEPARATOR . 'console.php', <<<'PHP'
<?php
return ['resources' => ['sample' => ['list']]];
PHP
        );
        file_put_contents($mapping . DIRECTORY_SEPARATOR . 'agents.php', <<<'PHP'
<?php
return [
    'version' => 1,
    'owner' => 'sample',
    'agents' => [
        'addon.sample' => [
            'mode' => 'specialist',
            'description' => 'Sample specialist.',
            'profile' => 'sample.profile',
            'tools' => ['command.list'],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => ['states' => ['active']],
        ],
    ],
];
PHP
        );
        file_put_contents(
            $this->workspace . DIRECTORY_SEPARATOR . 'definition.json',
            '{"agent_mapping":"mapping/agents.php"}'
        );

        $map = (new AgentCapabilityMapLoader())->load(
            $mapping . DIRECTORY_SEPARATOR . 'agents.php',
            'sample'
        );

        $this->assertSame([], $map->agents());
        unlink($mapping . DIRECTORY_SEPARATOR . 'agents.php');
        unlink($mapping . DIRECTORY_SEPARATOR . 'console.php');
        unlink($this->workspace . DIRECTORY_SEPARATOR . 'definition.json');
        rmdir($mapping);
    }

    public function testRuntimeLoaderRequiresTheDeclaredAgentMapManifest(): void
    {
        $mapping = $this->workspace . DIRECTORY_SEPARATOR . 'mapping';
        mkdir($mapping, 0777, true);
        file_put_contents($mapping . DIRECTORY_SEPARATOR . 'console.php', "<?php\nreturn ['resources' => ['sample' => ['list']]];\n");
        file_put_contents($mapping . DIRECTORY_SEPARATOR . 'agents.php', <<<'PHP'
<?php
return [
    'version' => 1,
    'owner' => 'sample',
    'agents' => [
        'addon.sample' => [
            'mode' => 'specialist',
            'description' => 'Sample specialist.',
            'profile' => 'sample.profile',
            'tools' => ['sample.list'],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => ['states' => ['active']],
        ],
    ],
];
PHP
        );

        $loader = new AgentCapabilityMapLoader();
        $path = $mapping . DIRECTORY_SEPARATOR . 'agents.php';

        $this->assertSame([], $loader->load($path, 'sample')->agents());
        file_put_contents(
            $this->workspace . DIRECTORY_SEPARATOR . 'definition.json',
            '{"agent_mapping":"mapping/default.php"}'
        );
        $this->assertSame([], $loader->load($path, 'sample')->agents());
        file_put_contents(
            $this->workspace . DIRECTORY_SEPARATOR . 'definition.json',
            '{"agent_mapping":"mapping/agents.php"}'
        );
        $this->assertSame(['sample.list'], $loader->load($path, 'sample')->agent('addon.sample')?->tools());

        unlink($this->workspace . DIRECTORY_SEPARATOR . 'definition.json');
        unlink($mapping . DIRECTORY_SEPARATOR . 'agents.php');
        unlink($mapping . DIRECTORY_SEPARATOR . 'console.php');
        rmdir($mapping);
    }

    public function testCatalogPreservesAnAbsoluteUnixRoot(): void
    {
        $catalog = new AgentCapabilityMapCatalog('/srv/opus/addons/');
        $method = (new \ReflectionClass($catalog))->getMethod('path');

        $this->assertSame(
            '/srv/opus/addons/sample_tools/mapping/agents.php',
            $method->invoke($catalog, 'sample_tools')
        );
    }

    public function testCatalogCannotPromoteAnAddOnNamedApplicationToTheRootTrustBoundary(): void
    {
        $catalog = new AgentCapabilityMapCatalog($this->workspace);

        $this->assertSame([], $catalog->load(['application']));
    }

    public function testValidatorRejectsUnknownToolsInvalidModesAndCentralLeakage(): void
    {
        $mapping = $this->mapping('sample', 'addon.sample', 'specialist', ['sample.run']);
        $mapping['agents']['addon.sample']['mode'] = 'ambient';
        $mapping['agents']['addon.sample']['tools'][] = 'unknown.run';
        $mapping['agents']['addon.sample']['exports'] = [
            'opus.central' => ['sample.run'],
        ];

        $result = (new AgentCapabilityMapValidator())->validate($mapping, ['sample.run'], 'sample');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => $error['code'])
            ->toArray();

        $this->assertFalse($result['valid']);
        $this->assertContains('agent_mode', $codes);
        $this->assertContains('agent_tool_unknown', $codes);

        $mapping['agents']['addon.sample']['mode'] = 'specialist';
        $mapping['agents']['addon.sample']['tools'] = ['sample.run'];
        $result = (new AgentCapabilityMapValidator())->validate($mapping, ['sample.run'], 'sample');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => $error['code'])
            ->toArray();
        $this->assertContains('agent_central_leakage', $codes);
    }

    public function testValidatorRejectsUnknownSchemaKeysAndMismatchedOwnerBoundary(): void
    {
        $mapping = $this->mapping('sample', 'addon.other', 'specialist', ['sample.run']);
        $mapping['policy'] = 'ambient';
        $mapping['agents']['addon.other']['provider'] = 'default';
        $mapping['agents']['addon.other']['lifecycle']['fallback'] = 'active';

        $result = (new AgentCapabilityMapValidator())->validate($mapping, ['sample.run'], 'sample');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => $error['code'])
            ->toArray();

        $this->assertFalse($result['valid']);
        $this->assertContains('agent_map_schema', $codes);
        $this->assertContains('agent_descriptor_schema', $codes);
        $this->assertContains('agent_lifecycle_schema', $codes);
        $this->assertContains('agent_addon_identifier', $codes);
    }

    public function testValidatorRetainsCanonicalListValuesInTheLoadedMap(): void
    {
        $mapping = $this->mapping('sample', 'addon.sample', 'specialist', [' Sample.Run ']);
        $mapping['agents']['addon.sample']['permissions'] = [' Sample.Manage '];
        $mapping['agents']['addon.sample']['lifecycle']['states'] = [' Active '];

        $result = (new AgentCapabilityMapValidator())->validate($mapping, ['sample.run'], 'sample');
        $agent = $result['map']?->agent('addon.sample');

        $this->assertTrue($result['valid']);
        $this->assertSame(['sample.run'], $agent?->tools());
        $this->assertSame(['sample.manage'], $agent?->permissions());
        $this->assertSame(['active'], $agent?->lifecycleStates());
    }

    public function testValidatorRejectsAssociativeListFields(): void
    {
        $mapping = $this->mapping('sample', 'addon.sample', 'specialist', ['sample.run']);
        $mapping['agents']['addon.sample']['tools'] = ['alias' => 'sample.run'];

        $result = (new AgentCapabilityMapValidator())->validate($mapping, ['sample.run'], 'sample');
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => $error['code'])
            ->toArray();

        $this->assertFalse($result['valid']);
        $this->assertContains('agent_tools', $codes);
    }

    public function testResolverFailsClosedForInvalidCollectiveRelationships(): void
    {
        $root = $this->map($this->mapping(
            'application',
            'opus.central',
            'central',
            ['command.list'],
            ['addon.missing' => ['missing.run']]
        ), ['command.list']);

        $resolved = (new AgentCapabilityMapResolver($root))->resolve('opus.central');

        $this->assertSame([], $resolved->tools());
        $this->assertSame('agent_relationship_invalid', Arr::make($resolved->decisions())->pop()['reason']);
    }

    public function testCentralImportsRequireAnActiveCentralModeBoundaryAndReciprocalGrant(): void
    {
        $root = $this->map($this->mapping(
            'application',
            'opus.central',
            'central',
            ['command.list'],
            ['addon.shared' => ['shared.status']]
        ), ['command.list']);
        $addOn = $this->map($this->mapping(
            'shared',
            'addon.shared',
            'central',
            ['shared.status'],
            [],
            ['opus.central' => ['shared.status']]
        ), ['shared.status']);
        $resolver = new AgentCapabilityMapResolver($root, ['shared' => $addOn]);

        $inactive = $resolver->resolve('opus.central');
        $active = $resolver->resolve(
            'opus.central',
            ['shared'],
            ['shared' => 'active'],
            [],
            'tenant-a'
        );

        $this->assertSame(['command.list'], $inactive->tools());
        $this->assertSame(['command.list', 'shared.status'], $active->tools());
        $this->assertSame('tenant-a', $active->tenantId());
    }

    public function testCollectiveValidationRejectsMissingReciprocalAndSpecialistImports(): void
    {
        $root = new AgentCapabilityMap(1, 'application', [
            'opus.central' => $this->descriptor(
                'central',
                ['command.list'],
                ['addon.sample' => ['sample.run']]
            ),
        ]);
        $addOn = new AgentCapabilityMap(1, 'sample', [
            'addon.sample' => $this->descriptor('specialist', ['sample.run']),
        ]);

        $result = (new AgentCapabilityMapValidator())->validateRelationships($root, ['sample' => $addOn]);
        $codes = Arr::make($result['errors'])
            ->map(fn (array $error): string => $error['code'])
            ->toArray();

        $this->assertFalse($result['valid']);
        $this->assertContains('agent_central_leakage', $codes);
        $this->assertContains('agent_import_reciprocal', $codes);
    }

    public function testSpecialistToolsDoNotLeakToCentralAndPeerGrantsAreNonTransitive(): void
    {
        $root = new AgentCapabilityMap(1, 'application', [
            'opus.central' => $this->descriptor(
                'central',
                ['command.list'],
                []
            ),
        ]);
        $first = new AgentCapabilityMap(1, 'first', [
            'addon.first' => $this->descriptor(
                'specialist',
                ['first.run'],
                ['addon.second' => ['second.read']],
                ['opus.central' => ['first.run']]
            ),
        ]);
        $second = new AgentCapabilityMap(1, 'second', [
            'addon.second' => $this->descriptor(
                'specialist',
                ['second.read'],
                [],
                ['addon.first' => ['second.read']]
            ),
        ]);
        $resolver = new AgentCapabilityMapResolver($root, ['first' => $first, 'second' => $second]);
        $states = ['first' => 'active', 'second' => 'active'];

        $central = $resolver->resolve('opus.central', ['first', 'second'], $states);
        $specialist = $resolver->resolve('addon.first', ['first', 'second'], $states);

        $this->assertSame(['command.list'], $central->tools());
        $this->assertSame(['first.run', 'second.read'], $specialist->tools());
        $this->assertNotContains('command.list', $specialist->tools());
    }

    public function testRequiredPermissionsAndLifecycleStateFailClosed(): void
    {
        $root = $this->map($this->mapping('application', 'opus.central', 'central', ['command.list']), ['command.list']);
        $addOnMapping = $this->mapping('secure', 'addon.secure', 'specialist', ['secure.read']);
        $addOnMapping['agents']['addon.secure']['permissions'] = ['secure.read'];
        $addOn = $this->map($addOnMapping, ['secure.read']);
        $resolver = new AgentCapabilityMapResolver($root, ['secure' => $addOn]);

        $missingPermission = $resolver->resolve('addon.secure', ['secure'], ['secure' => 'active']);
        $suspended = $resolver->resolve(
            'addon.secure',
            ['secure'],
            ['secure' => 'suspended'],
            ['secure.read']
        );
        $allowed = $resolver->resolve(
            'addon.secure',
            ['secure'],
            ['secure' => 'active'],
            ['secure.read']
        );

        $this->assertSame([], $missingPermission->tools());
        $this->assertSame([], $suspended->tools());
        $this->assertSame(['secure.read'], $allowed->tools());
    }

    public function testEveryRequiredPermissionMustBePresentRegardlessOfListOffset(): void
    {
        $root = $this->map($this->mapping('application', 'opus.central', 'central', ['command.list']), ['command.list']);
        $mapping = $this->mapping('secure', 'addon.secure', 'specialist', ['secure.read']);
        $mapping['agents']['addon.secure']['permissions'] = ['secure.first', 'secure.second'];
        $addOn = $this->map($mapping, ['secure.read']);
        $resolver = new AgentCapabilityMapResolver($root, ['secure' => $addOn]);

        $result = $resolver->resolve(
            'addon.secure',
            ['secure'],
            ['secure' => 'active'],
            ['secure.first']
        );

        $this->assertSame([], $result->tools());
    }

    public function testGeneratedAndDisabledModesRemainBounded(): void
    {
        $root = $this->map($this->mapping('application', 'opus.central', 'central', ['command.list']), ['command.list']);
        $generated = $this->map(
            $this->mapping('generated', 'addon.generated', 'generated', ['generated.run']),
            ['generated.run']
        );
        $disabledMapping = $this->mapping('disabled', 'addon.disabled', 'disabled', []);
        $disabledMapping['agents']['addon.disabled']['profile'] = '';
        $disabled = $this->map($disabledMapping, []);
        $resolver = new AgentCapabilityMapResolver($root, [
            'generated' => $generated,
            'disabled' => $disabled,
        ]);
        $states = ['generated' => 'active', 'disabled' => 'active'];

        $generatedTools = $resolver->resolve('addon.generated', ['generated', 'disabled'], $states);
        $disabledTools = $resolver->resolve('addon.disabled', ['generated', 'disabled'], $states);

        $this->assertSame(['generated.run'], $generatedTools->tools());
        $this->assertSame([], $disabledTools->tools());
    }

    public function testLifecycleAndTenantContextAreResolvedForEveryRequest(): void
    {
        $root = $this->map($this->mapping('application', 'opus.central', 'central', ['command.list']), ['command.list']);
        $addOn = $this->map(
            $this->mapping('sample', 'addon.sample', 'specialist', ['sample.run']),
            ['sample.run']
        );
        $resolver = new AgentCapabilityMapResolver($root, ['sample' => $addOn]);

        $active = $resolver->resolve('addon.sample', ['sample'], ['sample' => 'active'], [], 'tenant-a');
        $suspended = $resolver->resolve('addon.sample', ['sample'], ['sample' => 'suspended'], [], 'tenant-b');

        $this->assertSame(['sample.run'], $active->tools());
        $this->assertSame('tenant-a', $active->tenantId());
        $this->assertSame([], $suspended->tools());
        $this->assertSame('tenant-b', $suspended->tenantId());
    }

    public function testImportedToolsRequireProviderPermissions(): void
    {
        $root = $this->map($this->mapping(
            'application',
            'opus.central',
            'central',
            ['command.list'],
            ['addon.shared' => ['shared.status']]
        ), ['command.list']);
        $addOnMapping = $this->mapping(
            'shared',
            'addon.shared',
            'central',
            ['shared.status'],
            [],
            ['opus.central' => ['shared.status']]
        );
        $addOnMapping['agents']['addon.shared']['permissions'] = ['shared.read'];
        $addOn = $this->map($addOnMapping, ['shared.status']);
        $resolver = new AgentCapabilityMapResolver($root, ['shared' => $addOn]);
        $active = ['shared'];
        $states = ['shared' => 'active'];

        $denied = $resolver->resolve('opus.central', $active, $states);
        $allowed = $resolver->resolve('opus.central', $active, $states, ['shared.read']);

        $this->assertSame(['command.list'], $denied->tools());
        $this->assertSame(['command.list', 'shared.status'], $allowed->tools());
    }

    public function testScopedProcessorUsesTheSameMapForDiscoveryAndExecution(): void
    {
        if (!interface_exists(ICommandProcessor::class)
            || !class_exists(CommandRequest::class)
            || !class_exists(CommandResult::class)
        ) {
            $this->markTestSkipped('The installed Wise checkout predates the typed command processor contract.');
        }

        $processor = new class implements ICommandProcessor {
            public int $executions = 0;
            public bool $executedParsedCommand = false;

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
                $command = new Command();
                $command->resources = ['command'];
                $command->verb = 'list';

                if (!$request->shouldExecute()) {
                    return CommandResult::parsed($command);
                }

                $this->executions++;
                $this->executedParsedCommand = $request->input() instanceof Command;

                return CommandResult::completed(['ok' => true], $command, ['provider' => 'test']);
            }
        };
        $root = $this->map($this->mapping('application', 'opus.central', 'central', ['command.list']), ['command.list']);
        $scoped = new AgentScopedCommandProcessor(
            $processor,
            new AgentCapabilityMapResolver($root),
            $this->continuationStore()
        );
        $context = [
            'actor' => ['id' => 'operator'],
            'agent_id' => 'opus.central',
            'tenant_id' => 'tenant-a',
            'correlation_id' => 'correlation-a',
            'active_addons' => [],
            'addon_states' => [],
            'capabilities' => [],
        ];

        $discovery = $scoped->discover($context);
        $result = $scoped->process(new CommandRequest('list commands', context: $context));

        $this->assertSame(['command.list'], $discovery['commands']);
        $this->assertTrue($result->successful());
        $this->assertSame(1, $processor->executions);
        $this->assertTrue($processor->executedParsedCommand);
        $this->assertSame('command.list', $result->metadata()['agent_tool']);
        $this->assertSame('test', $result->metadata()['provider']);
        $this->assertSame('correlation-a', $result->metadata()['correlation_id']);
        $this->assertSame(CommandResult::COMPLETED, $result->metadata()['agent_result_status']);
    }

    public function testScopedProcessorRejectsUnauthorizedToolsBeforeExecution(): void
    {
        if (!interface_exists(ICommandProcessor::class)
            || !class_exists(CommandRequest::class)
            || !class_exists(CommandResult::class)
        ) {
            $this->markTestSkipped('The installed Wise checkout predates the typed command processor contract.');
        }

        $processor = new class implements ICommandProcessor {
            public int $executions = 0;

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
                $command = new Command();
                $command->resources = ['file'];
                $command->verb = 'delete';

                if (!$request->shouldExecute()) {
                    return CommandResult::parsed($command);
                }

                $this->executions++;

                return CommandResult::completed(null, $command);
            }
        };
        $root = $this->map($this->mapping('application', 'opus.central', 'central', ['command.list']), ['command.list']);
        $scoped = new AgentScopedCommandProcessor(
            $processor,
            new AgentCapabilityMapResolver($root),
            $this->continuationStore()
        );

        $result = $scoped->process(new CommandRequest('delete file', context: ['agent_id' => 'opus.central']));

        $this->assertSame(CommandResult::INVALID, $result->status());
        $this->assertSame(0, $processor->executions);
        $this->assertSame('tool_not_granted', $result->metadata()['agent_reason']);
        $this->assertSame(CommandResult::INVALID, $result->metadata()['agent_result_status']);
    }

    public function testScopedProcessorDefaultsToCentralAndBindsContinuationsToActorAndTenant(): void
    {
        if (!interface_exists(ICommandProcessor::class)
            || !class_exists(CommandRequest::class)
            || !class_exists(CommandResult::class)
        ) {
            $this->markTestSkipped('The installed Wise checkout predates the typed command processor contract.');
        }

        $processor = new class implements ICommandProcessor {
            public int $resumes = 0;

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
                $command = new Command();
                $command->resources = ['command'];
                $command->verb = 'list';

                if ($request->isContinuation()) {
                    $this->resumes++;
                    return CommandResult::completed(['confirmed' => true], $command);
                }
                if (!$request->shouldExecute()) {
                    return CommandResult::parsed($command);
                }

                return CommandResult::pending('Confirm command.', $command, 'continuation-a');
            }
        };
        $root = $this->map(
            $this->mapping('application', 'opus.central', 'central', ['command.list']),
            ['command.list']
        );
        $continuations = $this->continuationStore();
        $scoped = new AgentScopedCommandProcessor(
            $processor,
            new AgentCapabilityMapResolver($root),
            $continuations
        );
        $scope = [
            'actor' => 'operator-a',
            'tenant_id' => 'tenant-a',
        ];
        $refreshedScope = [
            'actor' => ['id' => 'operator-a'],
            'tenant_id' => 'tenant-a',
        ];

        $unscoped = $scoped->process(new CommandRequest('list commands'));
        $pending = $scoped->process(new CommandRequest('list commands', context: $scope));
        $wrongTenant = $scoped->process(CommandRequest::resume(
            'continuation-a',
            true,
            ['actor' => ['id' => 'operator-a'], 'tenant_id' => 'tenant-b']
        ));
        $wrongActor = $scoped->process(CommandRequest::resume(
            'continuation-a',
            true,
            ['actor' => ['id' => 'operator-b'], 'tenant_id' => 'tenant-a']
        ));
        $nextRequest = new AgentScopedCommandProcessor(
            $processor,
            new AgentCapabilityMapResolver($root),
            $continuations
        );
        $completed = $nextRequest->process(CommandRequest::resume('continuation-a', true, $refreshedScope));

        $this->assertSame(CommandResult::INVALID, $unscoped->status());
        $this->assertSame('actor_scope_required', $unscoped->metadata()['agent_reason']);
        $this->assertTrue($pending->confirmationRequired());
        $this->assertSame(CommandResult::INVALID, $wrongTenant->status());
        $this->assertSame(CommandResult::INVALID, $wrongActor->status());
        $this->assertSame(CommandResult::COMPLETED, $completed->status());
        $this->assertSame(1, $processor->resumes);
        $this->assertSame('opus.central', $completed->metadata()['agent_id']);
    }

    public function testRejectedContinuationIsConsumedAfterCapabilityRevocation(): void
    {
        if (!interface_exists(ICommandProcessor::class)
            || !class_exists(CommandRequest::class)
            || !class_exists(CommandResult::class)
        ) {
            $this->markTestSkipped('The installed Wise checkout predates the typed command processor contract.');
        }

        $processor = new class implements ICommandProcessor {
            public ?bool $approved = null;

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
                $this->approved = $request->approved();

                return CommandResult::completed(['rejected' => true]);
            }
        };
        $root = $this->map(
            $this->mapping('application', 'opus.central', 'central', []),
            []
        );
        $continuations = $this->continuationStore();
        $continuations->put('continuation-revoked', [
            'agent_id' => 'opus.central',
            'tenant_id' => 'tenant-a',
            'actor' => ['id' => 'operator-a'],
            'tool' => 'command.list',
        ]);
        $scoped = new AgentScopedCommandProcessor(
            $processor,
            new AgentCapabilityMapResolver($root),
            $continuations
        );

        $result = $scoped->process(CommandRequest::resume(
            'continuation-revoked',
            false,
            [
                'agent_id' => 'opus.central',
                'tenant_id' => 'tenant-a',
                'actor' => ['id' => 'operator-a'],
            ]
        ));

        $this->assertSame(CommandResult::COMPLETED, $result->status());
        $this->assertFalse($processor->approved);
        $this->assertNull($continuations->get('continuation-revoked'));
        $this->assertSame('deny', $result->metadata()['agent_decision']);
        $this->assertSame('continuation_rejected', $result->metadata()['agent_reason']);
    }

    public function testBotMiddlewareResumesWiseContinuationWithTheAuthenticatedContext(): void
    {
        if (!interface_exists(ICommandProcessor::class)
            || !class_exists(CommandRequest::class)
            || !class_exists(CommandResult::class)
            || !interface_exists(\BotMan\BotMan\Interfaces\Middleware\Received::class)
        ) {
            $this->markTestSkipped('The optional chat transport or typed Wise processor is unavailable.');
        }

        $processor = new class implements ICommandProcessor {
            public ?CommandRequest $request = null;

            public function process(CommandRequest|Command|array|string $request): CommandResult
            {
                $this->request = $request instanceof CommandRequest ? $request : new CommandRequest($request);

                return CommandResult::completed(['resumed' => true]);
            }
        };
        $query = new class implements IActivatedAddOnsQuery {
            public array $records = [['name' => 'sample', 'is_active' => 1]];

            public function fetch(): array
            {
                return $this->records;
            }
        };
        $middleware = new class(
            $this->createMock(CommandManager::class),
            new WiseCommandHost($processor, new CommandResultPresenter()),
            new AgentCommandContextProvider($query)
        )
            extends ProcessesCommandMiddleware {
                public function resume(string $token, bool $approved, array $context): CommandPresentation
                {
                    return $this->resumeCommand($token, $approved, $context);
                }

                public function description(CommandPresentation $presentation): string
                {
                    return $this->confirmationDescription($presentation);
                }
            };
        $context = [
            'actor' => ['id' => 'operator-a'],
            'tenant_id' => 'tenant-a',
            'active_addons' => ['sample'],
            'addon_states' => ['sample' => 'active'],
            'capabilities' => ['stale.capability'],
        ];
        $query->records = [];

        $pendingCommand = new Command();
        $pendingCommand->verb = 'list';
        $pendingCommand->resources = ['command'];
        $pending = CommandResult::pending('Confirm command.', $pendingCommand, 'continuation-b');
        $pendingPresentation = (new CommandResultPresenter())->present($pending);

        $result = $middleware->resume('continuation-a', true, $context);

        $this->assertSame(CommandResult::COMPLETED, $result->status());
        $this->assertTrue($processor->request?->isContinuation());
        $this->assertSame('continuation-a', $processor->request?->continuationToken());
        $this->assertTrue($processor->request?->approved());
        $this->assertSame('operator-a', $processor->request?->context()['actor']['id']);
        $this->assertSame('tenant-a', $processor->request?->context()['tenant_id']);
        $this->assertSame([], $processor->request?->context()['active_addons']);
        $this->assertSame([], $processor->request?->context()['addon_states']);
        $this->assertSame([], $processor->request?->context()['capabilities']);
        $this->assertSame('list command', $middleware->description($pendingPresentation));
    }

    public function testProductionContextIncludesTrustedActivatedAddOnState(): void
    {
        $query = new class implements IActivatedAddOnsQuery {
            public function fetch(): array
            {
                return [
                    ['name' => 'sample', 'is_active' => 1],
                    ['name' => 'reports', 'is_active' => 1],
                ];
            }
        };

        $context = (new AgentCommandContextProvider($query))->forActor('operator-a');

        $this->assertSame('opus.central', $context['agent_id']);
        $this->assertSame(['sample', 'reports'], $context['active_addons']);
        $this->assertSame(['sample' => 'active', 'reports' => 'active'], $context['addon_states']);
        $this->assertSame([], $context['capabilities']);
        $this->assertSame('operator-a', $context['actor']['id']);
    }

    public function testContinuationRefreshRunsInsideAndCannotReplaceTheOriginatingTenant(): void
    {
        $reflection = new \ReflectionClass(DevElation::class);
        $active = $reflection->getProperty('_isActive');
        $filters = $reflection->getProperty('_filters');
        $originalActive = $active->getValue();
        $originalFilters = $filters->getValue();
        $observedTenant = null;

        try {
            DevElation::up();
            DevElation::filter(
                'opus.agent.command_context',
                function (array $context) use (&$observedTenant): array {
                    $observedTenant = $context['tenant_id'] ?? null;
                    $context['tenant_id'] = 'tenant-b';
                    $context['capabilities'] = ['tenant-b.manage'];

                    return $context;
                }
            );

            $context = (new AgentCommandContextProvider())->forContinuation([
                'actor' => ['id' => 'operator-a'],
                'tenant_id' => 'tenant-a',
                'capabilities' => ['stale.manage'],
            ]);

            $this->assertSame('tenant-a', $observedTenant);
            $this->assertSame('tenant-a', $context['tenant_id']);
            $this->assertSame([], $context['capabilities']);
        } finally {
            $active->setValue(null, $originalActive);
            $filters->setValue(null, $originalFilters);
        }
    }

    public function testContinuationRefreshPreservesAStringActorIdentity(): void
    {
        $context = (new AgentCommandContextProvider())->forContinuation([
            'actor' => 'operator-a',
            'tenant_id' => 'tenant-a',
        ]);

        $this->assertSame('operator-a', $context['actor']['id']);
        $this->assertSame('tenant-a', $context['tenant_id']);
    }

    private function continuationStore(): IAgentContinuationScopeStore
    {
        return new class implements IAgentContinuationScopeStore {
            private array $scopes = [];

            public function get(string $token): ?array
            {
                return $this->scopes[$token] ?? null;
            }

            public function put(string $token, array $scope): void
            {
                $this->scopes[$token] = $scope;
            }

            public function delete(string $token): void
            {
                unset($this->scopes[$token]);
            }
        };
    }

    private function map(array $mapping, array $knownTools): AgentCapabilityMap
    {
        $result = (new AgentCapabilityMapValidator())->validate($mapping, $knownTools, $mapping['owner']);
        $this->assertTrue($result['valid'], Arr::make($result['errors'])->toJson());
        $this->assertInstanceOf(AgentCapabilityMap::class, $result['map']);

        return $result['map'];
    }

    private function mapping(
        string $owner,
        string $id,
        string $mode,
        array $tools,
        array $imports = [],
        array $exports = []
    ): array {
        return [
            'version' => 1,
            'owner' => $owner,
            'agents' => [
                $id => $this->descriptor($mode, $tools, $imports, $exports),
            ],
        ];
    }

    private function descriptor(
        string $mode,
        array $tools,
        array $imports = [],
        array $exports = []
    ): array {
        return [
            'mode' => $mode,
            'description' => 'Test agent boundary.',
            'profile' => 'test.profile',
            'tools' => $tools,
            'imports' => $imports,
            'exports' => $exports,
            'permissions' => [],
            'lifecycle' => ['states' => ['active']],
        ];
    }
}
