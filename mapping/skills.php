<?php
use BlueFission\Automata\Intent\Intent;
use BlueFission\Automata\Context;
use App\Business\Skills\GreetingResponseSkill;
use App\Business\Skills\GoodbyeResponseSkill;
use App\Business\Skills\StatusSkill;
use App\Business\Skills\HowAreYouResponseSkill;
use App\Business\Skills\TimeAndDateSkill;
use App\Business\Skills\ApplicationBuilderSkill;
use App\Business\Skills\ModelBuilderSkill;
use App\Business\Skills\WebsiteBuilderSkill;
use App\Business\Skills\WeatherSkill;
use App\Business\Skills\NewsSkill;

$skills = App::instance()->service('skill');

// $greetingIntent = new Intent('greeting.response', 'Greeting Response', [
//     'keywords' => [
//         ['word' => 'good morning', 'priority' => 3.0],
//         ['word' => 'good afternoon', 'priority' => 3.0],
//         ['word' => 'good evening', 'priority' => 3.0],
//         ['word' => 'hello', 'priority' => 4.0],
//         ['word' => 'hi', 'priority' => 4.0],
//     ],
// ]);

// $greetingSkill = new GreetingResponseSkill();

// $skills
//     ->registerSkill($greetingSkill)
//     ->registerIntent($greetingIntent)
//     ->associate($greetingIntent, $greetingSkill);


// // New intent and skill registration for GoodbyeResponseSkill
// $goodbyeIntent = new Intent('goodbye.response', 'Goodbye Response', [
//     'keywords' => [
//         ['word' => 'good night', 'priority' => 4.0],
//         ['word' => 'goodbye', 'priority' => 4.0],
//         ['word' => 'bye', 'priority' => 4.0],
//         ['word' => 'see you later', 'priority' => 3.0],
//         ['word' => 'take care', 'priority' => 3.0],
//     ],
// ]);

// $goodbyeSkill = new GoodbyeResponseSkill();

// $skills
//     ->registerSkill($goodbyeSkill)
//     ->registerIntent($goodbyeIntent)
//     ->associate($goodbyeIntent, $goodbyeSkill);

// // New intent and skill registration for HowAreYouResponseSkill
// $howAreYouIntent = new Intent('howareyou.response', 'How Are You Response', [
//     'keywords' => [
//         ['word' => 'how are you', 'priority' => 5.0],
//         ['word' => 'how is it going', 'priority' => 4.0],
//         ['word' => 'how are things', 'priority' => 3.0],
//         ['word' => 'how do you feel', 'priority' => 2.0],
//         ['word' => 'are you okay', 'priority' => 2.0],
//     ],
// ]);

// $howAreYouSkill = new HowAreYouResponseSkill();

// $skills
//     ->registerSkill($howAreYouSkill)
//     ->registerIntent($howAreYouIntent)
//     ->associate($howAreYouIntent, $howAreYouSkill);


// // New intent and skill registration for UpdateSkill
// $updateIntent = new Intent('status.skill', 'Update Skill', [
//     'keywords' => [
//         ['word' => 'what\'s up', 'priority' => 2.0],
//         ['word' => 'what\'s going on', 'priority' => 2.0],
//         ['word' => 'what\'s your status', 'priority' => 5.0],
//         ['word' => 'give me an update', 'priority' => 3.0],
//         ['word' => 'give me a status report', 'priority' => 3.0],
//         ['word' => 'system status', 'priority' => 3.0],
//     ],
// ]);

// $updateSkill = new StatusSkill();

// $skills
//     ->registerSkill($updateSkill)
//     ->registerIntent($updateIntent)
//     ->associate($updateIntent, $updateSkill);

// ApplicationBuilderSkill
$applicationBuilderIntent = new Intent('application.builder', 'Application Builder', [
    'keywords' => [
        ['word' => 'create application', 'priority' => 5.0],
        ['word' => 'configure application', 'priority' => 4.0],
        ['word' => 'set up application', 'priority' => 3.0],
        ['word' => 'application builder', 'priority' => 3.0],
        ['word' => 'application configuration', 'priority' => 3.0],
    ],
]);

$applicationBuilderSkill = new ApplicationBuilderSkill();

$skills
    ->registerSkill($applicationBuilderSkill)
    ->registerIntent($applicationBuilderIntent)
    ->associate($applicationBuilderIntent, $applicationBuilderSkill);

