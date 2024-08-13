<?php
// HearsIntentMiddleware.php
namespace App\Business\Middleware;

use BotMan\BotMan\BotMan;
use BotMan\BotMan\Interfaces\Middleware\Received;
use BotMan\BotMan\Interfaces\Middleware\Sending;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BlueFission\Automata\Intent\Matcher;
use BlueFission\Automata\Context;

class HearsIntentMiddleware implements Received, Sending
{
    protected $matcher;
    protected $context;
    protected $response;
    protected $matchedSkill;

    public function __construct(Matcher $matcher, Context $context)
    {
        $this->matcher = $matcher;
        $this->context = $context;
    }

    public function received(IncomingMessage $message, $next, BotMan $bot)
    {
        $text = $message->getText();

        // Update context with any relevant information.
        // For example, updating the user's name:
        $this->context->set('username', $message->getSender());
        $this->context->set('message', $text);

        $intentScores = $this->matcher->match($text, $this->context);
        $message->addExtras('intent_scores', $intentScores);

        // if ($this->matchedSkill) {
        //     $this->matchedSkill->execute($this->context);
        //     $this->response = $this->matchedSkill->response();
        // }

        return $next($message);
    }

    public function sending($payload, $next, BotMan $bot)
    {
        if ( $this->matchedSkill ) {
            if ( isset($payload['message']) && $payload['message'] instanceof OutgoingMessage && $this->response != "") {
                $payload['message']->text($this->response);
            }
        }

        return $next($payload);
    }

    protected function replyWithSkillResponse(BotMan $bot, $response)
    {
        if (is_string($response) && $response != "") {
            $bot->reply($response);
        } elseif (is_array($response)) {
            // If the response is an array, you can convert it to JSON or handle it differently based on your needs
            $bot->reply(json_encode($response));
        } else {
            // Handle other response types if necessary
        }
    }

    public function matching(IncomingMessage $message, $pattern, $regexMatched)
    {
        return $regexMatched;
    }
}
