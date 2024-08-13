<?php
use BlueFission\BlueCore\Menu;
use BlueFission\BlueCore\MenuItem;
use BlueFission\BlueCore\Business\Managers\NavMenuManager;

// Create a new instance of NavMenuManager
$navMenuManager = instance('nav');

// Create top nav menu and add items
$topNav = new Menu('Top Nav');
$topNav->addItem(new MenuItem('Home', '#'));
$topNav->addItem(new MenuItem('Terms', '#'));
$topNav->addItem(new MenuItem('Privacy', '#'));
$topNav->addItem(new MenuItem('Support', '#'));

// Register top nav menu
$navMenuManager->registerMenu($topNav);



// Create side nav menu and add items
$sideNav = new Menu('Sidebar', 'admin', 'sections/menu.html', 'sections/menu-item.html');
$sideNav->addItem(new MenuItem('Overview', 'dashboard'));

// Create users and addons menu items with 'admin' role
$usersMenu = new Menu('Users', 'admin', 'sections/menu-top-item.html', 'sections/menu-sub-item.html');
$usersItem = new MenuItem('Manage', 'users', 'admin');
$usersMenu->addItem($usersItem);
$sideNav->addItem($usersMenu);

$addonsItem = new MenuItem('AddOns', 'addons', null, null, 'elevated');
$sideNav->addItem($addonsItem);

$sideNav->addItem(new MenuItem('Content', 'content'));
$sideNav->addItem(new MenuItem('Terminal', 'terminal'));

// Register side nav menu
$navMenuManager->registerMenu($sideNav);

// Now you can use displayMenuItemBasedOnRole and displayMenuItemBasedOnPermission methods to control visibility of menu items

// echo $navMenuManager->displayMenuItemBasedOnRole('side_nav', 'users', 'admin'); // Only displays if user is an admin
// echo $navMenuManager->displayMenuItemBasedOnPermission('side_nav', 'addons', 'elevated'); // Only displays if user has 'elevated' permission
