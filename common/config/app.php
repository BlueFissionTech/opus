<?php

return [

	// Class alias for scope and extensibility 
	'aliases'=>[
		'\App'			=>'\BlueFission\BlueCore\Engine'
	],

	// Extensions to the application functionality
	'extensions'		=> [
		'App\Registration\AppRegistration',
	],

	// Gateways for processing requests
	'gateways'=> [
		'auth'			=>'\BlueFission\BlueCore\Gateway\AuthenticationGateway',
		'cache'			=>'\BlueFission\BlueCore\Gateway\CacheGateway',
		'csrf'			=>'\BlueFission\BlueCore\Gateway\CsrfGateway',
		'nocsrf'		=>'\BlueFission\BlueCore\Gateway\NoCsrfGateway',
		'admin:auth'	=>'\App\Business\Gateways\AdminAuthenticationGateway',
	],
];