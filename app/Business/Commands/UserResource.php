<?php
// UserCommand.php
namespace BlueFission\Wise\Res;

use BlueFission\Services\Service;
use App\Domain\User\Repositories\UserRepositorySql;
use BlueFission\BlueCore\Auth as Authenticator;
use BlueFission\Str;
use BlueFission\BlueCore\Business\Http\Api\Admin\UserController;

class UserResource extends Service
{
    private $userRepository;
    private $userController;
    private $authenticator;
    private $_properties;

    public function __construct(UserRepositorySql $userRepository, UserController $userController, Authenticator $authenticator)
    {
        $this->userRepository = $userRepository;
        $this->userController = $userController;
        $this->authenticator = $authenticator;
        $this->_properties = store('_system.user.properties') ?? [];

        parent::__construct();
    }

    private function isAdmin()
    {
        if ($this->authenticator->isAuthenticated()) {
            $user = $this->userRepository->find(USER_ID);
            return $user['is_admin'] ?? false;
        }
        return false;
    }

    public function handle($behavior, $args)
    {
        $action = $behavior->name();

        switch ($action) {
            case 'get':
                $this->getProperty($args);
                break;

            case 'set':
                $this->setProperty($args);
                break;

            case 'prompt':
                $this->promptUser($args);
                break;

            case 'update':
                $this->updatePassword($args);
                break;

            case 'list':
                if ( count($args) > 0) {
                    $this->listProperties();
                } elseif ($this->isAdmin()) {
                    $this->_response = $this->listUsers();
                }
                break;
            case 'find':
                if ($this->isAdmin()) {
                    $this->_response = $this->findUser($args);
                }
                break;
            case 'help':
                $this->_response = $this->getHelpMessage();
                break;

            default:
                $this->_response = "Invalid action '{$action}' specified.";
                break;
        }
    }

    private function getProperty($args)
    {
        if (!isset($args[0])) {
            $this->_response = "Please provide a property to get.";
            return;
        }

        $property = $args[0];

        if ($this->authenticator->isAuthenticated()) {
            $user = $this->userRepository->find(USER_ID);

            if (isset($user['data'][$property])) {
                $this->_response = "The property '{$property}' has the value: {$user[$property]}.";
            } elseif (isset($this->_properties[$property])) {
                $this->_response = "The property '{$property}' has the value: {$this->_properties[$property]}.";
            } else {
                $this->_response = "The property '{$property}' does not exist.";
            }
        } else {
            $this->_response = "User is not authenticated.";
        }
    }

    private function setProperty($args)
    {
        if (!isset($args[0])) {
            $this->_response = "Please provide a property name to set.";
            return;
        }

        $property = $args[0];
        $value = isset($args[1]) ? $args[1] : '';

        if ($this->authenticator->isAuthenticated()) {
            $user = $this->userRepository->find(USER_ID);

            if (isset($user[$property])) {
                $user[$property] = $value;
                $this->userRepository->save($user);
                $this->_response = "The property '{$property}' has been updated with the value '{$value}'.";
            } else {
                $this->_properties[$property] = $value;
                $this->_response = "The property '{$property}' with value '{$value}' has been added.";
            }
        } else {
            $this->_response = "User is not authenticated.";
        }
    }

    private function listProperties()
    {
        if ($this->authenticator->isAuthenticated()) {
            $user = $this->userRepository->find(USER_ID);
            $properties = implode(', ', array_keys($user['data']));
            $this->_response = "The following properties are available: {$properties}.";
        } else {
            $this->_response = "User is not authenticated.";
        }
    }

    private function getUserProperties()
    {
        $userProperties = store('_system.user.properties') ?? [];
        $output = "User Properties:\n\n";

        foreach ($userProperties as $key => $value) {
            $output .= "{$key}: {$value}\n";
        }

        if (empty($userProperties)) {
            $output = "No user properties found.";
        }

        $this->_response = $output;
    }


    private function getPropertyList()
    {
        $propertyList = $this->getUserProperties();

        if ($propertyList) {
            $message = "User properties: \n";

            foreach ($propertyList as $property) {
                $message .= "- {$property}\n";
            }

            $this->_response = $message;
        } else {
            $this->_response = "No user properties found.";
        }
    }

    private function listUsers()
    {
        if ($this->isAdmin()) {
            $users = $this->userRepository->all();
            $usernames = array_column($users, 'username');
            $this->_response = "Usernames:\n" . implode("\n", $usernames);
        } else {
            $this->_response = "User is not authenticated.";
        }
    }

    private function findUsers($args)
    {
        if ($this->isAdmin()) {
            if (!isset($args[0])) {
                $this->_response = "Please provide a user ID.";
                return;
            }

            $user = $this->userRepository->find($args[0]);

            if ($user) {
                $this->_response = "Username: {$user['username']}\nGoals:\n" . implode("\n", $user['goals']);
            } else {
                $this->_response = "User not found.";
            }
        } else {
            $this->_response = "User is not authenticated.";
        }
    }


    private function promptUser($args)
    {
        if (!isset($args[0])) {
            $this->_response = "Please provide a message to prompt the user.";
            return;
        }

        $message = $args[0];
        tell("System: {$message}\nPlease provide your response.", 'botman');
        $this->_response = "Message sent!";
    }

    private function updatePassword($args)
    {
        $length = isset($args[0]) ? (int)$args[0] : 8;

        if ($this->authenticator->isAuthenticated()) {
            $newPassword = Str::rand($length);
            // You can update the password for the user here
            $this->_response = "Password changed successfully. Your new password is: {$newPassword}";
        } else {
            $this->_response = "User is not authenticated.";
        }
    }

    private function getHelpMessage()
    {
        $help = "UserCommand Help:\n\n";
        $help .= "1. get user <property>: Retrieves the specified property for the authenticated user.\n";
        $help .= "2. ask user \"<message>\": Prompts the user with the specified message.\n";
        $help .= "3. update <length>: Changes the authenticated user's password to a random password with the specified length. Default length is 8 characters.\n";
        $help .= "4. help users: Displays this help information.\n";

        if ($this->isAdmin()) {
            $help .= "\nAdmin Commands:\n";
            $help .= "5. list users: Retrieves a list of all users.\n";
            $help .= "6. find users \"<userId>\": Finds a user by user ID.\n";
        }

        return $help;
    }

    public function __destruct()
    {
        store('_system.user.properties', $this->_properties);
    }
}