<?php
// SkillManager.php
namespace App\Business\Managers;

use BlueFission\Automata\Intent\Intent;
use BlueFission\Automata\Intent\Matcher;
use BlueFission\Automata\Intent\Skill\BaseSkill;
use BlueFission\Automata\Context;
use BlueFission\Services\Service;
use BlueFission\Automata\Language\EntityExtractor;

class SkillManager extends Service
{
    // protected $skills = [];
    // protected $intents = [];
    protected $matcher = null;

    public function __construct( Matcher $matcher )
    {
        $this->matcher = $matcher;
        parent::__construct();
    }

    public function listSkills(): array
    {
        $skills = $this->matcher->getSkills();
        $list = [];
        foreach ($skills as $skill) {
            $list[] = $skill->name();
        }
        $response = '- '.implode("\n- ", $list);
        $this->_response = $response;
        return $list;
    }

    public function registerSkill(BaseSkill $skill): self
    {
        $this->matcher->registerSkill($skill);
        return $this;
    }

    public function registerIntent(Intent $intent): self
    {
        // $this->intents[$intent->getName()] = $intent;
        $this->matcher->registerIntent($intent);
        return $this;
    }

    public function associate(Intent $intent, BaseSkill $skill): self
    {
        $this->matcher->associate($intent, $skill);
        return $this;
    }

    public function getIntent(string $intentLabel): ?Intent
    {
        return $this->matcher->getIntent($intentLabel);
    }

    public function getIntents(): ?Intent
    {
        return $this->matcher->getIntents();
    }

    public function getSkill(string $skillName): ?BaseSkill
    {
        return $this->matcher->getSkill($skillName);
    }

    public function getSkillsForIntent(Intent $intent): array
    {
        $intentLabel = $intent->getLabel();
        $associatedSkillNames = $this->matcher->map()[$intentLabel] ?? [];

        return array_map(function ($skillName) {
            return $this->matcher->getSkill($skillName);
        }, $associatedSkillNames);
    }

    public function process( $intent, $context ) {
        return $this->matcher->process($intent, $context);
    }

    public function runSkill( $behavior, $args )
    {
        if (empty($args) || $args == null) {
            $response = "Here's a list of skills\n";
            $list = $this->listSkills();
            $response .= '- '.implode("\n- ", $list);
            $response .= "\n\nWhich skill would you like to run?";
            $response .= "\n\nYou can also say 'run skill <skill name>'";

            $this->_response = $response;

            return;
        }

        if (isset($args) && isset($args[0])) {
            $extractor = new EntityExtractor();
            // $name = $extractor->object($args[0]);
            // if (!empty($name)) {
            //     $name = $name[0];
            // } else {
            //     $name = $args[0];
            // }

            $name = isset($args[0]) ? $args[0] : '';

            // TODO: Build context from available information
            $context = new Context();

            $skill = $this->matcher->getSkill($name);
            if ($skill) {
                $skill->execute($context);
                $this->_response = $skill->response();
            } else {
                $this->_response = "Skill '$name' not found";
            }
        }
    }
}
