<?php

namespace App\Http\Responses\Filament;

use App\Support\Filament\PanelRedirect;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as Responsable;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->to(PanelRedirect::intendedOrDashboard($request));
    }
}
