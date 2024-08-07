<?php

namespace App\Business\Managers;

use BlueFission\Connections\Database\MysqlLink;
use BlueFission\Services\Service;
use BlueFission\Utils\Loader;
use BlueFission\BlueCore\Domain\AddOn\Models\AddOnModel;
use BlueFission\BlueCore\Domain\AddOn\AddOn;
use BlueFission\DevString;
use BlueFission\System\System;

class AddOnManager extends Service
{
    private $_loader;
    protected $_model;

    public function __construct(MysqlLink $link)
    {
        $link->open();
        $this->_model = new AddOnModel();
        $this->_loader = Loader::instance();
        $this->autoload();
        parent::__construct();
    }

    public function install($name)
    {
        $data = $this->getAddOnData($name);
        $status = '';
        if ($data->libraries) {
            $system = new System();
            $system->cwd(OPUS_ROOT);
            foreach ($data->libraries as $library) {
                $system->run("composer require {$library}");
                $status .= $system->response();
            }
        }
        $addon = new AddOn;
        $addon->assign($data);
        $addon->path = OPUS_ROOT.'addons' . DIRECTORY_SEPARATOR . $name;

        $datasource = instance('datasource');
        $datasource->setDeltaDirectory($addon->path . DIRECTORY_SEPARATOR . 'datasources' . DIRECTORY_SEPARATOR . 'structure' . DIRECTORY_SEPARATOR);
        $datasource->setGeneratorDirectory($addon->path . DIRECTORY_SEPARATOR . 'datasources' . DIRECTORY_SEPARATOR . 'generator' . DIRECTORY_SEPARATOR);
        ob_start();
        $datasource->runMigrations();
        $datasource->populate();
        $status .= ob_get_contents();
        ob_end_clean();

        $this->callHook($addon, 'install');
        $this->_model->write($addon);
        return $status . $this->_model->status() . $this->_model->query();
    }

    public function uninstall($addOnId)
    {
        $addon = $this->getAddOnById($addOnId);

        // Check for and call the `uninstall` function hook
        $this->callHook($addon, 'uninstall');

        $this->_model->delete(['addon_id' => $addOnId]);

        $data = $this->getAddOnData($addon->name);
        $status = '';

        if ($data->libraries) {
            $system = new System();
            $system->cwd(OPUS_ROOT);
            foreach ($data->libraries as $library) {
                $system->run("composer remove {$library}");
                $status .= $system->response();
            }
        }

        $datasource = instance('datasource');
        $datasource->setDeltaDirectory($addon->path . DIRECTORY_SEPARATOR . 'datasources' . DIRECTORY_SEPARATOR . 'structure' . DIRECTORY_SEPARATOR);
        $datasource->setGeneratorDirectory($addon->path . DIRECTORY_SEPARATOR . 'datasources' . DIRECTORY_SEPARATOR . 'generator' . DIRECTORY_SEPARATOR);
        ob_start();
        $datasource->revertMigrations();
        $status .= ob_get_contents();
        ob_end_clean();

        return $status . $this->_model->status();
    }

    public function activate($addOnId)
    {
        $this->_model->write(['addon_id' => $addOnId, 'is_active' => 1]);
        die($this->_model->status());
    }

    public function deactivate($addOnId)
    {
        $this->_model->write(['addon_id' => $addOnId, 'is_active' => 0]);
    }

    public function showAllAddOns()
    {
        $addOns = array_values(array_diff(scandir(OPUS_ROOT.'addons'), ['.', '..']));
        $list = [];
        foreach ($addOns as $addOn) {
            $data = json_decode(file_get_contents(OPUS_ROOT.'addons' . DIRECTORY_SEPARATOR . $addOn  . DIRECTORY_SEPARATOR . 'definition.json'));
            $model = new AddOn;
            $model->name = $data->name ?? $addOn;
            $model->description = DevString::truncate($data->description ?? "");
            $model->path = OPUS_ROOT.'addons' . DIRECTORY_SEPARATOR . $addOn;
            $model->primaryFile = 'main.php';
            // $addOn = $model;
            $list[$data->name] = $model;
        }

        return $list;
    }

    public function loadActivatedAddOns()
    {
        $addOns = $this->_model->getActivatedAddOns();
        
        foreach ($addOns as $addOn) {
            $object = new AddOn;
            $object->assign($addOn);
            // $object->path = OPUS_ROOT.'addons' . DIRECTORY_SEPARATOR . $addOn;
            // $object->primary_file = 'main.php';
            $addOn = $object;
            $this->loadAddOn($addOn);
        }
    }

    protected function loadAddOn(AddOn $addOn)
    {
        $primaryFile = $addOn->path . DIRECTORY_SEPARATOR . $addOn->primary_file;
        // $primaryFile = OPUS_ROOT.'addons' . DIRECTORY_SEPARATOR . $addon . DIRECTORY_SEPARATOR . 'main.php';
        if ($primaryFile != DIRECTORY_SEPARATOR && file_exists($primaryFile)) {
            require_once($primaryFile);
        }
    }

    protected function callHook(AddOn $addOn, $hook)
    {
        $primaryFile = $addOn->path . DIRECTORY_SEPARATOR . $addOn->primary_file;
        if (file_exists($primaryFile)) {
            require_once($primaryFile);

            $hookFunction = "{$addOn->name}_{$hook}";
            if (function_exists($hookFunction)) {
                $hookFunction();
            }
        }
    }

    public function uploadAddonFile($file, $destination)
    {
        $fileSystem = new FileSystem();
        $fileSystem->upload($file, $destination);
    }

    public function moveAddonFile($source, $destination)
    {
        $fileSystem = new FileSystem();
        $fileSystem->move($source, $destination);
    }

    public function copyAddonFile($source, $destination)
    {
        $fileSystem = new FileSystem();
        $fileSystem->copy($source, $destination);
    }

    public function deleteAddonFile($path)
    {
        $fileSystem = new FileSystem();
        $fileSystem->delete($path);
    }

    public function deleteAddonDirectory($path)
    {
        $fileSystem = new FileSystem();
        $fileSystem->deleteDirectory($path);
    }

    protected function getAddOnById($addOnId)
    {
        $data = $this->_model->read(['addon_id'=>$addOnId]);
        $addon = new AddOn;
        $addon->assign($data->data());
        return $addon;
    }

    protected function getAddOnData($name)
    {
        $data = json_decode(file_get_contents(OPUS_ROOT.'addons' . DIRECTORY_SEPARATOR . $name  . DIRECTORY_SEPARATOR . 'definition.json'));
        return $data;
    }

    protected function autoload()
    {
        spl_autoload_register(function ($class) {
            // TODO: clean this up and add validation
            $file = str_replace('\\', DIRECTORY_SEPARATOR, $class).'.php';
            $path = explode(DIRECTORY_SEPARATOR, $file);
            if ( count($path) > 2 ) {
                $path[0] = strtolower($path[0]);
                $path[1] = strtolower($path[1]);

                array_splice( $path, 2, 0, 'logic' ); // splice in at position 2

                $file = implode(DIRECTORY_SEPARATOR, $path);
                $file = OPUS_ROOT.$file;
            }
            if (file_exists($file)) {
                require_once($file);
                return true;
            }
            return false;
        });
    }
}
