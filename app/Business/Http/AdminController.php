<?php
namespace App\Business\Http;

use BlueFission\Services\Service;
use BlueFission\Services\Request;
use BlueFission\BlueCore\Auth as Authenticator;

use BlueFission\Data\Storage\Storage;

class AdminController extends Service {

	public function index( Storage $session, Storage $datasource ) 
    {
        $auth = new Authenticator( $session, $datasource );

        if ( $auth->isAuthenticated() ) {
            // globals('sideNav', $navMenuManager->renderMenu('sideNav'));
            $navMenuManager = instance('nav');
            $sideNav = $navMenuManager->renderMenu('sidebar');
            return template('admin', 'default.html', ['csrf_token'=>store('_token'), 'side-nav'=>$sideNav, 'title'=>env('APP_NAME')." Admin"]);
        } else {
            return template('admin', 'login.html', ['csrf_token'=>store('_token')]);
        }
    }

    public function dashboard( ) 
    {
        return template('admin', 'panels/dashboard.html');
    }

    public function users( ) 
    {
        return template('admin', 'panels/users.html', ['realname'=>'System Admin']);
    }

    public function addons( ) 
    {
        return template('admin', 'panels/addons.html');
    }

    public function content( ) 
    {
        return template('admin', 'panels/content.html');
    }

    public function terminal( ) 
    {
        return template('admin', 'panels/terminal.html');
    }

    public function registration( ) 
    {
        return template('admin', 'register.html');
    }

    public function forgotpassword( ) 
    {
        return template('admin', 'forgotpassword.html');
    }
}