<?php
namespace App\Business\Http;

use BlueFission\Services\Service;
use BlueFission\Services\Request;

class LoginController extends Service {

	public function index( ) 
    {
        // Whatever is there to do here?
	}

    public function login( )
    {
        return template('app/ezdatta', 'login.html', ['app_name'=>env('APP_NAME')]);
    }

    public function registration( )
    {
        return template('default', 'register.html');
    }

    public function forgotPassword( )
    {
        return template('default', 'forgotpassword.html');
    }
}