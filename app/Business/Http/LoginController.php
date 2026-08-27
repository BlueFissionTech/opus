<?php
namespace App\Business\Http;

use App\Business\Presentation\VibeValue;
use BlueFission\Services\Service;
class LoginController extends Service {

	public function index( ) 
    {
        // Whatever is there to do here?
	}

    public function login( )
    {
        return template('default', 'login.vibe', ['url' => VibeValue::url('/login')]);
    }

    public function registration( )
    {
        return template('admin', 'register.vibe');
    }

    public function forgotPassword( )
    {
        return template('admin', 'forgotpassword.vibe');
    }
}
