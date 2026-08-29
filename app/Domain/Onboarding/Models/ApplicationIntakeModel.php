<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use BlueFission\BlueCore\Model\ModelSql;

class ApplicationIntakeModel extends ModelSql
{
    protected $_table = 'application_intakes';

    protected $_fields = [
        'application_intake_id',
        'session_key',
        'tenant_id',
        'application_slug',
        'prompt_version',
        'status',
        'answers',
        'defaults',
        'skipped',
        'actor',
        'correlation_id',
        'revision',
        'session_created_at',
        'session_updated_at',
        'completed_at',
    ];

    protected $_ignore_null = false;
}
