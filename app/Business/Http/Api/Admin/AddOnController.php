<?php
namespace App\Business\Http\Api\Admin;

use BlueFission\Services\Service;
use BlueFission\Services\Request;
use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Business\Managers\AddOnManager;
use BlueFission\BlueCore\Domain\AddOn\Queries\IAllAddOnsQuery;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\BlueCore\Domain\AddOn\Repositories\IAddOnRepository;
use BlueFission\BlueCore\Domain\AddOn\AddOn;
use BlueFission\BlueCore\Domain\AddOn\Models\AddOnModel;

class AddOnController extends Service {

    public function index( IAllAddOnsQuery $query ) {
        $installedAddons = $query->fetch();
        $manager = instance('addons');
        $addons = $manager->showAllAddOns();
        foreach ($installedAddons as $addon)
        {
            $addons[$addon['name']]->addon_id = $addon['addon_id'];
            $addons[$addon['name']]->is_active = $addon['is_active'];
        }

        $list = array_values($addons);

        return response($list);
    }

    public function find( $addon_id, IAddOnRepository $repository ) {
        $addon = $repository->find($addon_id);
        return response($addon);
    }

    public function save( Request $request, IAddOnRepository $repository )
    {
        // Create new addon model
        $addon = new AddOn;
        $addon->addon_id = $request->addon_id;
        $addon->name = $request->name;
        $addon->description = $request->description;
        $addon->status = $request->status;

        // Save the new addon
        $response = $repository->save($addon);

        // Return the id
        return response($response);
    }

    public function update( Request $request, IAddOnRepository $repository )
    {
        return $this->save($request, $repository);
    }

    public function install( Request $request )
    {
        $manager = instance('addons');
        $status = $manager->install($request->name);

        return $status;
    }

    public function uninstall( Request $request )
    {
        $manager = instance('addons');
        $status = $manager->uninstall($request->addon_id);

        return $status;
    }

    public function activate( Request $request )
    {
        $manager = instance('addons');
        if ($request->is_active == 1) {
            $status = $manager->deactivate($request->addon_id);
        } else {
            $status = $manager->activate($request->addon_id);
        }

        return $status;
    }
}