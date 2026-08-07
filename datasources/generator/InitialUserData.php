<?php
use BlueFission\BlueCore\Datasource\Generator;
use BlueFission\BlueCore\Security;
use BlueFission\Str;

use App\Domain\User\Models\UserModel;
use App\Domain\User\Models\CredentialModel;
use App\Domain\User\Models\CredentialStatusModel;
use App\Domain\User\CredentialStatus;

class InitialUserData extends Generator
{
	public function populate($auto = false) {
		$statuses = [
			'Unverified'=>CredentialStatus::UNVERIFIED,
			'Verified'=>CredentialStatus::VERIFIED,
			'Expired'=>CredentialStatus::EXPIRED,
			'Invalid'=>CredentialStatus::INVALID,
		];

		$status = new CredentialStatusModel();
		foreach ( $statuses as $label=>$name ) {
			$status->clear();
			$status->name = $name;
			$status->read();

			if (!$status->id()) {
				$status->label = $label;
				$status->write();
			}

			echo "Ensuring status: {$label} ";
			echo $status->status()."\n";
		}

		$status->clear();
		$status->name = CredentialStatus::VERIFIED;
		$status->read();

		$user = new UserModel();
		$credential = new CredentialModel();
		$credential->username = 'admin';
		$credential->read();

		if ($credential->id()) {
			echo "Admin credentials already exist.\n";
			echo "Complete.\n";
			return;
		}

		$password = env('DEFAULT_PASSWORD', Str::rand(null, 16, true));
		if ( defined('STDIN') && !$auto ) {
			$password = prompt_silent("Enter an admin password: ");
		}

		$user->displayname = 'Admin';
		$user->read();
		if (!$user->id()) {
			$user->realname = 'System Admin';
			$user->displayname = 'Admin';
			$user->write();
		}
		echo "Ensuring Admin user: {$user->displayname} ";
		echo $user->status()."\n";

		$credential->clear();
		$credential->username = 'admin';
		$credential->password = $password;
		$credential->is_primary = 1;
		$credential->credential_status_id = $status->id();
		$credential->password = Security::createToken($credential->password);
		$credential->user_id = $user->id();

		$credential->write();
		echo "Saving credentials for {$credential->username} ";
		echo $credential->status()."\n";

		echo "Complete.\n";
	}
}