// WebsiteBuilderSkill
$websiteBuilderIntent = new Intent('website.builder', 'Website Builder', [
    'keywords' => [
        ['word' => 'create website', 'priority' => 5.0],
        ['word' => 'build website', 'priority' => 4.0],
        ['word' => 'website builder', 'priority' => 3.0],
        ['word' => 'make website', 'priority' => 3.0],
        ['word' => 'design website', 'priority' => 3.0],
    ],
]);

$websiteBuilderSkill = new WebsiteBuilderSkill();

$skills
    ->registerSkill($websiteBuilderSkill)
    ->registerIntent($websiteBuilderIntent)
    ->associate($websiteBuilderIntent, $websiteBuilderSkill);

$modelBuilderIntent = new Intent('model.builder', 'Model Builder', [
    'keywords' => [
        ['word' => 'Machine Learning', 'priority' => 5.0],
        ['word' => 'AI Model', 'priority' => 1.0],
        ['word' => 'Deep Learning', 'priority' => 2.0],
        ['word' => 'Neural Net', 'priority' => 2.0],
        ['word' => 'SageMaker', 'priority' => 2.0],
        ['word' => 'Model', 'priority' => 2.0],
        ['word' => 'Generate', 'priority' => 2.0],
    ],
]);

$modelBuilderSkill = new ModelBuilderSkill();

$skills
    ->registerSkill($modelBuilderSkill)
    ->registerIntent($modelBuilderIntent)
    ->associate($modelBuilderIntent, $modelBuilderSkill);


// New intent and skill registration for TimeAndDateSkill
$timeAndDateIntent = new Intent('timeanddate.response', 'Time And Date Response', [
    'keywords' => [
        ['word' => 'what is the time', 'priority' => 1.0],
        ['word' => 'what time is it', 'priority' => 4.0],
        ['word' => 'tell me the time', 'priority' => 2.0],
        ['word' => 'current time', 'priority' => 3.0],
        ['word' => 'what is the date', 'priority' => 2.0],
        ['word' => 'what date is it', 'priority' => 2.0],
        ['word' => 'tell me the date', 'priority' => 1.0],
        ['word' => 'current date', 'priority' => 1.0],
    ],
]);

// $timeAndDateSkill = new TimeAndDateSkill();

// $skills
//     ->registerSkill($timeAndDateSkill)
//     ->registerIntent($timeAndDateIntent)
//     ->associate($timeAndDateIntent, $timeAndDateSkill);

// // WeatherSkill
// $weatherIntent = new Intent('weather.response', 'Weather Response', [
//     'keywords' => [
//         ['word' => 'weather', 'priority' => 3.0],
//         ['word' => 'current weather', 'priority' => 3.0],
//         ['word' => 'weather update', 'priority' => 2.0],
//         ['word' => 'what is the weather', 'priority' => 2.0],
//         ['word' => 'weather forecast', 'priority' => 1.0],
//         ['word' => 'what is it like outside', 'priority' => 1.0],
//         ['word' => 'what is the temperature', 'priority' => 5.0],
//         ['word' => 'current temperature', 'priority' => 1.0],
//         ['word' => 'what\'s the forecast', 'priority' => 1.0],
//     ],
// ]);

// $weatherSkill = new WeatherSkill();
// $skills
//     ->registerSkill($weatherSkill)
//     ->registerIntent($weatherIntent)
//     ->associate($weatherIntent, $weatherSkill);

// // NewsSkill
// $newsIntent = new Intent('news.response', 'News Response', [
//     'keywords' => [
//         ['word' => 'news', 'priority' => 5.0],
//         ['word' => 'latest news', 'priority' => 1.0],
//         ['word' => 'news update', 'priority' => 1.0],
//         ['word' => 'headline news', 'priority' => 1.0],
//         ['word' => 'what is the news', 'priority' => 3.0],
//         ['word' => 'what\'s the news', 'priority' => 1.0],
//         ['word' => 'what\'s the latest', 'priority' => 1.0],
//         ['word' => 'what\'s happening', 'priority' => 1.0],
//         ['word' => 'what\'s new', 'priority' => 1.0],
//         ['word' => 'what\'s trending', 'priority' => 1.0],
//     ],
// ]);

// $newsSkill = new NewsSkill();
// $skills
//     ->registerSkill($newsSkill)
//     ->registerIntent($newsIntent)
//     ->associate($newsIntent, $newsSkill);

