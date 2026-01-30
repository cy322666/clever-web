<x-filament-panels::page>
    <div class="mx-auto w-full max-w-5xl space-y-6">
        <div class="text-sm text-gray-500">
            <a href="{{ url('/catalog/widgets') }}" class="hover:underline">← Назад к виджетам</a>
        </div>

        <div class="flex items-start gap-4">
            @if($record->logo_url)
                <div class="h-20 w-20 shrink-0 overflow-hidden rounded-xl border border-gray-200 bg-gray-50">
                    <img src="{{ $record->logo_url }}" alt="{{ $record->title }}" class="h-full w-full object-cover">
                </div>
            @endif

            <div class="flex-1 space-y-2">
                <h1 class="text-2xl font-semibold text-gray-900">{{ $record->title }}</h1>

                <div class="flex flex-wrap items-center gap-3">
                    @if($record->pricing_type)
                        <span class="inline-flex items-center rounded-full border border-gray-200 px-2 py-0.5 text-xs text-gray-600">
                            {{ $record->pricing_type === 'free' ? 'Free' : 'Paid' }}
                            @if($record->pricing_type === 'paid' && $record->price_from_rub)
                                от {{ number_format((int)$record->price_from_rub, 0, '.', ' ') }} ₽
                            @endif
                            @if($record->trial_days)
                                · {{ $record->trial_days }} дней пробный период
                            @endif
                        </span>
                    @endif

                    @if($record->installs_count)
                        <span class="inline-flex items-center rounded-full border border-gray-200 px-2 py-0.5 text-xs text-gray-600">
                            {{ number_format((int)$record->installs_count, 0, '.', ' ') }} установок
                        </span>
                    @endif

                    @if($record->is_featured)
                        <span class="inline-flex items-center rounded-full border border-yellow-200 bg-yellow-50 px-2 py-0.5 text-xs text-yellow-800">
                            ★ Избранный
                        </span>
                    @endif
                </div>
            </div>
        </div>

        @if($record->excerpt)
            <div class="text-gray-700">
                {{ $record->excerpt }}
            </div>
        @endif

        @if($record->description)
            <div class="prose max-w-none">
                {!! $record->description !!}
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            @if($record->install_url)
                <a
                    href="{{ $record->install_url }}"
                    class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
                    target="_blank"
                    rel="noopener"
                >
                    Установить
                </a>
            @endif

            @if($record->website_url)
                <a
                    href="{{ $record->website_url }}"
                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    target="_blank"
                    rel="noopener"
                >
                    Сайт виджета
                </a>
            @endif

            @if($record->demo_vk_url)
                <a
                    href="{{ $record->demo_vk_url }}"
                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    target="_blank"
                    rel="noopener"
                >
                    Демо (VK)
                </a>
            @endif

            @if($record->demo_youtube_url)
                <a
                    href="{{ $record->demo_youtube_url }}"
                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    target="_blank"
                    rel="noopener"
                >
                    Видео демо
                </a>
            @endif
        </div>

        @if(!empty($record->tags))
            <div class="flex flex-wrap gap-2">
                @foreach($record->tags as $tag)
                    <span class="inline-flex items-center rounded-full border border-gray-200 px-2 py-0.5 text-xs text-gray-600">
                        {{ $tag }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
