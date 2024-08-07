<?php
// WebsiteBuilderSkill.php
namespace App\Business\Skills;

use BlueFission\Automata\Context;
use BlueFission\Automata\Intent\Skill\BaseSkill;
use BlueFission\BlueCore\Conversation\WebsiteBuilderConversation;

class WebsiteBuilderSkill extends BaseSkill
{
    protected $response;

    public function __construct()
    {
        parent::__construct('Website Builder');
    }

    public function execute(Context $context = null)
    {
        $botman = instance('botman');
        $conversation = new WebsiteBuilderConversation();
        $botman->startConversation($conversation);
        
        $this->response = ""; // Empty response since the conversation handles the reply
    }

    public function response(): string
    {
        return (string)$this->response;
    }
}
