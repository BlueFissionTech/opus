<?php
// GoodbyeResponseSkill.php
namespace App\Business\Skills;

use BlueFission\Automata\Context;
use BlueFission\BlueCore\Skill\BaseSkill;

class GoodbyeResponseSkill extends BaseSkill
{
    protected $response;

    public function __construct()
    {
        parent::__construct('Goodbye Response');
    }

    public function execute(Context $context = null)
    {
        $hour = date('H');
        $farewell = '';
        $name = null;
        if ($context) {
            $name = $context->get('username');
        }

        if ($hour >= 5 && $hour < 12) {
            $farewell = 'Have a great morning';
        } elseif ($hour >= 12 && $hour < 18) {
            $farewell = 'Have a great afternoon';
        } elseif ($hour >= 18 || $hour < 5) {
            $farewell = 'Have a great evening';
        }

        $questions = [
            "Do you need any more help?",
            "Is there anything else I can do for you?",
            "Can I assist you with anything else?",
        ];

        $randomQuestion = $questions[array_rand($questions)];

        if ($name) {
            $this->response = "{$farewell}, {$name}! {$randomQuestion}";
        } else {
            $this->response = "{$farewell}! {$randomQuestion}";
        }
    }

    public function response(): string
    {
        return $this->response;
    }
}
