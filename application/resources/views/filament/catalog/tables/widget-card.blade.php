@php /** @var \App\Models\Widget $record */ @endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 hover:shadow-sm transition">
    <div class="flex items-start gap-3">
        <div class="h-11 w-11 shrink-0 rounded-xl border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center">
            @if($record->logo_url)
                <img src="{{ $record->logo_url }}" class="h-full w-full object-cover" alt="{{ $record->title }}">
            @else
                <span class="text-xs font-bold" style="color:#FF4E36;">CP</span>
            @endif
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex items-center justify-between gap-2">
                <div class="truncate text-sm font-semibold text-gray-900">
                    {{ $record->title }}
                </div>

                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                      style="background: rgba(255,78,54,.10); color:#FF4E36;">
                    {{ $record->status }}
                </span>
            </div>

            @if($record->excerpt)
                <div class="mt-1 text-sm text-gray-600">
                    {{ \Illuminate\Support\Str::limit($record->excerpt, 90) }}
                </div>
            @endif
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <span class="inline-flex items-center rounded-full border border-gray-200 px-2 py-0.5 text-[11px] text-gray-600">
            {{ $record->pricing_type === 'free' ? 'Free' : 'Paid' }}
            @if($record->trial_days) · {{ $record->trial_days }} days trial @endif
        </span>

        <span class="inline-flex items-center rounded-full border border-gray-200 px-2 py-0.5 text-[11px] text-gray-600">
            {{ number_format($record->installs_count) }} installs
        </span>
    </div>

    <div class="mt-5 flex items-center justify-between">
        <span class="text-sm font-semibold" style="color:#FF4E36;">View details</span>
        <span class="text-gray-400">→</span>
    </div>
</div>
