<?php

namespace App\Filament\App\Auth;

use App\Support\Filament\PanelRedirect;
use Filament\Facades\Filament;

class Register extends \Filament\Auth\Pages\Register
{
    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->to(PanelRedirect::intendedOrDashboard(request()));

            return;
        }

        parent::mount();
    }
}
