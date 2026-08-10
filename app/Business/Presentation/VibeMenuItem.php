<?php

declare(strict_types=1);

namespace App\Business\Presentation;

use App\Business\Services\VibeThemeRenderer;
use BlueFission\BlueCore\MenuItem;

final class VibeMenuItem extends MenuItem
{
    private string $themeName = '';

    private string $vibeTemplate = '';

    private ?VibeThemeRenderer $renderer;

    public function __construct(
        string $label,
        string $action,
        ?string $role = null,
        ?string $group = null,
        ?string $permission = null,
        ?VibeThemeRenderer $renderer = null
    ) {
        parent::__construct($label, $action, $role, $group, $permission);

        $this->renderer = $renderer;
    }

    public function setVibeTemplate(string $themeName, string $template): void
    {
        $this->themeName = $themeName;
        $this->vibeTemplate = $template;
    }

    public function useRenderer(VibeThemeRenderer $renderer): void
    {
        $this->renderer = $renderer;
    }

    public function render(): string
    {
        if ($this->themeName === '' || $this->vibeTemplate === '') {
            throw new \LogicException('A Vibe menu item requires a theme and template.');
        }

        $renderer = $this->renderer ?? instance('vibe.theme');

        return $renderer->render($this->themeName, $this->vibeTemplate, [
            'id' => $this->_id,
            'label' => $this->_label,
            'action' => $this->_action,
        ], [], ['id', 'label', 'action']);
    }
}
