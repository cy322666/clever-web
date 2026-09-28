<x-filament-widgets::widget class="clever-market-widget">
    <div class="clever-market">
        @foreach($sections as $category => $section)
            @if($section['cards']->isNotEmpty())
                <section class="clever-market-section" aria-labelledby="market-{{ $category }}" data-category="{{ $category }}">
                    <header class="clever-market-section__header">
                        <h2 id="market-{{ $category }}">{{ $section['title'] }}</h2>
                    </header>
                    <ul class="clever-market-grid" role="list">
                        @foreach($section['cards'] as $card)
                            <li wire:key="market-app-{{ $card['id'] }}">
                                <a class="clever-market-card" href="{{ $card['url'] }}">
                                    <div class="clever-market-card__heading">
                                        <span class="clever-market-card__icon" aria-hidden="true">
                                            <x-filament::icon :icon="$card['icon']" />
                                        </span>
                                        <h3>{{ $card['title'] }}</h3>
                                    </div>
                                    <div class="clever-market-card__footer">
                                        @if(filled($card['status']))
                                            <x-filament::badge :color="$card['color']">{{ $card['status'] }}</x-filament::badge>
                                        @endif
                                        <span class="clever-market-card__action">{{ $card['action'] }} <x-filament::icon icon="heroicon-m-arrow-right" aria-hidden="true" /></span>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach

        @if($isEmpty)
            <div class="clever-market-empty">
                <x-filament::icon icon="heroicon-o-puzzle-piece" />
                <h2>Для {{ $crmLabel }} пока нет интеграций</h2>
                <p>Несовместимые виджеты скрыты. Вы можете изменить CRM в настройках платформы.</p>
                <x-filament::button tag="a" :href="$settingsUrl" wire:navigate>Изменить CRM</x-filament::button>
            </div>
        @endif
    </div>

    @once
        <style>
            .fi-wi-widget.clever-market-widget, .dark .fi-wi-widget.clever-market-widget {background: transparent !important; box-shadow: none !important;}
            .clever-market {
                --market-surface: #fff;
                --market-border: #e7e5e4;
                --market-text: #292524;
                --market-muted: #78716c;
                --market-accent: #c2410c;
                --market-icon-surface: #fff1e7;
                display: grid;
                gap: 2rem;
                min-width: 0;
                color: var(--market-text);
            }
            .clever-market-card__action svg {width: 1rem; height: 1rem; flex-shrink: 0;}
            .clever-market-section {min-width: 0;}
            .clever-market-section__header {margin-bottom: 1rem;}
            .clever-market-section__header h2 {font-size: 1.125rem; font-weight: 700;}
            .clever-market-grid {display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem;}
            .clever-market-grid > li {min-width: 0;}
            .clever-market-card {display: flex; flex-direction: column; gap: 1.25rem; height: 100%; min-width: 0; padding: 1.125rem; border: 1px solid var(--market-border); border-radius: .875rem; background: var(--market-surface); box-shadow: 0 2px 4px rgb(28 25 23 / 3%);}
            .clever-market-card:hover {border-color: #fb923c;}
            .clever-market-card:focus-visible {outline: 2px solid #ea580c; outline-offset: 3px;}
            .clever-market-card__heading {display: flex; align-items: center; gap: .875rem; flex: 1; min-width: 0;}
            .clever-market-card__icon {display: grid; place-items: center; flex-shrink: 0; width: 3rem; height: 3rem; border-radius: .75rem; color: var(--market-accent); background: var(--market-icon-surface);}
            .clever-market-card__icon svg {width: 1.5rem; height: 1.5rem;}
            .clever-market-card h3 {font-size: 1.0625rem; line-height: 1.4; font-weight: 650; overflow-wrap: anywhere;}
            .clever-market-card__footer {display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; min-height: 1.625rem;}
            .clever-market-card__action {display: inline-flex; align-items: center; gap: .375rem; margin-inline-start: auto; color: var(--market-accent); font-size: .8125rem; font-weight: 650;}
            .clever-market-empty {display: grid; justify-items: center; gap: 1rem; padding: 3rem 1.5rem; border: 1px solid var(--market-border); border-radius: 1rem; background: var(--market-surface); text-align: center;}
            .clever-market-empty > svg {width: 2rem; height: 2rem; color: var(--market-muted);}
            .clever-market-empty h2 {font-weight: 700;}
            .clever-market-empty p {max-width: 30rem; color: var(--market-muted); font-size: .875rem; line-height: 1.6;}
            .dark .clever-market {--market-surface: #211f1d; --market-border: #44403c; --market-text: #fafaf9; --market-muted: #a8a29e; --market-accent: #fb923c; --market-icon-surface: #38291e;}
            @media (max-width: 1100px) {.clever-market-grid {grid-template-columns: repeat(2, minmax(0, 1fr));}}
            @media (max-width: 640px) {
                .clever-market-grid {grid-template-columns: minmax(0, 1fr);}
            }
        </style>
    @endonce
</x-filament-widgets::widget>
