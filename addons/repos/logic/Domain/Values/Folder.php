<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Values;

use InvalidArgumentException;

final readonly class Folder
{
    public function __construct(
        public string $id,
        public string $name,
        public string $ownerId,
        public ?string $parentId = null
    ) {
        RecordValidation::identifier($id);
        RecordValidation::name($name);
        RecordValidation::identifier($ownerId);
        if ($parentId !== null) { RecordValidation::identifier($parentId); }
        if ($parentId === $id) { throw new InvalidArgumentException('repos_folder_cycle'); }
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name,
            'owner_id' => $this->ownerId, 'parent_id' => $this->parentId];
    }
}
