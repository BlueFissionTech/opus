<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Presentation;

use App\Business\Presentation\VibeMenu;
use App\Business\Presentation\VibeMenuItem;
use App\Business\Services\VibeThemeRenderer;
use BlueFission\BlueCore\MenuItem;
use PHPUnit\Framework\TestCase;

final class VibeMenuTest extends TestCase
{
    public function testItComposesEscapedNavigationItemsThroughVibePartials(): void
    {
        $themeDirectory = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'resource'
            . DIRECTORY_SEPARATOR . 'markup' . DIRECTORY_SEPARATOR . 'admin';
        $renderer = new VibeThemeRenderer(
            static fn (): object => (object) ['location' => $themeDirectory]
        );
        $menu = new VibeMenu(
            'Users',
            'admin',
            'sections/menu-top-item.vibe',
            'sections/menu-sub-item.vibe',
            $renderer
        );
        $menu->addItem(new VibeMenuItem('Manage <users>', '/admin/users?a=1&b=2'));

        $output = $menu->render();

        $this->assertStringContainsString('id="users"', $output);
        $this->assertStringContainsString('Manage &lt;users&gt;', $output);
        $this->assertStringContainsString('/admin/users?a=1&amp;b=2', $output);
        $this->assertStringNotContainsString('{$children}', $output);
    }

    public function testItRejectsLegacyMenuItemsAtTheCompositionBoundary(): void
    {
        $menu = new VibeMenu('Users', 'admin', 'sections/menu-top-item.vibe', 'sections/menu-sub-item.vibe');

        $this->expectException(\InvalidArgumentException::class);

        $menu->addItem(new MenuItem('Legacy', '/legacy'));
    }

    public function testItPropagatesAnInjectedRendererThroughNestedMenus(): void
    {
        $themeDirectory = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'resource'
            . DIRECTORY_SEPARATOR . 'markup' . DIRECTORY_SEPARATOR . 'admin';
        $renderer = new VibeThemeRenderer(
            static fn (): object => (object) ['location' => $themeDirectory]
        );
        $parent = new VibeMenu(
            'Administration',
            'admin',
            'sections/menu-top-item.vibe',
            'sections/menu-sub-item.vibe',
            $renderer
        );
        $child = new VibeMenu(
            'Users',
            'admin',
            'sections/menu-top-item.vibe',
            'sections/menu-sub-item.vibe'
        );
        $child->addItem(new VibeMenuItem('Manage users', '/admin/users'));
        $parent->addItem($child);

        $output = $parent->render();

        $this->assertStringContainsString('Administration', $output);
        $this->assertStringContainsString('Users', $output);
        $this->assertStringContainsString('Manage users', $output);
    }
}
