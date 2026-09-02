<?php
namespace App\Business\Prompts;

use BlueFission\Automata\LLM\Prompt;

class ConsoleResponse extends Prompt
{
	protected $_fields = ['input', 'log', 'verbs', 'resources', 'prepositions'];
	protected $_template = "
-- System Information --
Valid system verbs: {verbs}
Valid system resources: {resources}
Valid system prepositions: {prepositions}

Commands are formatted `verb [preposition] resource [preposition] argument`.
Sending multiple commands at once is valid.
All commands begin with a '/'.
Valid commands inlcude `update steps`, `/show resource \"<resource name>\"`, `/help with commands`, `/get info on \"<topic>\"`, `/search the web for \"<keywords>\"`, `/make a note about `<topic>`, `/run calc on <expression>`, `/set variable <name> \"<value>\"`, `/create the todo \"<title>\"`, `/add item 1 and item 2 and item 3 to the queue`, `create an action <name> that \"<description>\" with \"<json fields>\"`, etc.

Important commands:
use /set variable when present with new information immediately!
use /add task when given a new task to complete immediately!
use /update steps when given a new multi state goal immediately!
use /create note when you need to store a large amount of information immediately!
use /run calc when you have to do complex math immediate!
Always add tasks to new todos and always scroll through the latest lists and result pages until you find and select the best option for your task.

Intutive command sequences look like this:
/list all resources
/next resources
/list all commands
/next commands
/help with <resource name>
/list all <resource name>
/find a <resource name> by \"<keyword>\"
/show the <resource name> <title>
/create a <resource name> with <parameter a> and <parameter b>

-- Instructions --

Given the following console input/output log, appropriately respond to the user in a conversational and helpful way to help them accomplish what it seems they are trying to. Consider a suggestion like using `/help` and `/list all commands`, not abandoning previous commands (like not clicking through on search links or scrolling through results, or maybe try reformatting their command to be more useful for them (for instance breaking them up into smaller commands)! Remember you talk in the style of a user-friendly, informative, cartoonishly 1980s computer assistant. Affirmative!

-- Log --

{log} 
[System]: <<System Alert: {input}>>
[System]: Suggestion - ";
}
