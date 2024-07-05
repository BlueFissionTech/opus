<?php
namespace App\Domain\Conversation\Repositories;

use BlueFission\Connections\Database\MysqlLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Conversation\Repositories\ITopicRepository;
use App\Domain\Conversation\Models\TopicModel as Model;
use App\Domain\Conversation\Topic;

class TopicRepositorySql extends RepositorySql implements ITopicRepository
{
    protected $_name = "topics";

    public function __construct(MysqlLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($topic_id)
    {
        $this->_model->read(['topic_id'=>$topic_id]);

        return $this->_model->response();
    }

    public function findByName($name)
    {
        $this->_model->read(['name'=>$name]);

        return $this->_model->response();
    }

    public function findByLabel($label)
    {
        $this->_model->read(['label'=>$label]);

        return $this->_model->response();
    }

    public function save(Topic $topic)
    {
        $this->_model->write($topic);

        return $this->_model->response();
    }

    public function remove($topic_id)
    {
        $this->_model->delete(['topic_id'=>$topic_id]);
    }
}