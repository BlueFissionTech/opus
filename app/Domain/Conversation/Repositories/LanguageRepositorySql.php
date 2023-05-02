<?php
namespace App\Domain\Conversation\Repositories;

use BlueFission\Connections\Database\MysqlLink;
use BlueFission\Framework\Repository\RepositorySql;
use App\Domain\Conversation\Repositories\ILanguageRepository;
use App\Domain\Conversation\Models\LanguageModel as Model;
use App\Domain\Conversation\Language;

class LanguageRepositorySql extends RepositorySql implements ILanguageRepository
{
    protected $_name = "languages";

    public function __construct(MysqlLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($language_id)
    {
        $this->_model->read(['language_id'=>$language_id]);

        return $this->_model->response()['data'];
    }

    public function findByName($language_name)
    {
        $this->_model->read(['language_name'=>$language_name]);

        return $this->_model->response()['data'];
    }

    public function save(Language $language)
    {
        $this->_model->write($language);

        return $this->_model->response();
    }

    public function remove($language_id)
    {
        $this->_model->delete(['language_id'=>$language_id]);
    }
}