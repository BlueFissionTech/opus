<?php
namespace App\Domain\Conversation\Repositories;

use BlueFission\Connections\Database\MysqlLink;
use BlueFission\Framework\Repository\RepositorySql;
use App\Domain\Conversation\Repositories\IDialogueTypeRepository;
use App\Domain\Conversation\Models\DialogueTypeModel as Model;
use App\Domain\Conversation\DialogueType;

class DialogueTypeRepositorySql extends RepositorySql implements IDialogueTypeRepository
{
    protected $_name = "dialogues";

    public function __construct(MysqlLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($dialogue_id)
    {
        $this->_model->read(['dialogue_id'=>$dialogue_id]);

        return $this->_model->response();
    }

    public function findByName($name)
    {
        $this->_model->read(['name'=>$name]);

        return $this->_model->response();
    }

    public function save(DialogueType $dialogue)
    {
        $this->_model->write($dialogue);

        return $this->_model->response();
    }

    public function remove($dialogue_id)
    {
        $this->_model->delete(['dialogue_id'=>$dialogue_id]);
    }
}