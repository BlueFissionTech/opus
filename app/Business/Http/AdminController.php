<?php
namespace App\Business\Http;

use BlueFission\Services\Service;
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
            // Generated navigation crosses the trusted-markup boundary explicitly.
            return instance('template')->render(
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
            return template(
                'admin',
                'login.vibe',
                [
                    'csrfToken' => store('_token'),
                    'appName' => env('APP_NAME'),
                    'url' => '/admin',
                ]
            );
        }
    }

    public function dashboard( ) 
    {
        return template('admin', 'panels/dashboard.vibe');
    }

    public function users( ) 
    {
        return template('admin', 'panels/users.vibe', ['realname' => 'System Admin']);
    }

    public function addons( ) 
    {
        return template('admin', 'panels/addons.vibe');
    }

    public function content( ) 
    {
        return template('admin', 'panels/content.vibe');
    }

    public function terminal( ) 
    {
        return template('admin', 'panels/terminal.vibe');
    }

    public function registration( ) 
    {
        return template('admin', 'register.vibe');
    }

    public function forgotpassword( ) 
    {
        return template('admin', 'forgotpassword.vibe');
    }
}
