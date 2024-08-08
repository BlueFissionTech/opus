<?php
use BlueFission\BlueCore\Datasource\Generator;
use BlueFission\Str;

use BlueFission\BlueCore\Domain\Communication\Models\CommunicationTypeModel;
use BlueFission\BlueCore\Domain\Communication\CommunicationType;
use BlueFission\BlueCore\Domain\Communication\Models\CommunicationStatusModel;
use BlueFission\BlueCore\Domain\Communication\CommunicationStatus;
use BlueFission\BlueCore\Domain\Communication\Models\CommunicationChannelModel;
use BlueFission\BlueCore\Domain\Communication\CommunicationChannel;

class InitialCommunicationData extends Generator
{
	public function populate() {
		$statuses = [
			'Unverified'=>CommunicationStatus::UNSENT,
			'Verified'=>CommunicationStatus::SENT,
			'Expired'=>CommunicationStatus::DELIVERED,
			'Invalid'=>CommunicationStatus::READ,
		];

		$status = new CommunicationStatusModel();
		foreach ( $statuses as $label=>$name ) {
			$status->clear();
			$status->name = $name; //strtolower($label);
			$status->label = $label;
			$status->write();

			echo "Creating communication status: {$status->label} ";
			echo $status->status()."\n";
		}

		$status->clear();
		$status->name = CommunicationStatus::UNSENT;
		$status->read();

		$types = [
		    // User to System
		    'User Request' => CommunicationType::USER_REQUEST,
		    'User Feedback' => CommunicationType::USER_FEEDBACK,
		    'User Error Report' => CommunicationType::USER_ERROR_REPORT,

		    // System to User
		    'System Response' => CommunicationType::SYSTEM_RESPONSE,
		    'System Prompt' => CommunicationType::SYSTEM_PROMPT,
		    'System Error' => CommunicationType::SYSTEM_ERROR,
		    'System Success' => CommunicationType::SYSTEM_SUCCESS,

		    // User to User
		    'User Direct Message' => CommunicationType::USER_DIRECT_MESSAGE,
		    'User Group Message' => CommunicationType::USER_GROUP_MESSAGE,
		    'User Mention' => CommunicationType::USER_MENTION,

		    // System to System
		    'System Internal Info' => CommunicationType::SYSTEM_INTERNAL_INFO,
		    'System Internal Update' => CommunicationType::SYSTEM_INTERNAL_UPDATE,
		    'System Internal Warning' => CommunicationType::SYSTEM_INTERNAL_WARNING,
		    'System Internal Error' => CommunicationType::SYSTEM_INTERNAL_ERROR,
		];

		$type = new CommunicationTypeModel();
		foreach ( $types as $label=>$name ) {
			$type->clear();
			$type->name = $name;
			$type->label = $label;
			$type->write();

			echo "Creating communication type: {$type->label} ";
			echo $type->status()."\n";
		}

		$channels = [
			'BotMan',
			'HTTP',
			'Phone',
			'SMS',
			'Email',
		];

		$channel = new CommunicationChannelModel();
		foreach ( $channels as $label ) {
			$channel->clear();
			$channel->name = slugify($label);
			$channel->label = $label;
			$channel->write();

			echo "Creating communication channel: {$channel->label} ";
			echo $channel->status()."\n";
		}

		echo "Complete.\n";
	}
}