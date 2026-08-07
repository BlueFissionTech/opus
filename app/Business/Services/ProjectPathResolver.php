<?php

declare(strict_types=1);

namespace App\Business\Services;

final class ProjectPathResolver
{
    public static function resolve(
        string $pathInProject,
        string $applicationRoot,
        string $projectRoot
    ): string {
        $applicationRoot = rtrim($applicationRoot, '/\\');
        $projectRoot = rtrim($projectRoot, '/\\');
        $pathInProject = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pathInProject);

        $candidate = $applicationRoot . DIRECTORY_SEPARATOR . $pathInProject;
        $fallback = $projectRoot . DIRECTORY_SEPARATOR . $pathInProject;

        if (file_exists($candidate)) {
            return $candidate;
        }

        if (strpbrk($pathInProject, '*?[') !== false) {
            $matches = glob($candidate);
            if ($matches !== false && $matches !== []) {
                return $candidate;
            }
        }

        return $fallback;
    }
}
