<?php

namespace App\Business\Managers;

use BlueFission\Services\Service;
use BlueFission\BlueCore\Auth as Authenticator;
use BlueFission\Data\Storage\Storage;
use BlueFission\BlueCore\Domain\Communication\Communication;
use BlueFission\BlueCore\Domain\Communication\CommunicationStatus;
use BlueFission\BlueCore\Domain\Communication\Repositories\ICommunicationRepository;
use BlueFission\BlueCore\Domain\Communication\Repositories\CommunicationChannelRepositorySql;
use BlueFission\BlueCore\Domain\Communication\Repositories\CommunicationTypeRepositorySql;
use BlueFission\BlueCore\Domain\Communication\Repositories\CommunicationStatusRepositorySql;
use BlueFission\BlueCore\Domain\Communication\Repositories\CommunicationRepositorySql;
use BlueFission\BlueCore\Domain\Communication\Queries\IUndeliveredCommunicationsQuery;
use Closure;

class CommunicationManager extends Service
{
    protected $repo;
    protected $undeliveredQuery;
    protected $drivers;

    public function __construct( ICommunicationRepository $repo, IUndeliveredCommunicationsQuery $undeliveredQuery, $driverConfigurations = [])
    {
        $this->repo = $repo;
        $this->undeliveredQuery = $undeliveredQuery;
        $this->drivers = [];

        foreach ($driverConfigurations as $driverClass => $condition) {
            $this->registerDriver($driverClass, $condition);
        }

        parent::__construct();
    }

    public function registerDriver(string $driverClass, Closure $condition): void
    {
        $this->drivers[$driverClass] = $condition;
    }

    // Basic CRUD methods for the communications table.
    public function create(Communication $communication)
    {
        $this->repo->save($communication);
    }

    public function read($communication_id)
    {
        return $this->repo->find($communication_id);
    }

    public function update(Communication $communication)
    {
        if ($communication->communication_id) {
            $this->repo->save($communication);
        }
    }

    public function delete($communication_id)
    {
        $this->repo->remove($communication_id);
    }

    public function queueMessage(Communication $communication)
    {
        // If no model is configured, directly route the communication
        // if (!MySQLLink::tableExists('communications') || $communication->isSecret()) {
        if ($this->repo === null || $communication->isSecret()) {
            $this->routeMessage($communication);
            return;
        }

        // Otherwise, store the communication in the queue
        $this->repo->save($communication);
    }

    public function processUndeliveredMessages()
    {
        // If no model is configured, return immediately
        if ($this->repo === null) {
            return;
        }

        // Process undelivered messages
        $messages = $this->getUndeliveredMessages();
        foreach ($messages as $communication) {
            $this->routeMessage($communication);
        }
    }

    protected function getUndeliveredMessages()
    {
        $messages = $this->undeliveredQuery->fetch();
        
        return $messages;
    }

    protected function routeMessage(Communication $communication)
    {
        // Choose the appropriate driver based on the communication type
        $driver = $this->resolveDriver($communication);
        if ($driver) {
            // Deliver the communication using the chosen driver
            $driver->send($communication);

            // Update the communication status to 'delivered'
            $communication->communication_status_id = CommunicationStatus::DELIVERED;
            $this->update($communication);
        }
    }

    protected function resolveDriver(Communication $communication)
	{
	    $driverClass = null;

	    foreach ($this->drivers as $class => $condition) {
	        if ($condition($communication)) {
	            $driverClass = $class;
	            break;
	        }
	    }

	    if ($driverClass !== null) {
	    	return new $driverClass;
	    }

	    return null;
	}


    public static function send(string $content, string $channel = null, int $userId = null, string $type = null, array $attachments = [], array $parameters = [], bool $isSecret = false)
    {
        // Get the default values for sender and recipient
        $senderId = 0; // Assuming 0 is the system ID
        $auth = new Authenticator(new Storage);
        $recipientId = $auth->id ?? 0; // Assuming the currently logged-in user or anonymous user if not logged in

        if ($userId !== null) {
            // If user_id is passed, it's a message from the user to the system
            $senderId = $userId;
            $recipientId = 0;
        }

        $channels = \App::makeInstance(CommunicationChannelRepositorySql::class);
        $types = \App::makeInstance(CommunicationTypeRepositorySql::class);
        $statuses = \App::makeInstance(CommunicationStatusRepositorySql::class);

        // Create and send the message
        $communication = new Communication();
        $communication->user_id = $senderId;
        $communication->communication_type_id = $types->findByName($type)['id'];
	    $communication->recipient_id = $recipientId;
	    $communication->content = $content;
        $communication->channel = $channel;
	    $communication->communication_status_id = $statuses->findByName(CommunicationStatus::UNSENT)['id'];

        if (!$isSecret) {
            $communications = \App::makeInstance(CommunicationRepositorySql::class);

            $data = $communications->save($communication, $attachments, $parameters);
            $communication->communication_id = $communications->lastInsertId();
            // $communication->assign($data);
        }
        // If the communication is secret, bypass saving and send directly
        $manager = instance('communication');
        $manager->routeMessage($communication);

        return $communication;
    }
}