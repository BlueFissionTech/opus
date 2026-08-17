<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Str;

final class ProjectPathResolver
{
    public static function resolve(
        string $pathInProject,
        string $applicationRoot,
        string $projectRoot
    ): string {
        $applicationRoot = rtrim($applicationRoot, '/\\');
        $projectRoot = rtrim($projectRoot, '/\\');
        $pathInProject = Str::make($pathInProject)
            ->replace('/', DIRECTORY_SEPARATOR)
            ->replace('\\', DIRECTORY_SEPARATOR)
            ->val();

        $candidate = $applicationRoot . DIRECTORY_SEPARATOR . $pathInProject;
        $fallback = $projectRoot . DIRECTORY_SEPARATOR . $pathInProject;

        if (file_exists($candidate)) {
            return $candidate;
        }

        $path = Str::make($pathInProject);
        if ($path->contains('*') || $path->contains('?') || $path->contains('[')) {
            $matches = glob($candidate);
            if ($matches !== false && $matches !== []) {
                return $candidate;
            }
        }

        return $fallback;
    }
}
