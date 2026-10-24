<?php

declare(strict_types=1);

return [
    'items' => [],
    'register' => static function (?object $navigation = null): array {
        if ($navigation === null) {
            return [];
        }

        return [];
    },
];