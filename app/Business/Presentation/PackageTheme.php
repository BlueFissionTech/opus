<?php

declare(strict_types=1);

namespace App\Business\Presentation;

use BlueFission\BlueCore\Theme;

final class PackageTheme extends Theme
{
    public function __construct(string $name, string $location)
    {
        $this->name = $name;
        $this->path = $location;
        $this->location = rtrim($location, '/\\') . DIRECTORY_SEPARATOR;
    }
}
