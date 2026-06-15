<?php

namespace App\Workflows\Triggers;

use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowGenericWebhookService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Illuminate\Support\HtmlString;
use Leek\FilamentWorkflows\Triggers\Contracts\BaseTrigger;

class GenericWebhookTrigger implements BaseTrigger
{
    public static function type(): string
    {
        return 'generic-webhook';
    }

    public static function name(): string
    {
        return 'Вебхук';
    }

    public static function description(): string
    {
        return 'Запускается при получении внешнего webhook-запроса.';
    }

    public static function icon(): string
    {
        return 'heroicon-o-globe-alt';
    }

    public static function color(): string
    {
        return '#0EA5E9';
    }

    /**
     * @return array<Component>
     */
    public static function configSchema(): array
    {
        return [
            Hidden::make('source')->default('webhook'),

            Placeholder::make('webhook_url')
                ->label('URL вебхука')
                ->content(function (mixed $livewire = null): HtmlString {
                    $workflow = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

                    if (!$workflow instanceof Workflow || !$workflow->exists) {
                        return new HtmlString('URL появится после создания процесса.');
                    }

                    $url = app(WorkflowGenericWebhookService::class)->callbackUrl($workflow);

                    return new HtmlString(
                        '<code class="block break-all rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100">'
                        . e($url)
                        . '</code>'
                    );
                }),

            Placeholder::make('variables_hint')
                ->label('Данные запроса')
                ->content(new HtmlString(
                    'Используйте переменные вида <code>{{payload.key}}</code>, <code>{{query.key}}</code>, '
                    . '<code>{{headers.header_name}}</code>, <code>{{method}}</code>, <code>{{url}}</code>.'
                )),

            View::make('filament.workflow-builder.generic-webhook-preview')
                ->viewData(function (mixed $livewire = null): array {
                    $workflow = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

                    if (!$workflow instanceof Workflow || !$workflow->exists) {
                        return [
                            'workflow' => null,
                            'preview' => null,
                            'url' => null,
                        ];
                    }

                    return [
                        'workflow' => $workflow,
                        'preview' => app(WorkflowGenericWebhookService::class)->latestPreview($workflow),
                        'url' => app(WorkflowGenericWebhookService::class)->callbackUrl($workflow),
                    ];
                })
                ->poll('3s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'source' => 'webhook',
        ];
    }

    public function shouldTrigger(array $config, mixed $subject, array $context = []): bool
    {
        return ($config['source'] ?? 'webhook') === 'webhook';
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextData(array $config, mixed $subject, array $context = []): array
    {
        return is_array($subject) ? $subject : $context;
    }

    public static function getConfiguredDescription(array $config): string
    {
        return 'Запускается при входящем запросе на URL процесса.';
    }

    /**
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateConfig(array $config): array
    {
        return [
            'valid' => true,
            'errors' => [],
        ];
    }
}
