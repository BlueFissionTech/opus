<?php
declare(strict_types=1);
namespace AddOns\Meridia\Domain;

use BlueFission\{Obj, Str};
use InvalidArgumentException;

/** Host-admitted provenance, not an Annex signature verification result. */
class RouteSource extends Obj
{
    protected $_immutable = true;

    public function __construct(
        public readonly string $id,
        public readonly string $revision,
        public readonly string $application,
        public readonly ?string $tenant,
        public readonly int $expiresAt
    ) {
        foreach ([$id, $revision, $application, ...($tenant === null ? [] : [$tenant])] as $value) {
            if (!Str::make($value)->matches('/^[a-zA-Z0-9._-]{1,128}$/D')) {
                throw new InvalidArgumentException('meridia_source_invalid');
            }
        }
        if ($expiresAt < 1) { throw new InvalidArgumentException('meridia_source_expiry_invalid'); }
        $this->_data = ['version' => 1, 'id' => $id, 'revision' => $revision,
            'application' => $application, 'tenant' => $tenant, 'expires_at' => $expiresAt];
        parent::__construct();
    }
}
