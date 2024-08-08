<?php
namespace App\Business\Http\Api\Admin;

use BlueFission\Services\Service;
use BlueFission\Services\Request;
use BlueFission\Connections\Database\MySQLLink;
use App\Domain\User\Models\CredentialModel;
use App\Domain\User\Repositories\IUserRepository;
use App\Domain\User\Queries\IAllUsersQuery;
use App\Domain\User\Queries\IAllCredentialStatusesQuery;
use App\Domain\User\User;

class UserController extends Service {

    public function __construct( MySQLLink $link )
    {
        parent::__construct();
        $link->open();
    }

    public function index( IAllUsersQuery $query ) {

        $users = $query->fetch();
        return response($users);
    }

    public function find( $user_id, IUserRepository $repository ) {
        $user = $repository->find($user_id);
        return response($user);
    }

	public function save( Request $request, IUserRepository $repository )
    {
        // Create new user model
        $user = new User;
        $user->user_id = $request->user_id;
        $user->realname = $request->realname;
        $user->displayname = $request->displayname;

        // Save the new user
        $response = $repository->save($user);

        // Return the id
        return response($response);
    }

    public function updateCredentials( Request $request, MySQLLink $link )
    {
        // $credential->credential_id = $request->credential_id;
        // $credential->read();

        $password = $request->password;
        $password_confirm = $request->password_confirm;

        if ($password != $password_confirm) {
            return response("Passwords do not match");
        }
        
        // $credential->password = password_hash($password, PASSWORD_DEFAULT);
        // $credential->credential_status_id = $request->credential_status_id;

        // $credential->write();

        $password = password_hash($password, PASSWORD_DEFAULT);
        $credential_id = $request->credential_id;

        $link->query("UPDATE `credentials` SET `password` = '".$password."' where `credential_id` = '".$credential_id."';");
        return response($link->status());
    }

    public function credentialStatuses( IAllCredentialStatusesQuery $query )
    {
        $credentialStatuses = $query->fetch();
        return response($credentialStatuses);
    }
}