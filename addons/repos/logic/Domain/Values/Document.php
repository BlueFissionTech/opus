<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Values;

use InvalidArgumentException;

final readonly class Document
{
    public function __construct(
        public string $id,
        public string $name,
        public string $ownerId,
        public ?string $folderId = null,
        public string $mediaType = 'application/octet-stream'
    ) {
        RecordValidation::identifier($id);
        RecordValidation::identifier($ownerId);
        RecordValidation::name($name);
        if ($folderId !== null) { RecordValidation::identifier($folderId); }
        if (strlen($mediaType) > 127
            || preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~D', $mediaType) !== 1
        ) {
            throw new InvalidArgumentException('repos_media_type_invalid');
        }
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'owner_id' => $this->ownerId,
            'folder_id' => $this->folderId, 'media_type' => $this->mediaType];
    }
}
