<?php
use BlueFission\Services\Mapping;
use BlueFission\Net\HTTP;

Mapping::add('/', function() {
	return template('default', 'default.html', ['title'=>"Welcome", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'index', 'get');

// Authentication
Mapping::add('/login', ['App\Business\Http\LoginController', 'login'], 'login', 'get');
Mapping::add('/register', ['App\Business\Http\LoginController', 'registration'], 'register', 'get');
Mapping::add('/forgotpassword', ['App\Business\Http\LoginController', 'forgotpassword'], 'forgotpassword', 'get');

// Admin
Mapping::add('/admin', ['App\Business\Http\AdminController', 'index'], 'admin', 'get');
Mapping::add('/admin/register', ['App\Business\Http\AdminController', 'registration'], 'admin.register', 'get');
Mapping::add('/admin/forgotpassword', ['App\Business\Http\AdminController', 'forgotpassword'], 'admin.forgotpassword', 'get');

Mapping::add('/admin/modules/dashboard', ['App\Business\Http\AdminController', 'dashboard'], 'admin.dashboard', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/users', ['App\Business\Http\AdminController', 'users'], 'admin.users', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/addons', ['App\Business\Http\AdminController', 'addons'], 'admin.addons', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/terminal', ['App\Business\Http\AdminController', 'terminal'], 'admin.terminal', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/content', ['App\Business\Http\AdminController', 'content'], 'admin.content', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/media', ['App\Business\Http\AdminController', 'media'], 'admin.media', 'get')->gateway('admin:auth');