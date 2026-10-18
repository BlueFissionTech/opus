<?php

return [

	// Class alias for scope and extensibility
	'aliases'=>[
		'\App'			=>'\BlueFission\BlueCore\Engine'
	],

	// Extensions to the application functionality
	'extensions'		=> [
		\App\Business\Services\ApplicationProfile::current()->minimal()
            ? \App\Registration\ProfileRegistration::class : \App\Registration\AppRegistration::class,
	],

	// Gateways for processing requests
	'gateways'=> [
		'auth'			=>'\App\Business\Gateways\AuthenticationGateway',
		'cache'			=>'\BlueFission\BlueCore\Gateway\CacheGateway',
		'csrf'			=>'\BlueFission\BlueCore\Gateway\CsrfGateway',
		'nocsrf'		=>'\BlueFission\BlueCore\Gateway\NoCsrfGateway',
		'admin:auth'	=>'\App\Business\Gateways\AdminAuthenticationGateway',
	],
];
