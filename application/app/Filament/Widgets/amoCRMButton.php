<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class amoCRMButton extends Widget
{
    /**
     * @param string $view
     */
    public static function setView(string $view): void
    {
        self::$view = 'filament.app.widgets.amocrm-button';
    }
}
