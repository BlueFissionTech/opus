<?php
namespace App\Business\Console;

use BlueFission\Services\Service;
use BlueFission\Connections\Database\MySQLLink;
use App\Domain\User\Models\UserModel;
use App\Domain\User\Models\CredentialModel;

class UserManager extends Service {

	public function __construct( MySQLLink $link )
    {
    	$this->_link = $link;
		parent::__construct();
	}

	public function create()
	{
		$user = new UserModel($this->_link);
		$credential = new CredentialModel($this->_link);

		$user->realname = readline('Enter user real name: ');
		$user->displayname = readline('Enter a displayname: ');

		$credential->username = readline('Enter a username: ');
		$credential->password = readline('Enter a password: ');

		$credential->password = password_hash($credential->password, PASSWORD_DEFAULT);

		$user->write();
		$user->read();
		$credential->user_id = $user->id();
		$credential->write();

		print_r( $credential->status()  ."\n");
	}



	public function changePassword( $behavior, $username )
	{
		// $user = new UserModel($this->_link);
		$credential = new CredentialModel($this->_link);

		// $user->realname = readline('Enter user real name: ');
		// $user->displayname = readline('Enter a displayname: ');

		$credential->username = $username;
		$credential->read();

		if ( !$credential->id() ) {
			print_r("User not found.\n");
			$data = $credential->credential_id;
			return;
		}

		$credential->password = readline('Enter a new password: ');

		$credential->password = password_hash($credential->password, PASSWORD_DEFAULT);

		$credential->write();

		print_r( $credential->status() ."\n" );
	}
}