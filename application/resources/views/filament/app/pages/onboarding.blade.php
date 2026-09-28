@php
    $english = $language === 'en';
    $copy = $english ? [
        'subtitle' => 'Choose your language, CRM and industry to personalise the platform and integration recommendations.',
        'language' => 'Interface language',
        'crm' => 'Your CRM',
        'industry' => 'Business industry',
        'submit' => 'Finish setup',
        'saved' => 'You can change these choices later.',
        'choose' => 'Select your industry to continue.',
    ] : [
        'subtitle' => 'Выберите язык, CRM и сферу бизнеса. Подстроим интерфейс и рекомендации в магазине.',
        'language' => 'Язык интерфейса',
        'crm' => 'Ваша CRM',
        'industry' => 'Сфера бизнеса',
        'submit' => 'Завершить настройку',
        'saved' => 'Позже выбор можно изменить.',
        'choose' => 'Выберите сферу бизнеса, чтобы продолжить.',
    ];
@endphp

<x-filament-panels::page>
    <form wire:submit="complete" class="clever-onboarding-form">
        <p class="clever-onboarding-intro">{{ $copy['subtitle'] }}</p>

        <fieldset>
            <legend><span aria-hidden="true">1</span>{{ $copy['language'] }}</legend>
            <div class="clever-choice-grid">
                @foreach(['ru' => 'Русский', 'en' => 'English'] as $value => $label)
                    <label>
                        <input type="radio" name="language" wire:model.live="language" value="{{ $value }}" @checked($language === $value) required>
                        <strong>{{ $label }}</strong>
                    </label>
                @endforeach
            </div>
            @error('language') <p class="clever-onboarding-error" role="alert">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset>
            <legend><span aria-hidden="true">2</span>{{ $copy['crm'] }}</legend>
            <div class="clever-choice-grid">
                @foreach(['amocrm' => ['amoCRM', 'amocrm.ru'], 'kommo' => ['Kommo', 'kommo.com']] as $value => [$label, $domain])
                    <label>
                        <input type="radio" name="crm" wire:model.live="crm" value="{{ $value }}" @checked($crm === $value) required>
                        <span class="clever-choice-text"><strong>{{ $label }}</strong><small>{{ $domain }}</small></span>
                    </label>
                @endforeach
            </div>
            @error('crm') <p class="clever-onboarding-error" role="alert">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset>
            <legend><span aria-hidden="true">3</span>{{ $copy['industry'] }}</legend>
            <div class="clever-choice-grid is-industries">
                @foreach($this->industries() as $value => $labels)
                    <label>
                        <input type="radio" name="industry" wire:model.live="industry" value="{{ $value }}" @checked($industry === $value) required>
                        <strong>{{ $labels[$language] ?? $labels['ru'] }}</strong>
                    </label>
                @endforeach
            </div>
            @error('industry') <p class="clever-onboarding-error" role="alert">{{ $message }}</p> @enderror
        </fieldset>

        <div class="clever-onboarding-submit">
            <p aria-live="polite">{{ $industry === '' ? $copy['choose'] : $copy['saved'] }}</p>
            <x-filament::button type="submit" :disabled="$industry === ''" wire:loading.attr="disabled" wire:target="complete" icon="heroicon-m-arrow-right" icon-position="after">
                <span wire:loading.remove wire:target="complete">{{ $copy['submit'] }}</span>
                <span wire:loading wire:target="complete">{{ $english ? 'Saving...' : 'Сохраняем...' }}</span>
            </x-filament::button>
        </div>
    </form>

    @once
        <style>
            .clever-onboarding-form {
                --setup-surface: #fff;
                --setup-border: #e7e5e4;
                --setup-text: #292524;
                --setup-muted: #78716c;
                --setup-selected: #fff7ed;
                display: grid;
                gap: 1.75rem;
                min-width: 0;
                padding: clamp(1rem, 3vw, 2rem);
                border: 1px solid var(--setup-border);
                border-radius: 1rem;
                background: var(--setup-surface);
                color: var(--setup-text);
                box-shadow: 0 2px 6px rgb(28 25 23 / 4%);
            }
            .clever-onboarding-intro {max-width: 38rem; color: var(--setup-muted); font-size: .9375rem; line-height: 1.6;}
            .clever-onboarding-form fieldset {min-width: 0;}
            .clever-onboarding-form legend {display: flex; align-items: center; gap: .625rem; margin-bottom: .75rem; font-size: 1rem; font-weight: 700;}
            .clever-onboarding-form legend > span {display: inline-grid; place-items: center; flex-shrink: 0; width: 1.625rem; height: 1.625rem; border: 1px solid var(--setup-border); border-radius: 50%; color: var(--setup-muted); font-size: .75rem; font-weight: 600;}
            .clever-choice-grid {display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .625rem;}
            .clever-choice-grid.is-industries {grid-template-columns: repeat(3, minmax(0, 1fr));}
            .clever-choice-grid label {display: flex; align-items: center; gap: .625rem; min-width: 0; min-height: 3.25rem; padding: .75rem; border: 1px solid var(--setup-border); border-radius: .625rem; cursor: pointer;}
            .clever-choice-grid label:hover {border-color: #fb923c;}
            .clever-choice-grid label:has(input:checked) {border-color: #ea580c; background: var(--setup-selected);}
            .clever-choice-grid label:has(input:focus-visible) {outline: 2px solid #ea580c; outline-offset: 3px;}
            .clever-choice-grid input {appearance: auto; flex: 0 0 1rem; width: 1rem; height: 1rem; margin: 0; accent-color: #ea580c;}
            .clever-choice-grid strong {font-size: .875rem; font-weight: 600; overflow-wrap: anywhere;}
            .clever-choice-text {display: flex; flex-direction: column; gap: .125rem; min-width: 0;}
            .clever-choice-text small {color: var(--setup-muted); font-size: .75rem; overflow-wrap: anywhere;}
            .clever-onboarding-error {margin-top: .5rem; color: #dc2626; font-size: .8125rem;}
            .clever-onboarding-submit {display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-top: 1.25rem; border-top: 1px solid var(--setup-border);}
            .clever-onboarding-submit p {color: var(--setup-muted); font-size: .8125rem; line-height: 1.5;}
            .clever-onboarding-submit button {flex-shrink: 0; min-height: 2.75rem;}
            .dark .clever-onboarding-form {--setup-surface: #211f1d; --setup-border: #44403c; --setup-text: #fafaf9; --setup-muted: #a8a29e; --setup-selected: #34271d;}
            .dark .clever-choice-grid label:has(input:checked) {border-color: #fb923c;}
            .dark .clever-choice-grid input {accent-color: #fb923c;}
            .dark .clever-onboarding-error {color: #fca5a5;}
            @media (max-width: 640px) {
                .clever-choice-grid.is-industries {grid-template-columns: repeat(2, minmax(0, 1fr));}
                .clever-onboarding-submit {align-items: stretch; flex-direction: column;}
                .clever-onboarding-submit button {width: 100%;}
            }
            @media (max-width: 360px) {
                .clever-choice-grid.is-industries {grid-template-columns: minmax(0, 1fr);}
            }
        </style>
    @endonce
</x-filament-panels::page>
