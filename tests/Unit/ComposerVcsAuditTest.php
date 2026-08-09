<?php

declare(strict_types=1);

namespace Tests\Unit;

use Opus\Tools\ComposerVcsAudit;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tools/ComposerVcsAudit.php';

class ComposerVcsAuditTest extends TestCase
{
    public function testProjectRegistryCoversRecursiveSourcePackages(): void
    {
        $root = dirname(__DIR__, 2);
        $result = (new ComposerVcsAudit())->auditFiles(
            $root . '/composer.json',
            $root . '/composer.lock',
            $root . '/templates/composer/opus-root.json'
        );

        $this->assertSame([], $result['errors']);
        $this->assertContains('bluefission/chronicler', $result['packages']);
        $this->assertContains('bluefission/jenerator', $result['packages']);
        $this->assertContains('bluefission/develation', $result['packages']);
        $this->assertContains('bluefission/simpleclients', $result['packages']);
    }

    public function testConsumerTemplateRepeatsRootOnlyReleaseAliases(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'require' => [
                'bluefission/automata' => 'v1.0.0-alpha.2 as dev-master',
                'bluefission/chronicler' => 'v0.1.2-alpha as dev-main',
                'bluefission/develation' => 'v1.3.41 as dev-master',
            ],
        ];
        $template = [
            'config' => ['use-github-api' => false],
            'require' => [
                'bluefission/automata' => 'v1.0.0-alpha.2 as dev-master',
                'bluefission/chronicler' => 'v0.1.2-alpha as dev-main',
            ],
        ];

        $result = (new ComposerVcsAudit())->audit($composer, [], $template);

