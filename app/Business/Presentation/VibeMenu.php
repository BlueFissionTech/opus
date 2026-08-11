<?php

declare(strict_types=1);

namespace App\Business\Presentation;

use App\Business\Services\VibeThemeRenderer;
use BlueFission\Arr;
use BlueFission\BlueCore\Menu;
use BlueFission\BlueCore\MenuItem;
use BlueFission\Str;

final class VibeMenu extends Menu
{
    private ?VibeThemeRenderer $renderer;

    public function __construct(
        string $label,
        ?string $theme = null,
        ?string $template = null,
        ?string $itemTemplate = null,
        ?VibeThemeRenderer $renderer = null
    ) {
        parent::__construct($label, $theme, $template, $itemTemplate);

        $this->renderer = $renderer;
    }

    public function addItem(MenuItem|Menu $item): void
    {
        if (!$item instanceof VibeMenuItem && !$item instanceof self) {
            throw new \InvalidArgumentException('Vibe menus only accept Vibe menu items and nested Vibe menus.');
        }

        if ($item instanceof VibeMenuItem && $this->_itemTemplate) {
            $item->setVibeTemplate((string) $this->_theme, (string) $this->_itemTemplate);
        }

        if ($this->renderer && ($item instanceof VibeMenuItem || $item instanceof self)) {
            $item->useRenderer($this->renderer);
        }

        $this->_items[$item->getId()] = $item;
    }

    public function useRenderer(VibeThemeRenderer $renderer): void
    {
        $this->renderer = $renderer;

        Arr::make($this->_items)->each(
            static function (MenuItem|Menu $item) use ($renderer): void {
                if ($item instanceof VibeMenuItem || $item instanceof self) {
                    $item->useRenderer($renderer);
                }
            }
        );
    }

    public function render(): string
    {
        $children = Arr::toArray(Arr::make($this->_items)->map(
            static fn (MenuItem|Menu $item): string => $item->render()
        ), true);

        $renderer = $this->renderer ?? instance('template');

        return $renderer->render(
            (string) $this->_theme,
            (string) $this->_template,
            [
                'id' => $this->_id,
                'label' => $this->_label,
                'children' => Str::concat('', ...$children),
            ],
            ['children'],
            ['id', 'label', 'children']
        );
    }
}
