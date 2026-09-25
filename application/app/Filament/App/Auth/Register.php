<?php

namespace App\Filament\App\Auth;

use App\Support\Auth\AccountEmail;
use App\Support\Filament\PanelRedirect;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;

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

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->rule(AccountEmail::rule())
            ->mutateStateForValidationUsing(fn (mixed $state): string => AccountEmail::normalize($state))
            ->dehydrateStateUsing(fn (mixed $state): string => AccountEmail::normalize($state));
    }
}
