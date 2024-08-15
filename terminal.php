#!/usr/bin/php
<?php
use BlueFission\Utils\Loader;
use BlueFission\BlueCore\Engine as App;

session_start();

/**
 * Load the autoloader from the settings file.
 */
require 'common/config/settings.php';

/**
 * Include the autoloader for the dependencies.
 */
require 'vendor/autoload.php';
// $autoloader = require 'common/config/settings.php';

// Loader utility for non-composer compatible scripts
$loader = Loader::instance();
$loader->addPath(getcwd());
$loader->addPath(getcwd().DIRECTORY_SEPARATOR."app");

/**
 * Create a new instance of the BlueFission App Engine.
 */
$app = App::instance();

/**
 * Bootstrap the application, set arguments, and run it.
 */
$app
    ->bootstrap()
    ->args()
    ->process()
    ->run();

/*
 * Nodes
 Input

 Sensory Drives
 Calibrate
    Granular
        Signal
        Noise
        Boundaries
    Translate
        tolerance
        flags
        map

 Engine

 Output

 "Senses"
 Raw
 Plaintext
 XML
 Image
 Video
 Audio
 Glyph
 Face
*/

/*
ini_set('display_errors', 1);
set_time_limit(3000);
date_default_timezone_set('America/New_York');
error_reporting(E_ALL);
$autoloader = require '../vendor/autoload.php';

use \BlueFission\Utils\Loader;
// use \BlueFission\Intelligence\Engine;
// use \BlueFission\Services\Application as App;
use Cubiqle\Initiative\Engine as App;
use \BlueFission\Services\Service;
// use \BlueFission\System\System;
// use \BlueFission\HTML\Table;
// use \BlueFission\Utils\DateTime;
// use \BlueFission\Utils\Util;
// use \BlueFission\Connections\Database\MySQLLink;
// use \BlueFission\Data\Storage\MysqlBulk;
// use \BlueFission\Data\Storage\Mysql;
// use \BlueFission\System\System;
// use \BlueFission\DevValue;
// use \BlueFission\DevArray;
// use \BlueFission\DevString;
// use \BlueFission\Behavioral\Behaviors\State;
// use \BlueFission\Data\FileSystem;
// use \BlueFission\HTML\HTML;
// use \BlueFission\HTML\Form;
// use \BlueFission\HTML\Template;
use BlueFission\Data\Queues\DiskQueue as Queue;

Queue::$_mode = Queue::FILO;

$loader = Loader::instance();
$loader->addPath(getcwd());
$loader->addPath(getcwd()."/app/");

$loader->load('lib.Eidolon');

// Root interactions
// $loader->load('lib.Foundation');
// $loader->load('lib.terminal.*');
// $loader->load('lib.MetaDataObject');
// $loader->load('lib.Identity');
// $loader->load('lib.Message');
// $loader->load('lib.Agenda');
// $loader->load('lib.ActionItem');
// $loader->load('lib.Informant');
// $loader->load('lib.Page');
$loader->load('lib.Vocalizer');
// $loader->load('lib.google-api-php-client.src.Google.autoload');

$app = App::instance();
$app
->delegate('responder', 'BlueFission\Services\Response')
->delegate('chat', 'Cubiqle\Chat')
// ->delegate('foundation', 'Eidolon\Foundation')
// ->register('foundation', 'get', 'run')
// ->register('eidolon', 'OnComplete', function() {
//  if ( !headers_sent() ) {
//      $responder = $this->service('responder');

//      $terminal = $this->service('terminal');
//      $responder->data = $terminal->respond();
//      $responder->send();
//  }
// })
// ->register('eidolon', 'DoConfirm', function( $behavior ) {
//  $message = "Cannot complete action {$behavior->_context['service']}.{$behavior->_context['behavior']}";
//  $user_queue = 'user_0001_messages';

//  Queue::enqueue($user_queue, $message);
// })
->delegate('terminal', 'Terminal')
->register('terminal', 'get', 'start', Service::LOCAL_LEVEL)
->register('eidolon', 'continue', function() {
    // echo 'test';
    // die('something');
})
->register('terminal', 'post', 'command', Service::LOCAL_LEVEL)
->register('chat', 'start', function() {
    echo "hi\n";
    die('howdy');
})
// ->route('eidolon', 'sense', 'OnComplete', 'DoProcess')

// ->delegate('sense', 'Intelligence\Bot\Sensory\Input')
// interface cubiqle
// interface web
// interface email



// ->register('eidolon','get', function($data) {
//  // $content = file_get_contents($url);
//  // $drive = new SensoryDrive($data);
//  // $drive->setParent($this->_parent);
//     // $data = $drive->invoke($content);
//     // var_dump($data);
//     include('app/includes/home.php');
// })
->delegate('vocalizer', 'Vocalizer')
// ->delegate('browse', '\BlueFission\Intelligence\Input', 'file_get_contents' )
// ->delegate('responder', '\BlueFission\Services\Response', null)
// ->delegate('xml', '\BlueFission\HTML\XML')

->register('vocalizer', 'get', 'say')
->register('sense', 'DoProcess', 'invoke')
->register('brain', 'DoQueueInput', 'queueInput')

// ->input('text', 'file_get_contents')
// ->strategy('prediction', '\BlueFission\Intelligence\Strategies\Predictor')

// ->register('xml', 'url', function( $url ) {
//  // echo $url;
//  $this->parseXML( $url );
//  // echo $this->status();
//  var_dump($this->_data);
//  // echo 'done';
// })

// Classify
// part of self of not
// self or other
// directional trigger
// duty trigger
// creativity trigger

// ->route('browse', 'sense', 'OnComplete', 'DoProcess')
// ->route('sense', 'brain', 'OnCapture', 'DoQueueInput')
// ->register('sense', 'get', 'invoke', Service::SCOPE_LEVEL)
// ->register('responder', 'get', 'send', Service::SCOPE_LEVEL)
->args()
->run()
;
*/