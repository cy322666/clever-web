@if ($blockedWidgets !== [])
    <x-filament-widgets::widget>
        <x-filament::section>
            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <x-filament::icon
                                icon="heroicon-o-lock-closed"
                                class="h-5 w-5 text-danger-500"
                            />
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                                Доступ ограничен
                            </h2>
                        </div>

                        <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                            Некоторые установленные виджеты отключены из-за окончания доступа. Отправьте заявку, и поддержка подготовит продление.
                        </p>
                    </div>

                    <x-filament::badge color="danger">
                        {{ count($blockedWidgets) }} отключено
                    </x-filament::badge>
                </div>

                <div class="grid gap-3">
                    @foreach ($blockedWidgets as $widget)
                        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-gray-950 dark:text-white">
                                            {{ $widget['title'] }}
                                        </span>

                                        <x-filament::badge color="danger">
                                            {{ $widget['status_label'] }}
                                        </x-filament::badge>
                                    </div>

                                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                                        @if ($widget['ends_at_label'])
                                            <span>Доступ до {{ $widget['ends_at_label'] }}</span>
                                        @endif

                                        @if ($widget['grace_until_label'])
                                            <span>Льготный период до {{ $widget['grace_until_label'] }}</span>
                                        @endif

                                        @if (! $widget['ends_at_label'] && ! $widget['grace_until_label'])
                                            <span>Доступ сейчас не активен</span>
                                        @endif
                                    </div>
                                </div>

                                <x-filament::button
                                    color="warning"
                                    icon="heroicon-o-document-plus"
                                    wire:click="requestInvoice('{{ $widget['widget'] }}')"
                                    wire:target="requestInvoice('{{ $widget['widget'] }}')"
                                >
                                    Запросить счет
                                </x-filament::button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-filament::section>
    </x-filament-widgets::widget>
@endif
