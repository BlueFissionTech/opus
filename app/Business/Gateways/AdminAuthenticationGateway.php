<?php
namespace App\Business\Gateways;

class AdminAuthenticationGateway extends AuthenticationGateway {

	public $_redirectUri = '/admin';
}
