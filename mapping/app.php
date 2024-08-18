<?php
use BlueFission\BlueCore\Engine as App;
use BlueFission\Wise\Res\EncyclopediaResource;
use BlueFission\Wise\Res\CalculatorResource;
use BlueFission\Wise\Res\HowToResource;
use BlueFission\Wise\Res\ResourceHelper;
use BlueFission\Wise\Res\FeatureResource;
use BlueFission\Wise\Res\SearchResource;
use BlueFission\Wise\Res\WebBrowserResource;
use BlueFission\Wise\Res\WeatherResource;
use BlueFission\Wise\Res\NewsResource;
use BlueFission\Wise\Res\SystemResourceResource;
use BlueFission\Wise\Res\VariableResource;
use BlueFission\Wise\Res\StackResource;
use BlueFission\Wise\Res\QueueResource;
use BlueFission\Wise\Res\FileResource;
use BlueFission\Wise\Res\NoteResource;
use BlueFission\Wise\Res\TodoResource;
use BlueFission\Wise\Res\StepResource;
use BlueFission\Wise\Res\ScheduleResource;
use BlueFission\Wise\Res\ResourceResource;
use BlueFission\Wise\Res\MessageResource;
use BlueFission\Wise\Res\EntityResource;
use BlueFission\Wise\Res\AIResource;
use BlueFission\Wise\Res\APIResource;
use BlueFission\Wise\Res\ActionResource;
use App\Business\Commands\UserResource;

$app = App::instance();

$app->register( 'skill', 'do', 'runSkill' );
$app->register( 'skill', 'list', 'listSkills' );

$app->delegate( 'feature', FeatureResource::class );
$app->register( 'feature', 'list', 'handle' );
$app->register( 'feature', 'show', 'handle' );
$app->register( 'feature', 'more', 'handle' );
$app->register( 'feature', 'help', 'handle' );

$app->delegate( 'action', ActionResource::class );
$app->register( 'action', 'do', 'handle' );
$app->register( 'action', 'make', 'handle' );
$app->register( 'action', 'generate', 'handle' );
$app->register( 'action', 'list', 'handle' );
$app->register( 'action', 'show', 'handle' );
$app->register( 'action', 'next', 'handle' );
$app->register( 'action', 'previous', 'handle' );
$app->register( 'action', 'delete', 'handle' );
$app->register( 'action', 'help', 'handle' );

$app->delegate( 'api', APIResource::class );
$app->register( 'api', 'do', 'handle' );
$app->register( 'api', 'make', 'handle' );
$app->register( 'api', 'generate', 'handle' );
$app->register( 'api', 'list', 'handle' );
$app->register( 'api', 'show', 'handle' );
$app->register( 'api', 'next', 'handle' );
$app->register( 'api', 'previous', 'handle' );
$app->register( 'api', 'delete', 'handle' );
$app->register( 'api', 'help', 'handle' );

$app->delegate( 'info', EncyclopediaResource::class );
$app->register( 'info', 'get', 'handle' );
$app->register( 'info', 'help', 'handle' );

$app->delegate( 'calc', CalculatorResource::class );
$app->register( 'calc', 'do', 'handle' );
$app->register( 'calc', 'help', 'handle' );

$app->delegate( 'web', SearchResource::class );
$app->register( 'web', 'find', 'handle' );
$app->register( 'web', 'get', 'handle' );
$app->register( 'web', 'next', 'handle' );
$app->register( 'web', 'previous', 'handle' );
$app->register( 'web', 'go', 'handle' );
$app->register( 'web', 'help', 'handle' );

$app->delegate( 'website', WebBrowserResource::class );
$app->register( 'website', 'make', 'create' );
$app->register( 'website', 'open', 'browse' );
$app->register( 'website', 'show', 'viewPageContent' );
$app->register( 'website', 'list', 'listItems' );
$app->register( 'website', 'select', 'selectItem' );
$app->register( 'website', 'input', 'fillForm' );
$app->register( 'website', 'go', 'clickElement' );
$app->register( 'website', 'submit', 'submitForm' );
$app->register( 'website', 'previous', 'handle' );
$app->register( 'website', 'next', 'handle' );
$app->register( 'website', 'less', 'less' );
$app->register( 'website', 'more', 'more' );
$app->register( 'website', 'save', 'bookmark' );

$app->delegate( 'howto', HowToResource::class );
$app->register( 'howto', 'find', 'search' );
$app->register( 'howto', 'show', 'show' );
$app->register( 'howto', 'help', 'help' );

$app->delegate( 'weather', WeatherResource::class );
$app->register( 'weather', 'get', 'handle' );
$app->register( 'weather', 'help', 'handle' );

$app->delegate( 'news', NewsResource::class );
$app->register( 'news', 'get', 'handle' );
$app->register( 'news', 'find', 'handle' );
$app->register( 'news', 'help', 'handle' );
$app->register( 'news', 'select', 'handle' );

// Simple Alias
$app->delegate( 'task', TodoResource::class );
$app->register( 'task', 'add', 'tasks' );

$app->delegate( 'entity', EntityResource::class );
$app->register( 'entity', 'do', 'handle' );
$app->register( 'entity', 'list', 'handle' );
$app->register( 'entity', 'get', 'handle' );
$app->register( 'entity', 'find', 'handle' );

