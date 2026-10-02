<?php

return [
	'frontend' => [
		'selected' => env('OPUS_FRONTEND_THEME', ''),
		'fallbacks' => explode(',', (string) env('OPUS_FRONTEND_FALLBACKS', 'ada')),
		'required_templates' => ['default.vibe', 'login.vibe'],
	],
	'html' => [
		'file'=>'',
		'cache'=>true,
		'cache_expire'=>60,
		'cache_directory'=>env('CACHE_DIRECTORY', 'cache/').'template',
		'max_records'=>1000, 
		'delimiter_start'=>'{', 
		'delimiter_end'=>'}',
		'module_token'=>'mod', 
		'module_directory'=>'modules/',
		'format'=>false,
		'eval'=>false,
	]
];
