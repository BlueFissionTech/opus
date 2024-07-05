<?php
namespace App\Domain\Conversation\Repositories;

use BlueFission\Connections\Database\MysqlLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Conversation\Repositories\IMessageRepository;
use App\Domain\Conversation\Models\MessageModel as Model;
use App\Domain\Conversation\Message;

class MessageRepositorySql extends RepositorySql implements IMessageRepository
{
    protected $_name = "messages";

    public function __construct(MysqlLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($message_id)
    {
        $this->_model->read(['message_id'=>$message_id]);

        return $this->_model->response();
    }

    public function save(Message $message)
    {
        $this->_model->write($message);

        return $this->_model->response();
    }

    public function remove($message_id)
    {
        $this->_model->delete(['message_id'=>$message_id]);
    }
}