// // ReminderSkill
// $reminderIntent = new Intent('reminder.response', 'Reminder Response', [
//     'keywords' => [
//         ['word' => 'set a reminder', 'priority' => 4.0],
//         ['word' => 'remind me', 'priority' => 4.0],
//         ['word' => 'create a reminder', 'priority' => 3.0],
//     ],
// ]);

// // $reminderSkill = new ReminderSkill();
// $skills->registerIntent($reminderIntent);

// // TimerSkill
// $timerIntent = new Intent('timer.response', 'Timer Response', [
//     'keywords' => [
//         ['word' => 'set a timer', 'priority' => 4.0],
//         ['word' => 'start a timer', 'priority' => 4.0],
//         ['word' => 'timer', 'priority' => 3.0],
//     ],
// ]);

// // $timerSkill = new TimerSkill();
// $skills->registerIntent($timerIntent);

// // ManageDashboardSkill
// $manageDashboardIntent = new Intent('manage.dashboard', 'Manage Dashboard', [
//     'keywords' => [
//         ['word' => 'dashboard', 'priority' => 3.0],
//         ['word' => 'manage dashboard', 'priority' => 4.0],
//         ['word' => 'open dashboard', 'priority' => 4.0],
//         ['word' => 'configuration', 'priority' => 3.0],
//     ],
// ]);

// // $manageDashboardSkill = new ManageDashboardSkill();
// $skills->registerIntent($manageDashboardIntent);

// // CreateWebAppSkill
// $createWebAppIntent = new Intent('create.webapp', 'Create Web App', [
//     'keywords' => [
//         ['word' => 'create web app', 'priority' => 4.0],
//         ['word' => 'build a website', 'priority' => 4.0],
//         ['word' => 'start a web app', 'priority' => 3.0],
//     ],
// ]);

// // $createWebAppSkill = new CreateWebAppSkill();
// $skills->registerIntent($createWebAppIntent);

// // DataCollectionSkill
// $dataCollectionIntent = new Intent('data.collection', 'Data Collection', [
//     'keywords' => [
//         ['word' => 'collect data', 'priority' => 4.0],
//         ['word' => 'data scraping', 'priority' => 4.0],
//         ['word' => 'gather data', 'priority' => 3.0],
//     ],
// ]);

// // $dataCollectionSkill = new DataCollectionSkill();
// $skills->registerIntent($dataCollectionIntent);

// // AggregatorSkill
// $aggregatorIntent = new Intent('aggregator.response', 'Aggregator Response', [
//     'keywords' => [
//         ['word' => 'content aggregator', 'priority' => 4.0],
//         ['word' => 'news aggregator', 'priority' => 4.0],
//         ['word' => 'RSS feed', 'priority' => 3.0],
//     ],
// ]);

// // $aggregatorSkill = new AggregatorSkill();
// $skills->registerIntent($aggregatorIntent);

// // MultiUserWebAppSkill
// $multiUserWebAppIntent = new Intent('multiuser.webapp', 'Multiuser Web App', [
//     'keywords' => [
//         ['word' => 'create multiuser app', 'priority' => 4.0],
//         ['word' => 'build a collaborative app', 'priority' => 4.0],
//         ['word' => 'multiuser web app', 'priority' => 3.0],
//     ],
// ]);

// // $multiUserWebAppSkill = new MultiUserWebAppSkill();
// $skills->registerIntent($multiUserWebAppIntent);

// // CodeGenerationSkill
// $codeGenerationIntent = new Intent('code.generation', 'Code Generation', [
//     'keywords' => [
//         ['word' => 'generate code', 'priority' => 5.0],
//         ['word' => 'write code', 'priority' => 4.0],
//         ['word' => 'code snippet', 'priority' => 3.0],
//         ['word' => 'create code', 'priority' => 3.0],
//         ['word' => 'auto code', 'priority' => 3.0],
//     ],
// ]);

// // $codeGenerationSkill = new CodeGenerationSkill();
// $skills->registerIntent($codeGenerationIntent);

// // DataAnalysisSkill
// $dataAnalysisIntent = new Intent('data.analysis', 'Data Analysis', [
//     'keywords' => [
//         ['word' => 'analyze data', 'priority' => 5.0],
//         ['word' => 'data analysis', 'priority' => 4.0],
//         ['word' => 'statistical analysis', 'priority' => 3.0],
//         ['word' => 'data processing', 'priority' => 3.0],
//         ['word' => 'data visualization', 'priority' => 3.0],
//     ],
// ]);

