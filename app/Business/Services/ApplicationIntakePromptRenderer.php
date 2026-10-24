<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\PackageTheme;
use App\Domain\Onboarding\ApplicationIntakeSession;
use BlueFission\Arr;
use BlueFission\Services\Service;
use BlueFission\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Read-only questions; retrieving or changing intake still requires host authorization. */
final class ApplicationIntakePromptRenderer extends Service
{
    private const TEMPLATES = ['1.0' => 'intake-1.0.vibe'];
    private VibeThemeRenderer $renderer;

    public function __construct(?callable $readerFactory = null)
    {
        parent::__construct();
        $this->renderer = new VibeThemeRenderer(
            static fn (string $name): PackageTheme => new PackageTheme(
                $name, dirname(__DIR__, 3) . '/resource/prompts/intake'
            ),
            $readerFactory
        );
    }

    public function render(?ApplicationIntakeSession $session = null): string
    {
        $version = $session?->promptVersion() ?? ApplicationIntakeSession::VERSION;
        if (!Arr::hasKey(self::TEMPLATES, $version)) {
            throw new InvalidArgumentException('intake_prompt_version_unsupported');
        }

        // Native Vibe lifecycle observers see variables and output. Never give
        // the interpreter private values, defaults, identifiers or actor context.
        $unanswered = $session?->unanswered() ?? ['project_name'];
        $variables = [
            'status' => $session?->status() ?? 'new',
            'has_questions' => Arr::isNotEmpty($unanswered),
        ];
        foreach (ApplicationIntakeSession::fields() as $field) {
            $variables['ask_' . $field] = Arr::has($unanswered, $field, true);
        }

        try {
            $output = $this->renderer->render('intake.prompt', self::TEMPLATES[$version], $variables);
            if (Str::isEmpty(Str::trim($output))) {
                throw new RuntimeException('empty_intake_prompt');
            }
            return $output;
        } catch (Throwable) {
            throw new RuntimeException('intake_prompt_unavailable');
        }
    }
}
