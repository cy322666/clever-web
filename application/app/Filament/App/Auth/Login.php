<?php

namespace App\Filament\App\Auth;

use App\Support\Filament\PanelRedirect;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;

class Login extends \Filament\Auth\Pages\Login
{
    public function mount(): void
    {
        Log::debug(__METHOD__);

        if (Filament::auth()->check()) {
            redirect()->to(PanelRedirect::intendedOrDashboard(request()));

            return;
        }

        parent::mount();
    }
}
