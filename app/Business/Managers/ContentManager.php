<?php

namespace App\Business\Managers;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\Services\Service;
use BlueFission\BlueCore\Domain\Content\Queries\PublishedContentQuerySql;
use BlueFission\Services\Mapping;

class ContentManager extends Service
{
	protected $_query;

	public function __construct()
    {
        parent::__construct();
    }

    static function map()
    {
    	$query = instance()->getDynamicInstance(PublishedContentQuerySql::class);
		$pages = $query->fetch();
		foreach ($pages as $page) {
			$path = '/'.trim($page['uri'], '/').'/'.$page['slug'];

			// $template = $page['theme'].'/'.$page['template'];
			$theme = $page['theme'] ?? 'app/default';
			$template = $page['template'] ?? 'default.html';

			$name = trim(str_replace(['/','-','_'], ['.','.','.'], $path), '.');

			$data = [
				'name'=>env('APP_NAME'),
				'title'=>$page['title'],
				'content'=>$page['content'],
				'keywords'=>$page['keywords'],
				'description'=>$page['description'],
			];

			Mapping::add($path, function() use ( $data, $template ) {
				return template($theme, $template, $data);
			}, $name, 'get');
		}
    }
}