<?php

return [
	'mysql'=> [
		'target'=>env('MYSQL_DB_HOST', 'localhost'),
		'username'=>env('MYSQL_DB_USERNAME'),
		'password'=>env('MYSQL_DB_PASSWORD'),
		'database'=>env('MYSQL_DB_NAME'),
		'port'=>env('MYSQL_DB_PORT'),
		'table'=>'',
		'key'=>'_rowid',
		'ignore_null'=>false,
	],
	'sqlite'=> [
		'location'=>null,
		'table'=>'',
		'key'=>'_rowid',
		'ignore_null'=>false,
	],
	'database'=> [
		'location'=>null
	],
	'elasticsearch'=> [
		'host'=>env('ELASTICSEARCH_HOST', 'localhost'),
		'port'=>env('ELASTICSEARCH_PORT', '9200'),
		'index'=>env('ELASTICSEARCH_INDEX', 'opus'),
		'type'=>env('ELASTICSEARCH_TYPE', 'opus'),
		'key'=>'_id',
	],
];