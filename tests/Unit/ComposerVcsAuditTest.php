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
    }

    public function testAuditReportsMissingRootRepositoryButAllowsPackagistDevelation(): void
    {
        $repository = [
            'type' => 'vcs',
            'url' => 'https://github.com/BlueFissionTech/automata',
        ];
        $composer = [
            'repositories' => [],
            'require' => ['bluefission/automata' => 'dev-master'],
        ];
        $lock = [
            'packages' => [[
                'name' => 'bluefission/automata',
                'require' => ['bluefission/develation' => '^1.3'],
                'source' => [
                    'url' => 'https://github.com/BlueFissionTech/automata.git',
                ],
            ]],
        ];
        $template = [
            'repositories' => ['bluefission/automata' => $repository],
        ];

        $result = (new ComposerVcsAudit())->audit($composer, $lock, $template);

        $this->assertContains(
            'Root composer.json is missing the canonical bluefission/automata repository.',
            $result['errors']
        );
        $this->assertNotContains(
            'Canonical consumer template is missing bluefission/develation.',
            $result['errors']
        );
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
