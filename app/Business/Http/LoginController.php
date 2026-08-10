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
        return instance('template')->render('default', 'login.vibe', ['url' => '/login'], [], ['url']);
    }

    public function registration( )
    {
        return instance('template')->render('admin', 'register.vibe');
    }

    public function forgotPassword( )
    {
        return instance('template')->render('admin', 'forgotpassword.vibe');
    }
}