$app->delegate( 'todo', TodoResource::class );
$app->register( 'todo', 'list', 'handle' );
$app->register( 'todo', 'make', 'handle' );
$app->register( 'todo', 'add', 'handle' );
$app->register( 'todo', 'open', 'handle' );
$app->register( 'todo', 'select', 'handle' );
$app->register( 'todo', 'edit', 'handle' );
$app->register( 'todo', 'previous', 'handle' );
$app->register( 'todo', 'next', 'handle' );
$app->register( 'todo', 'delete', 'handle' );
$app->register( 'todo', 'find', 'handle' );
$app->register( 'todo', 'help', 'handle' );

$app->delegate( 'step', StepResource::class );
$app->register( 'step', 'generate', 'perform' );
$app->register( 'step', 'update', 'perform' );
$app->register( 'step', 'list', 'perform' );
$app->register( 'step', 'show', 'perform' );
$app->register( 'step', 'get', 'perform' );
$app->register( 'step', 'set', 'perform' );
$app->register( 'step', 'add', 'perform' );
$app->register( 'step', 'delete', 'perform' );
$app->register( 'step', 'previous', 'perform' );
$app->register( 'step', 'next', 'perform' );
$app->register( 'step', 'help', 'perform' );

$app->delegate( 'schedule', ScheduleResource::class );
$app->register( 'schedule', 'add', 'process' );
$app->register( 'schedule', 'list', 'process' );
$app->register( 'schedule', 'next', 'process' );
$app->register( 'schedule', 'previous', 'process' );
$app->register( 'schedule', 'delete', 'process' );
$app->register( 'schedule', 'edit', 'process' );
$app->register( 'schedule', 'help', 'process' );

$app->delegate( 'system', SystemResourceResource::class );
$app->register( 'system', 'get', 'handle' );

$app->delegate( 'resource', ResourceResource::class );
$app->register( 'resource', 'list', 'handle' );
$app->register( 'resource', 'next', 'handle' );
$app->register( 'resource', 'previous', 'handle' );
$app->register( 'resource', 'show', 'handle' );
$app->register( 'resource', 'more', 'showAll' );
$app->register( 'resource', 'help', 'handle' );

$app->delegate( 'user', UserResource::class );
$app->register( 'user', 'list', 'handle' );
$app->register( 'user', 'find', 'handle' );
$app->register( 'user', 'update', 'handle' );
$app->register( 'user', 'help', 'handle' );
$app->register( 'user', 'prompt', 'handle' );
$app->register( 'user', 'get', 'handle' );

$app->delegate( 'variable', VariableResource::class );
$app->register( 'variable', 'show', 'handle' );
$app->register( 'variable', 'list', 'handle' );
$app->register( 'variable', 'get', 'handle' );
$app->register( 'variable', 'set', 'handle' );
$app->register( 'variable', 'find', 'handle' );
$app->register( 'variable', 'previous', 'handle' );
$app->register( 'variable', 'next', 'handle' );
$app->register( 'variable', 'delete', 'handle' );
$app->register( 'variable', 'help', 'handle' );

$app->delegate( 'queue', QueueResource::class );
$app->register( 'queue', 'add', 'handle' );
$app->register( 'queue', 'get', 'handle' );

$app->delegate( 'stack', StackResource::class );
$app->register( 'stack', 'add', 'handle' );
$app->register( 'stack', 'get', 'handle' );

$app->delegate( 'file', FileResource::class );
$app->register( 'file', 'make', 'handle' );
$app->register( 'file', 'edit', 'handle' );
$app->register( 'file', 'add', 'handle' );
$app->register( 'file', 'open', 'handle' );
$app->register( 'file', 'save', 'handle' );
$app->register( 'file', 'delete', 'handle' );
$app->register( 'file', 'list', 'handle' );
$app->register( 'file', 'find', 'handle' );
$app->register( 'file', 'help', 'handle' );

$app->delegate( 'note', NoteResource::class );
$app->register( 'note', 'make', 'handle' );
$app->register( 'note', 'save', 'handle' );
$app->register( 'note', 'list', 'handle' );
$app->register( 'note', 'get', 'handle' );
$app->register( 'note', 'find', 'handle' );
$app->register( 'note', 'next', 'handle' );
$app->register( 'note', 'previous', 'handle' );
$app->register( 'note', 'help', 'handle' );

$app->delegate( 'ai', AIResource::class );
$app->register( 'ai', 'list', 'handle' );
$app->register( 'ai', 'show', 'handle' );
$app->register( 'ai', 'find', 'handle' );
$app->register( 'ai', 'get', 'handle' );
$app->register( 'ai', 'do', 'handle' );
$app->register( 'ai', 'help', 'handle' );

$app->delegate( 'transcript', MessageResource::class );
$app->register( 'transcript', 'find', 'handle' );
$app->register( 'transcript', 'show', 'handle' );
$app->register( 'transcript', 'help', 'handle' );

$app->delegate( 'command', ResourceHelper::class );
// $app->register( 'command', 'parse', 'parse' );
$app->register( 'command', 'list', 'list' );
$app->register( 'command', 'get', 'all' );
$app->register( 'command', 'next', 'next' );
$app->register( 'command', 'previous', 'previous' );
$app->register( 'command', 'help', 'help' );