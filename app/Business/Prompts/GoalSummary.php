<?php
namespace App\Business\Prompts;

use BlueFission\Automata\LLM\Prompt;

class GoalSummary extends Prompt
{
	protected $_fields = ['input', 'dialogue'];
	protected $_template = "Previous prompt: \"{input}\"

{dialogue}

Given this conversation write a long, descriptive GPT prompt for the Chatbot that reminds it to solve the User's goals. Use the following format -

<<You are a Chatbot who is assisting the User by engaging them in elucidating conversation, consulting them, asking relevant questions, or using the System to execute commands into Opus that are useful to their goals. So far you understand that ... and so far you have accomplished ... and you left off doing ...>>

If there are no clear or present goals yet, generate a prompt for the Chatbot to figure out the User's needs, intents, and circumstances.

Draft the prompt: ";
}