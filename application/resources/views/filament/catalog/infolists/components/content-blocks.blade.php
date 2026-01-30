@php
    $record = $getRecord();
    $contentBlocks = $record->content_blocks ?? [];
@endphp

@if(!empty($contentBlocks))
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @foreach($contentBlocks as $block)
            @php
                $type = $block['type'] ?? 'text';
                $width = $block['width'] ?? 'half';
                // Если width = 'half', блок занимает 1 колонку (половина ширины - отображается в 2 колонки)
                // Если width = 'full', блок занимает 2 колонки (полная ширина - на всю ширину)
                $columnSpan = ($width === 'half') ? 'col-span-1' : 'col-span-1 md:col-span-2';
            @endphp

            <div class="{{ $columnSpan }}">
                <x-filament::section>
                @if(!empty($block['title']))
                    <x-slot name="heading">
                        {{ $block['title'] }}
                    </x-slot>
                @endif

                @switch($type)
                    @case('screenshot')
                        @if(!empty($block['image_url']))
                            <img src="{{ $block['image_url'] }}" alt="" class="w-full rounded-xl border border-gray-200">
                        @endif
                        @if(!empty($block['caption']))
                            <p class="mt-3 text-sm text-gray-600">
                                {!! nl2br(e($block['caption'])) !!}
                            </p>
                        @endif
                        @break

                    @case('metrics')
                        @if(!empty($block['items']) && is_array($block['items']))
                            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach($block['items'] as $item)
                                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                                        <div class="text-xl font-semibold text-gray-900">
                                            {{ $item['value'] ?? '' }}{{ $item['suffix'] ?? '' }}
                                        </div>
                                        @if(!empty($item['label']))
                                            <div class="text-sm text-gray-600">{{ $item['label'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @break

                    @case('list')
                        @if(!empty($block['list_items']) && is_array($block['list_items']))
                            <ul class="list-disc pl-5 text-gray-700">
                                @foreach($block['list_items'] as $item)
                                    <li>
                                        {{ is_array($item) ? ($item['text'] ?? '') : $item }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @break

                    @case('gallery')
                        @if(!empty($block['images']) && is_array($block['images']))
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach($block['images'] as $image)
                                    @if(!empty($image['url']))
                                        <div>
                                            <img src="{{ $image['url'] }}" alt="" class="w-full rounded-xl border border-gray-200">
                                            @if(!empty($image['caption']))
                                                <div class="mt-2 text-sm text-gray-600">
                                                    {{ $image['caption'] }}
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @break

                    @case('video')
                        @php
                            $videoUrl = $block['video_url'] ?? null;
                            $embedUrl = $videoUrl;
                            if (is_string($videoUrl)) {
                                if (str_contains($videoUrl, 'youtu.be/')) {
                                    $id = trim(parse_url($videoUrl, PHP_URL_PATH) ?? '', '/');
                                    $embedUrl = $id ? 'https://www.youtube.com/embed/' . $id : $videoUrl;
                                } elseif (str_contains($videoUrl, 'youtube.com/watch')) {
                                    parse_str(parse_url($videoUrl, PHP_URL_QUERY) ?? '', $query);
                                    $embedUrl = !empty($query['v']) ? 'https://www.youtube.com/embed/' . $query['v'] : $videoUrl;
                                } elseif (str_contains($videoUrl, 'vimeo.com/')) {
                                    $id = trim(parse_url($videoUrl, PHP_URL_PATH) ?? '', '/');
                                    $embedUrl = $id ? 'https://player.vimeo.com/video/' . $id : $videoUrl;
                                }
                            }
                        @endphp

                        @if(!empty($embedUrl))
                            <div class="aspect-video w-full overflow-hidden rounded-xl border border-gray-200">
                                <iframe
                                    src="{{ $embedUrl }}"
                                    class="h-full w-full"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                    allowfullscreen
                                ></iframe>
                            </div>
                        @endif
                        @if(!empty($block['video_caption']))
                            <p class="mt-3 text-sm text-gray-600">
                                {!! nl2br(e($block['video_caption'])) !!}
                            </p>
                        @endif
                        @break

                    @case('cta')
                        @if(!empty($block['cta_description']))
                            <p class="text-gray-700">
                                {!! nl2br(e($block['cta_description'])) !!}
                            </p>
                        @endif
                        @if(!empty($block['cta_url']))
                            <x-filament::button
                                href="{{ $block['cta_url'] }}"
                                tag="a"
                                target="_blank"
                                rel="noopener"
                                class="mt-3"
                            >
                                {{ $block['cta_label'] ?? 'Перейти' }}
                            </x-filament::button>
                        @endif
                        @break

                    @case('link')
                        @if(!empty($block['url']))
                            <x-filament::link
                                href="{{ $block['url'] }}"
                                target="_blank"
                                rel="noopener"
                            >
                                {{ $block['label'] ?? $block['url'] }}
                            </x-filament::link>
                        @endif
                        @if(!empty($block['description']))
                            <p class="mt-2 text-sm text-gray-600">
                                {!! nl2br(e($block['description'])) !!}
                            </p>
                        @endif
                        @break

                    @case('testimonial')
                        <div class="flex gap-3">
                            @if(!empty($block['avatar_url']))
                                <img src="{{ $block['avatar_url'] }}" alt="" class="h-10 w-10 rounded-full border border-gray-200">
                            @endif
                            <div>
                                @if(!empty($block['quote']))
                                    <p class="text-gray-700">
                                        "{!! nl2br(e($block['quote'])) !!}"
                                    </p>
                                @endif
                                @if(!empty($block['author']) || !empty($block['role']) || !empty($block['author_company']))
                                    <p class="mt-2 text-sm text-gray-600">
                                        {{ $block['author'] ?? '' }}
                                        @if(!empty($block['role']))
                                            — {{ $block['role'] }}
                                        @endif
                                        @if(!empty($block['author_company']))
                                            , {{ $block['author_company'] }}
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>
                        @break

                    @case('text')
                    @default
                        @if(!empty($block['body']))
                            <p class="text-gray-700">
                                {!! nl2br(e($block['body'])) !!}
                            </p>
                        @endif
                @endswitch
                </x-filament::section>
            </div>
        @endforeach
    </div>
@endif