// // $dataAnalysisSkill = new DataAnalysisSkill();
// $skills->registerIntent($dataAnalysisIntent);

// // FeatureEngineeringSkill
// $featureEngineeringIntent = new Intent('feature.engineering', 'Feature Engineering', [
//     'keywords' => [
//         ['word' => 'feature engineering', 'priority' => 5.0],
//         ['word' => 'create features', 'priority' => 4.0],
//         ['word' => 'transform data', 'priority' => 3.0],
//         ['word' => 'preprocess data', 'priority' => 3.0],
//         ['word' => 'extract features', 'priority' => 3.0],
//     ],
// ]);

// // $featureEngineeringSkill = new FeatureEngineeringSkill();
// $skills->registerIntent($featureEngineeringIntent);

// // AppGenerationSkill
// $appGenerationIntent = new Intent('app.generation', 'App Generation', [
//     'keywords' => [
//         ['word' => 'generate app', 'priority' => 5.0],
//         ['word' => 'create app', 'priority' => 4.0],
//         ['word' => 'app builder', 'priority' => 3.0],
//         ['word' => 'build app', 'priority' => 3.0],
//         ['word' => 'develop app', 'priority' => 3.0],
//     ],
// ]);

// // $appGenerationSkill = new AppGenerationSkill();
// $skills->registerIntent($appGenerationIntent);

// // ModuleGenerationSkill
// $moduleGenerationIntent = new Intent('module.generation', 'Module Generation', [
//     'keywords' => [
//         ['word' => 'generate module', 'priority' => 5.0],
//         ['word' => 'create module', 'priority' => 4.0],
//         ['word' => 'module builder', 'priority' => 3.0],
//         ['word' => 'build module', 'priority' => 3.0],
//         ['word' => 'develop module', 'priority' => 3.0],
//     ],
// ]);

// // $moduleGenerationSkill = new ModuleGenerationSkill();
// $skills->registerIntent($moduleGenerationIntent);

// // OfficeAutomationSkill
// $officeAutomationIntent = new Intent('office.automation', 'Office Automation', [
//     'keywords' => [
//         ['word' => 'office automation', 'priority' => 5.0],
//         ['word' => 'automate tasks', 'priority' => 4.0],
//         ['word' => 'document automation', 'priority' => 3.0],
//         ['word' => 'spreadsheet automation', 'priority' => 3.0],
//         ['word' => 'email automation', 'priority' => 3.0],
//     ],
// ]);

// // $officeAutomationSkill = new OfficeAutomationSkill();
// $skills->registerIntent($officeAutomationIntent);

$catchAllIntent = new Intent('catchall.response', 'Catch All Response', [
    'keywords' => [
        ['word' => 'what is your name', 'priority' => 1.0],
        ['word' => 'tell me a joke', 'priority' => 1.0],
        ['word' => 'do you like movies', 'priority' => 1.0],
        ['word' => 'what is your favorite color', 'priority' => 1.0],
        ['word' => 'how old are you', 'priority' => 1.0],
        ['word' => 'where do you live', 'priority' => 1.0],
        ['word' => 'can you cook', 'priority' => 1.0],
        ['word' => 'what do you do for fun', 'priority' => 1.0],
        ['word' => 'tell me something interesting', 'priority' => 1.0],
        ['word' => 'who created you', 'priority' => 1.0],
        ['word' => 'what languages do you speak', 'priority' => 1.0],
        ['word' => 'do you have any hobbies', 'priority' => 1.0],
        ['word' => 'what is your favorite food', 'priority' => 1.0],
        ['word' => 'tell me about yourself', 'priority' => 1.0],
        ['word' => 'do you have any pets', 'priority' => 1.0],
        ['word' => 'do you have any siblings', 'priority' => 1.0],
        ['word' => 'are you a robot', 'priority' => 1.0],
        ['word' => 'what is your favorite book', 'priority' => 1.0],
        ['word' => 'what is your favorite movie', 'priority' => 1.0],
        ['word' => 'do you like music', 'priority' => 1.0],
        ['word' => 'what are your dreams', 'priority' => 1.0],
        ['word' => 'what do you think about', 'priority' => 1.0],
        ['word' => 'what are you afraid of', 'priority' => 1.0],
        ['word' => 'do you have any secrets', 'priority' => 1.0],
        ['word' => 'what do you like most about yourself', 'priority' => 1.0],
    ],
]);

// $catchAllSkill = new CatchAllResponseSkill();
$skills->registerIntent($catchAllIntent);
