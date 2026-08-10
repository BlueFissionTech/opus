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

        // die(var_dump($auth->isAuthenticated()));

        if ( $auth->isAuthenticated() ) {
            // globals('sideNav', $navMenuManager->renderMenu('sideNav'));
            $navMenuManager = instance('nav');
            $sideNav = $navMenuManager->renderMenu('sidebar');
            return instance('vibe.theme')->render(
                'admin',
                'default.vibe',
                [
                    'csrfToken' => store('_token'),
                    'sideNav' => $sideNav,
                    'appName' => env('APP_NAME'),
                    'title' => env('APP_NAME') . " Admin",
                    'url' => '/admin',
                ],
                ['sideNav'],
                ['csrfToken', 'sideNav', 'appName', 'title', 'url']
            );
        } else {
            return instance('vibe.theme')->render(
                'admin',
                'login.vibe',
                [
                    'csrfToken' => store('_token'),
                    'appName' => env('APP_NAME'),
                    'url' => '/admin',
                ],
                [],
                ['csrfToken', 'appName', 'url']
            );
        }
    }

    public function dashboard( ) 
    {
        return instance('vibe.theme')->render('admin', 'panels/dashboard.vibe');
    }

    public function users( ) 
    {
        return instance('vibe.theme')->render('admin', 'panels/users.vibe', ['realname' => 'System Admin']);
    }

    public function addons( ) 
    {
        return instance('vibe.theme')->render('admin', 'panels/addons.vibe');
    }

    public function content( ) 
    {
        return instance('vibe.theme')->render('admin', 'panels/content.vibe');
    }

    public function terminal( ) 
    {
        return instance('vibe.theme')->render('admin', 'panels/terminal.vibe');
    }

    public function registration( ) 
    {
        return instance('vibe.theme')->render('admin', 'register.vibe');
    }

    public function forgotpassword( ) 
    {
        return instance('vibe.theme')->render('admin', 'forgotpassword.vibe');
    }
}
