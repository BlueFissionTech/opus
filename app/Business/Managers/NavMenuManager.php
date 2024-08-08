<?php
namespace App\Business\Managers;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\Services\Service;
use BlueFission\BlueCore\Auth as Authenticator;

class NavMenuManager extends Service
{
    protected $_menus = [];
	protected $_authenticator;

    public function __construct(Authenticator $authenticator)
    {
        parent::__construct();
        $this->_authenticator = $authenticator;
    }

    public function registerMenu($menu)
    {
        // Ensure menu is not already registered
        if (!array_key_exists($menu->getId(), $this->_menus)) {
            $this->_menus[$menu->getId()] = $menu;
        }
        // $this->_menus[$menu->getName()] = $menu;
    }

    public function getMenu($menuId)
    {
        if (array_key_exists($menuId, $this->_menus)) {
            return $this->_menus[$menuId];
        }
        return null;
    }

    public function addMenuItem($menuId, $menuItem)
    {
        if (array_key_exists($menuId, $this->_menus)) {
            $this->_menus[$menuId]->addItem($menuItem);
        }
    }

    public function renderMenu(string $menuName)
    {
        $renderedItems = [];
        if (isset($this->_menus[$menuName])) {
            $menu = $this->_menus[$menuName];
            $menuItems = $menu->getItems();

            foreach ($menuItems as $item) {
                if ( $item instanceof MenuItem ) {
                    $requiredRole = $item->getRole();
                    $requiredGroup = $item->getGroup();
                    $requiredPermission = $item->getPermission();

                    // Check role, group, and permission against current user
                    if (
                        ($requiredRole && !$this->_authenticator->hasRole($requiredRole)) ||
                        ($requiredGroup && !$this->_authenticator->isInGroup($requiredGroup)) ||
                        ($requiredPermission && !$this->_authenticator->hasPermission($requiredPermission))
                    ) {
                        continue; // Skip this item if user does not meet requirements
                    }
                }

                // Render the menu item
                $renderedItems[] = $item->render();
            }
        }

        return implode("\n", $renderedItems);
    }

    public function displayMenuItemBasedOnRole($menuId, $itemId, $role)
    {
        if (array_key_exists($menuId, $this->_menus)) {
            $menuItem = $this->_menus[$menuId]->getItem($itemId);
            if ($menuItem->getRole() === $role) {
                return $menuItem->render();
            }
        }
        return '';
    }

    public function displayMenuItemBasedOnGroup($menuId, $itemId, $group)
    {
        if (array_key_exists($menuId, $this->_menus)) {
            $menuItem = $this->_menus[$menuId]->getItem($itemId);
            if ($menuItem && $menuItem->getGroup() === $group) {
                return $menuItem->render();
            }
        }
        return '';
    }

    public function displayMenuItemBasedOnPermission($menuId, $itemId, $permission)
    {
        if (array_key_exists($menuId, $this->_menus)) {
            $menuItem = $this->_menus[$menuId]->getItem($itemId);
            if ($menuItem && $menuItem->getPermission() === $permission) {
                return $menuItem->render();
            }
        }
        return '';
    }
}