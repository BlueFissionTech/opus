<?php

declare(strict_types=1);

use AddOns\Tributary\Registration\AddOnRegistration;

require_once __DIR__ . '/logic/Lifecycle.php';

return static fn (): AddOnRegistration => new AddOnRegistration();