        $this->assertContains(
            'Consumer template must repeat the root-only bluefission/develation alias v1.3.41 as dev-master.',
            $result['errors']
        );
        $this->assertNotContains(
            'Consumer template must repeat the root-only bluefission/automata alias v1.0.0-alpha.2 as dev-master.',
            $result['errors']
        );
        $this->assertNotContains(
            'Consumer template must repeat the root-only bluefission/chronicler alias v0.1.2-alpha as dev-main.',
            $result['errors']
        );
    }

    public function testConsumerTemplateRejectsAliasRemovedFromRoot(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.3'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.3',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = [
            'config' => ['use-github-api' => false],
            'require' => ['bluefission/automata' => 'v1.0.0-alpha.3 as dev-master'],
        ];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Consumer template must remove the stale bluefission/automata alias v1.0.0-alpha.3 as dev-master.',
            $result['errors']
        );
    }

    public function testAuditRejectsUnexpectedRepositoryInCanonicalOrganization(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [[
                'type' => 'vcs',
                'url' => 'https://github.com/BlueFissionTech/renamed-fork',
            ]],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.3'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.3',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Root composer.json has a repository for unexpected package bluefission/renamed-fork.',
            $result['errors']
        );
    }

    public function testAuditAllowsPackagistDevelopmentLineForUnreleasedPackage(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'require' => ['bluefission/simpleclients' => 'dev-master'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/simpleclients',
                'version' => 'dev-master',
                'source' => [
                    'url' => 'https://github.com/BlueFissionTech/simpleclients.git',
                    'reference' => 'source-reference',
                ],
                'dist' => [
                    'url' => 'https://api.github.com/repos/BlueFissionTech/simpleclients/zipball/source-reference',
                    'reference' => 'source-reference',
                ],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertSame([], $result['errors']);
    }

    public function testAuditReportsMissingRootRepositoryButAllowsPackagistPackages(): void
    {
        $repository = [
            'type' => 'vcs',
            'url' => 'https://github.com/BlueFissionTech/wise',
        ];
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [],
            'require' => [
                'bluefission/automata' => 'v1.0.0-alpha.2 as dev-master',
                'bluefission/chronicler' => 'v0.1.2-alpha as dev-main',
                'bluefission/develation' => 'v1.3.41 as dev-master',
                'bluefission/wise' => 'dev-main',
            ],
        ];
        $lock = [
            'packages' => [
                [
                    'name' => 'bluefission/automata',
                    'version' => 'v1.0.0-alpha.2',
                    'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                    'notification-url' => 'https://packagist.org/downloads/',
                ],
                [
                    'name' => 'bluefission/chronicler',
                    'version' => 'v0.1.2-alpha',
                    'source' => ['url' => 'https://github.com/BlueFissionTech/chronicler.git'],
                    'notification-url' => 'https://packagist.org/downloads/',
                ],
                [
                    'name' => 'bluefission/develation',
                    'version' => 'v1.3.41',
                    'source' => ['url' => 'https://github.com/BlueFissionTech/develation.git'],
                    'notification-url' => 'https://packagist.org/downloads/',
                ],
                [
                    'name' => 'bluefission/wise',
                    'version' => 'dev-main',
                    'source' => ['url' => 'https://github.com/BlueFissionTech/wise.git'],
                ],
            ],
        ];
        $template = [
            'config' => ['use-github-api' => false],
            'repositories' => ['bluefission/wise' => $repository],
        ];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Root composer.json is missing the canonical bluefission/wise repository.',
            $result['errors']
        );
        foreach (['automata', 'chronicler', 'develation'] as $package) {
            $this->assertNotContains(
                "Canonical consumer template is missing bluefission/{$package}.",
                $result['errors']
            );
        }
    }

    public function testAuditRejectsVcsOverridesAndDevelopmentLocksForPackagistPackages(): void
    {
        $automataRepository = [
            'type' => 'vcs',
            'url' => 'https://github.com/BlueFissionTech/automata',
        ];
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => ['bluefission/automata' => $automataRepository],
            'require' => ['bluefission/automata' => 'dev-master'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'dev-master',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
            ]],
        ];
        $template = [
            'config' => ['use-github-api' => false],
            'repositories' => ['bluefission/automata' => $automataRepository],
        ];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'bluefission/automata must resolve through Packagist, not a root VCS override.',
            $result['errors']
        );
        $this->assertContains(
            'bluefission/automata must not be present in the consumer VCS template.',
            $result['errors']
        );
        $this->assertContains(
            'Locked bluefission/automata must use a tagged Packagist release.',
            $result['errors']
        );
        $this->assertContains(
            'Locked bluefission/automata does not carry Packagist distribution metadata.',
            $result['errors']
        );
    }

    public function testAuditRejectsNumericDevelopmentLocksForPackagistPackages(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => '1.x-dev',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Locked bluefission/automata must use a tagged Packagist release.',
            $result['errors']
        );
    }

    public function testAuditRejectsForkOverrideForPackagistPackage(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [[
                'type' => 'vcs',
                'url' => 'https://github.com/example/automata',
            ]],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.2',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'bluefission/automata must resolve through Packagist, not a root VCS override.',
            $result['errors']
        );
    }

    public function testAuditRejectsInlineOverrideForPackagistPackage(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [[
                'type' => 'package',
                'package' => [
                    'name' => 'bluefission/automata',
                    'version' => 'v1.0.0-alpha.2',
                    'dist' => ['url' => 'https://example.com/automata.zip'],
                ],
            ]],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.2',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'bluefission/automata must resolve through Packagist, not a root VCS override.',
            $result['errors']
        );
    }

    public function testAuditRejectsInlinePackageListOverride(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [[
                'type' => 'package',
                'package' => [
                    [
                        'name' => 'example/utility',
                        'version' => '1.0.0',
                    ],
                    [
                        'name' => 'bluefission/automata',
                        'version' => 'v1.0.0-alpha.2',
                        'dist' => ['url' => 'https://example.com/automata.zip'],
                    ],
                ],
            ]],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.2',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'bluefission/automata must resolve through Packagist, not a root VCS override.',
            $result['errors']
        );
    }

    public function testAuditRejectsInlineOverrideBeforeCanonicalVcsRepository(): void
    {
        $repository = [
            'type' => 'vcs',
            'url' => 'https://github.com/BlueFissionTech/wise',
        ];
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'bluefission/wise',
                        'version' => 'dev-main',
                        'dist' => ['url' => 'https://example.com/wise.zip'],
                    ],
                ],
                $repository,
            ],
            'require' => ['bluefission/wise' => 'dev-main'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/wise',
                'version' => 'dev-main',
                'source' => ['url' => 'https://github.com/BlueFissionTech/wise.git'],
            ]],
        ];
        $template = [
            'config' => ['use-github-api' => false],
            'repositories' => ['bluefission/wise' => $repository],
        ];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Root composer.json must not define bluefission/wise through an inline package repository.',
            $result['errors']
        );
    }

    public function testAuditRejectsAlteredPackagistDistribution(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.3'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.3',
                'source' => [
                    'url' => 'https://github.com/BlueFissionTech/automata.git',
                    'reference' => 'source-reference',
                ],
                'dist' => [
                    'url' => 'https://example.com/automata.zip',
                    'reference' => 'different-reference',
                ],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Locked bluefission/automata does not use its canonical Packagist distribution archive.',
            $result['errors']
        );
        $this->assertContains(
            'Locked bluefission/automata source and distribution references do not match.',
            $result['errors']
        );
    }

    public function testAuditRejectsUnverifiableNumericVcsRepository(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [[
                'type' => 'vcs',
                'url' => 'https://github.com/example/renamed-fork',
            ]],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.2',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Root composer.json has an unverifiable vcs repository at index 0.',
            $result['errors']
        );
    }

    public function testAuditRejectsUnverifiableKeyedVcsRepository(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [
                'renamed' => [
                    'type' => 'vcs',
                    'url' => 'https://github.com/example/renamed-fork',
                ],
            ],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.2',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Root composer.json has an unverifiable vcs repository named renamed.',
            $result['errors']
        );
    }

    public function testAuditDoesNotTrustPackageLookingRepositoryKey(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [
                'bluefission/decoy' => [
                    'type' => 'vcs',
                    'url' => 'https://github.com/example/renamed-fork',
                ],
            ],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.2',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Root composer.json has an unverifiable vcs repository named bluefission/decoy.',
            $result['errors']
        );
    }

    public function testAuditDoesNotTrustInlineIdentityOnVcsRepository(): void
    {
        $composer = [
            'config' => ['use-github-api' => false],
            'repositories' => [[
                'type' => 'vcs',
                'url' => 'https://github.com/example/renamed-fork',
                'package' => [
                    'name' => 'example/decoy',
                    'version' => '1.0.0',
                ],
            ]],
            'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.2',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Root composer.json has an unverifiable vcs repository at index 0.',
            $result['errors']
        );
    }

    public function testAuditRejectsDisabledPackagist(): void
    {
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'version' => 'v1.0.0-alpha.2',
                'source' => ['url' => 'https://github.com/BlueFissionTech/automata.git'],
                'notification-url' => 'https://packagist.org/downloads/',
            ]],
        ];
        $template = ['config' => ['use-github-api' => false]];

        foreach (['packagist', 'packagist.org'] as $repositoryName) {
            $composer = [
                'config' => ['use-github-api' => false],
                'repositories' => [$repositoryName => false],
                'require' => ['bluefission/automata' => '^1.0.0-alpha.2'],
            ];
            $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

            $this->assertContains('Root composer.json must not disable Packagist.', $result['errors']);
        }
    }

    public function testPackageDoesNotNeedToDeclareItselfAsARootRepository(): void
    {
        $repository = [
            'type' => 'vcs',
            'url' => 'https://github.com/BlueFissionTech/opus',
        ];
        $composer = [
            'name' => 'bluefission/opus',
            'config' => ['use-github-api' => false],
            'repositories' => [],
            'require' => [],
        ];
        $template = [
            'config' => ['use-github-api' => false],
            'repositories' => ['bluefission/opus' => $repository],
        ];

        $result = (new ComposerVcsAudit())->audit($composer, [], $template);

        $this->assertSame([], $result['errors']);
    }

    public function testAuditRejectsNonGithubLockedSource(): void
    {
        $repository = [
            'type' => 'vcs',
            'url' => 'https://github.com/BlueFissionTech/wise',
        ];
        $composer = [
            'name' => 'bluefission/opus',
            'repositories' => ['bluefission/wise' => $repository],
            'require' => ['bluefission/wise' => 'dev-main'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/wise',
                'source' => ['url' => 'https://example.com/wise.git'],
            ]],
        ];
        $template = [
            'repositories' => ['bluefission/wise' => $repository],
        ];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Locked bluefission/wise does not resolve from its canonical GitHub source.',
            $result['errors']
        );
    }
}
