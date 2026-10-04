<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\DevElation;
use BlueFission\Str;
use BlueFission\Utils\File;
use InvalidArgumentException;

final class HostFrontendThemeResolver
{
    public function resolve(RuntimePathResolver $paths, array $config): string
    {
        $selected = $config['selected'] ?? '';
        $fallbacks = $config['fallbacks'] ?? [];
        $templates = $config['required_templates'] ?? [];
        if (!Arr::is($fallbacks) || !Arr::is($templates) || $templates === []) {
            throw new InvalidArgumentException('Host frontend theme configuration is invalid.');
        }

        $directory = $this->markupDirectory();
        $file = new File();
        foreach (Arr::make([$selected, ...$fallbacks])->unique()->toArray() as $name) {
            if ($name === '') {
                continue;
            }
            if (!Str::is($name) || preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
                throw new InvalidArgumentException('Host frontend theme name is invalid.');
            }

            $relative = $directory . '/' . $name;
            $root = $paths->themeRoot('default', $relative);
            $complete = true;
            foreach ($templates as $template) {
                if (!Str::is($template) || preg_match('/^[a-z][a-z0-9_-]*\.vibe$/', $template) !== 1) {
                    throw new InvalidArgumentException('Host frontend template name is invalid.');
                }
                if (!$file->exists($paths->hostResourcePath($relative . '/' . $template))) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                return $root;
            }
        }

        return $paths->themeRoot('default');
    }

    private function markupDirectory(): string
    {
        $default = defined('OPUS_HOST_MARKUP_DIRECTORY')
            ? constant('OPUS_HOST_MARKUP_DIRECTORY')
            : RuntimePathResolver::DEFAULT_HOST_MARKUP_DIRECTORY;
        $directory = DevElation::apply(ExtensionPointCatalog::FRONTEND_MARKUP_DIRECTORY, $default);
        if (!Str::is($directory)
            || preg_match('#^[a-z][a-z0-9_-]*(?:[/\\\\][a-z][a-z0-9_-]*)*$#', $directory) !== 1
        ) {
            throw new InvalidArgumentException('Host markup directory is invalid.');
        }

        return Str::replace($directory, '\\', '/');
    }
}
