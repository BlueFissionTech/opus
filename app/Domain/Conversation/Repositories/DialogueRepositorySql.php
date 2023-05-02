<?php
namespace App\Domain\Conversation\Repositories;

use BlueFission\Connections\Database\MysqlLink;
use BlueFission\Framework\Repository\RepositorySql;
use App\Domain\Conversation\Repositories\IDialogueRepository;
use App\Domain\Conversation\Models\DialogueModel as Model;
use App\Domain\Conversation\Dialogue;

class DialogueRepositorySql extends RepositorySql implements IDialogueRepository
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

    public function search(Dialogue $dialogue)
    {
        $this->_model->condition('text', 'like', $dialogue->text);
        $this->_model->read($dialogue);

        return $this->_model->response();
    }

    public function save(Dialogue $dialogue)
    {
        $this->_model->write($dialogue);

        return $this->_model->response();
    }

    public function remove($dialogue_id)
    {
        $this->_model->delete(['dialogue_id'=>$dialogue_id]);
    }
}