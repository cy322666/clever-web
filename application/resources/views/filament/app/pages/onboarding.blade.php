@php
    $english = $language === 'en';
    $copy = $english ? [
        'eyebrow' => 'Three quick choices',
        'title' => 'Set up CleverCRM for you',
        'subtitle' => 'We will use these answers for the interface, CRM connection and recommended integrations.',
        'language' => '1. Interface language',
        'crm' => '2. Your CRM',
        'industry' => '3. Business industry',
        'submit' => 'Finish setup',
        'saved' => 'You can change these choices later.',
    ] : [
        'eyebrow' => 'Три быстрых ответа',
        'title' => 'Настроим CleverCRM под вас',
        'subtitle' => 'Используем ответы для интерфейса, подключения CRM и рекомендаций в магазине.',
        'language' => '1. Язык интерфейса',
        'crm' => '2. Ваша CRM',
        'industry' => '3. Сфера бизнеса',
        'submit' => 'Завершить настройку',
        'saved' => 'Позже выбор можно изменить.',
    ];
@endphp

<x-filament-panels::page>
    <div class="clever-onboarding-shell">
        <div class="clever-onboarding-intro">
            <span>{{ $copy['eyebrow'] }}</span>
            <h1>{{ $copy['title'] }}</h1>
            <p>{{ $copy['subtitle'] }}</p>
        </div>

        <form wire:submit="complete" class="clever-onboarding-form">
            <fieldset>
                <legend>{{ $copy['language'] }}</legend>
                <div class="clever-choice-grid is-two">
                    <label @class(['is-selected' => $language === 'ru'])>
                        <input type="radio" wire:model.live="language" value="ru">
                        <strong>Русский</strong><small>RU</small>
                    </label>
                    <label @class(['is-selected' => $language === 'en'])>
                        <input type="radio" wire:model.live="language" value="en">
                        <strong>English</strong><small>EN</small>
                    </label>
                </div>
                @error('language') <p class="clever-onboarding-error">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset>
                <legend>{{ $copy['crm'] }}</legend>
                <div class="clever-choice-grid is-two">
                    <label @class(['is-selected' => $crm === 'amocrm'])>
                        <input type="radio" wire:model.live="crm" value="amocrm">
                        <strong>amoCRM</strong><small>amocrm.ru</small>
                    </label>
                    <label @class(['is-selected' => $crm === 'kommo'])>
                        <input type="radio" wire:model.live="crm" value="kommo">
                        <strong>Kommo</strong><small>kommo.com</small>
                    </label>
                </div>
                @error('crm') <p class="clever-onboarding-error">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset>
                <legend>{{ $copy['industry'] }}</legend>
                <div class="clever-choice-grid is-industries">
                    @foreach($this->industries() as $value => $labels)
                        <label @class(['is-selected' => $industry === $value])>
                            <input type="radio" wire:model.live="industry" value="{{ $value }}">
                            <strong>{{ $labels[$language] ?? $labels['ru'] }}</strong>
                        </label>
                    @endforeach
                </div>
                @error('industry') <p class="clever-onboarding-error">{{ $message }}</p> @enderror
            </fieldset>

            <div class="clever-onboarding-submit">
                <p>{{ $copy['saved'] }}</p>
                <button type="submit" @disabled($industry === '') wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ $copy['submit'] }}</span>
                    <span wire:loading>{{ $english ? 'Saving...' : 'Сохраняем...' }}</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" />
                </button>
            </div>
        </form>
    </div>

    @once
        <style>
            .clever-onboarding-shell{display:grid;grid-template-columns:minmax(17rem,.75fr) minmax(0,1.5fr);gap:1.25rem;padding:1.25rem;border:1px solid #e7e5e4;border-radius:1.4rem;background:linear-gradient(135deg,#fff 0%,#fffaf5 100%);box-shadow:0 22px 65px rgba(28,25,23,.08)}
            .clever-onboarding-intro{display:flex;flex-direction:column;justify-content:flex-end;min-height:33rem;padding:2rem;border-radius:1rem;color:#fff;background:radial-gradient(circle at 10% 10%,rgba(255,255,255,.18),transparent 34%),linear-gradient(145deg,#161412 0%,#37271d 64%,#d95400 160%)}
            .clever-onboarding-intro span{width:max-content;padding:.32rem .58rem;border:1px solid rgba(255,255,255,.22);border-radius:999px;font-size:.72rem;font-weight:750;letter-spacing:.04em;text-transform:uppercase}.clever-onboarding-intro h1{max-width:24rem;margin-top:1rem;font-size:clamp(2rem,4vw,3.4rem);font-weight:780;line-height:.98;letter-spacing:-.045em}.clever-onboarding-intro p{max-width:28rem;margin-top:1.1rem;color:#d6d3d1;font-size:.92rem;line-height:1.6}
            .clever-onboarding-form{display:grid;gap:1.25rem;padding:1rem}.clever-onboarding-form fieldset{display:grid;gap:.7rem}.clever-onboarding-form legend{margin-bottom:.7rem;color:#292524;font-size:.92rem;font-weight:760}.clever-choice-grid{display:grid;gap:.65rem}.clever-choice-grid.is-two{grid-template-columns:repeat(2,minmax(0,1fr))}.clever-choice-grid.is-industries{grid-template-columns:repeat(3,minmax(0,1fr))}.clever-choice-grid label{position:relative;display:flex;align-items:center;justify-content:space-between;gap:.65rem;min-height:4rem;padding:.85rem 1rem;border:1px solid #e7e5e4;border-radius:.85rem;background:#fff;cursor:pointer;transition:border-color .16s ease,transform .16s ease,box-shadow .16s ease}.clever-choice-grid label:hover{transform:translateY(-1px);border-color:#fdba74}.clever-choice-grid label.is-selected{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.13)}.clever-choice-grid input{position:absolute;opacity:0;pointer-events:none}.clever-choice-grid strong{font-size:.9rem}.clever-choice-grid small{color:#a8a29e;font-size:.7rem;font-weight:750}.clever-onboarding-error{color:#dc2626;font-size:.75rem}.clever-onboarding-submit{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding-top:.25rem}.clever-onboarding-submit p{color:#78716c;font-size:.76rem}.clever-onboarding-submit button{display:inline-flex;align-items:center;gap:.55rem;min-height:2.85rem;padding:0 1.15rem;border-radius:.75rem;background:#f97316;color:#fff;font-size:.84rem;font-weight:760;box-shadow:0 10px 24px rgba(234,88,12,.2)}.clever-onboarding-submit button:disabled{cursor:not-allowed;opacity:.45}.clever-onboarding-submit svg{width:1rem}
            .dark .clever-onboarding-shell{border-color:#3f3f46;background:linear-gradient(135deg,#27272a,#201c19)}.dark .clever-onboarding-form legend{color:#fafaf9}.dark .clever-choice-grid label{border-color:#44403c;background:#292524}.dark .clever-choice-grid label.is-selected{border-color:#fb923c}
            @media(max-width:900px){.clever-onboarding-shell{grid-template-columns:1fr}.clever-onboarding-intro{min-height:16rem}.clever-choice-grid.is-industries{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.clever-onboarding-shell{padding:.75rem}.clever-onboarding-form{padding:.3rem}.clever-choice-grid.is-two,.clever-choice-grid.is-industries{grid-template-columns:1fr}.clever-onboarding-submit{align-items:stretch;flex-direction:column}.clever-onboarding-submit button{justify-content:center;width:100%}}
        </style>
    @endonce
</x-filament-panels::page>
