<div class="mb-4">
    {{-- Section Header --}}
    <div class="flex items-center gap-3 mb-4 pb-3 border-b border-gray-200 dark:border-gray-700">
        <div @class([
            'flex items-center justify-center w-8 h-8 rounded-full',
            'bg-success-100 dark:bg-success-900/30' => $results['success'],
            'bg-danger-100 dark:bg-danger-900/30' => !$results['success'],
        ])>
            <x-filament::icon
                :icon="$results['success'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'"
                @class([
                    'h-5 w-5',
                    'text-success-600 dark:text-success-400' => $results['success'],
                    'text-danger-600 dark:text-danger-400' => !$results['success'],
                ])
            />
        </div>
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                {{ __('filament-workflows::workflows.test_results.heading') }}
            </h3>
            @php
                $description = $results['success']
                    ? __('filament-workflows::workflows.test_results.success_description')
                    : __('filament-workflows::workflows.test_results.error_description');
            @endphp

            @if(filled($description))
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $description }}
                </p>
            @endif
        </div>
    </div>

    {{-- Include the existing results content --}}
    @include('filament-workflows::filament.partials.test-results-modal', ['results' => $results])
</div>
