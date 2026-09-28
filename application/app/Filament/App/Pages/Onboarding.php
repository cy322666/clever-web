<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Support\Crm\CrmProvider;
use App\Support\Onboarding\IndustryProfile;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class Onboarding extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'start';

    protected static ?string $title = 'Настройка платформы';

    protected string $view = 'filament.app.pages.onboarding';

    public string $language = 'ru';

    public string $crm = CrmProvider::AMOCRM;

    public string $industry = '';

    public function mount(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $this->language = in_array($user->locale, ['ru', 'en'], true) ? $user->locale : 'ru';
        $this->crm = CrmProvider::normalize($user->crm_provider);
        $this->industry = (string) $user->industry;
    }

    public function complete(): void
    {
        $data = $this->validate([
            'language' => ['required', Rule::in(['ru', 'en'])],
            'crm' => ['required', Rule::in([CrmProvider::AMOCRM, CrmProvider::KOMMO])],
            'industry' => ['required', Rule::in(array_keys(IndustryProfile::options()))],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill([
            'locale' => $data['language'],
            'crm_provider' => $data['crm'],
            'industry' => $data['industry'],
            'onboarding_completed_at' => now(),
        ])->saveQuietly();

        app()->setLocale($data['language']);

        $this->redirect(Dashboard::getUrl(), navigate: true);
    }

    /** @return array<string, array{ru: string, en: string}> */
    public function industries(): array
    {
        return IndustryProfile::options();
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::ThreeExtraLarge;
    }
}
