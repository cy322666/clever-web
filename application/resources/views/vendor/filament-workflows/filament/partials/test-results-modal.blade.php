<div class="space-y-4">
    {{-- Error Message --}}
    @if(!$results['success'] && !empty($results['error']))
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 dark:border-danger-700 dark:bg-danger-900/20">
            <div class="flex items-start gap-3">
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="h-5 w-5 text-danger-500 dark:text-danger-400"
                />
                <div>
                    <h4 class="font-medium text-danger-800 dark:text-danger-200">{{ __('filament-workflows::workflows.fields.error_message.label') }}</h4>
                    <p class="mt-1 text-sm text-danger-700 dark:text-danger-300">{{ $results['error'] }}</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Steps Timeline --}}
    @if(!empty($results['steps']))
        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-2 mb-3">
                <x-filament::icon
                    icon="heroicon-o-list-bullet"
                    class="h-5 w-5 text-gray-500 dark:text-gray-400"
                />
                <h4 class="font-medium text-gray-900 dark:text-white">{{ __('filament-workflows::workflows.test_results.step_preview') }}</h4>
                <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">
                    {{ __('filament-workflows::workflows.test_results.step_count', ['count' => count($results['steps'])]) }}
                </span>
            </div>

            <div class="space-y-3">
                @foreach($results['steps'] as $index => $step)
                    @include('filament-workflows::filament.partials.test-step-result', [
                        'step' => $step,
                        'index' => $index,
                        'depth' => 0
                    ])
                @endforeach
            </div>
        </div>
    @endif

    {{-- Context Variables --}}
    @if(!empty($results['variables']))
        <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-2 mb-3">
                <x-filament::icon
                    icon="heroicon-o-variable"
                    class="h-5 w-5 text-gray-500 dark:text-gray-400"
                />
                <h4 class="font-medium text-gray-900 dark:text-white">{{ __('filament-workflows::workflows.test_results.context_variables') }}</h4>
            </div>

            <div class="max-h-40 overflow-y-auto">
                <pre
                    class="text-xs bg-gray-50 dark:bg-gray-900 p-2 rounded text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ json_encode($results['variables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    @endif

    {{-- Completed Timestamp --}}
    @if(!empty($results['completed_at']))
        <p class="text-xs text-gray-500 dark:text-gray-400 text-right">
            {{ __('filament-workflows::workflows.test_results.completed', ['time' => \Carbon\Carbon::parse($results['completed_at'])->format('M j, Y g:i:s A')]) }}
        </p>
    @endif
</div>
