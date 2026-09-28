<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\Onboarding;
use App\Models\App;
use App\Services\Integrations\IntegrationProvisioningService;
use App\Support\Crm\CrmProvider;
use App\Support\Onboarding\IndustryProfile;
use Carbon\Carbon;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class Market extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.app.widgets.market';

    protected int|string|array $columnSpan = 'full';

    private bool $catalogSynced = false;

    protected function getViewData(): array
    {
        $definitions = App::definitions();
        $recommended = IndustryProfile::recommendedApps(auth()->user()?->industry);
        $cards = $this->getFilteredQuery()->get()->map(function (App $app) use ($definitions, $recommended): array {
            $status = self::effectiveStatus($app);

            return [
                'id' => $app->id,
                'category' => $definitions->get($app->name)['category'] ?? 'universal',
                'title' => self::safeRecordTitle($app),
                'description' => trim(App::getTooltipText($app->name)),
                'url' => route('integrations.open', ['app' => $app->id]),
                'status' => self::statusBadgeText($app),
                'color' => match ($status) {
                    App::STATE_INACTIVE => 'warning',
                    App::STATE_ACTIVE => 'success',
                    App::STATE_EXPIRES => 'danger',
                    default => 'gray',
                },
                'action' => $status === App::STATE_CREATED ? 'Подключить' : 'Открыть',
                'recommended' => in_array($app->name, $recommended, true),
            ];
        });

        return [
            'sections' => [
                'universal' => [
                    'title' => 'Универсальные',
                    'description' => 'Для любой сферы: заявки, данные и автоматизация работы.',
                    'cards' => $cards->where('category', 'universal'),
                ],
                'industry' => [
                    'title' => 'Отраслевые',
                    'description' => 'Интеграции с сервисами для красоты, медицины и ветеринарии.',
                    'cards' => $cards->where('category', 'industry'),
                ],
            ],
            'isEmpty' => $cards->isEmpty(),
            'crmLabel' => CrmProvider::label(auth()->user()?->crm_provider),
            'settingsUrl' => Onboarding::getUrl(),
        ];
    }

    protected function getFilteredQuery(): Builder
    {
        $this->syncCatalog();

        $query = App::query()
            ->where('user_id', auth()->id());

        $availableNames = App::definitionNamesForCrmProvider(
            auth()->user()?->crm_provider,
            app()->environment('production') ? true : null,
        );

        $query->whereIn('name', $availableNames);

        $recommended = IndustryProfile::recommendedApps(auth()->user()?->industry);

        if ($recommended !== []) {
            $case = collect($recommended)
                ->values()
                ->map(fn (string $name, int $position): string => 'WHEN ? THEN '.($position + 1))
                ->implode(' ');

            $query->orderByRaw('CASE name '.$case.' ELSE '.(count($recommended) + 1).' END', $recommended);
        }

        return $query->orderBy('name');
    }

    private function syncCatalog(): void
    {
        if ($this->catalogSynced) {
            return;
        }

        $this->catalogSynced = true;

        $user = auth()->user();
        if (! $user) {
            return;
        }

        try {
            app(IntegrationProvisioningService::class)->syncCatalogForUser($user);
        } catch (Throwable $exception) {
            Log::warning('Failed to sync integration catalog before rendering market.', [
                'user_id' => $user->id,
                'exception' => $exception,
            ]);
        }
    }

    private static function effectiveStatus(App $app): int
    {
        if (
            $app->status === App::STATE_ACTIVE
            && filled($app->expires_tariff_at)
            && Carbon::parse($app->expires_tariff_at)->startOfDay()->lt(now()->startOfDay())
        ) {
            return App::STATE_EXPIRES;
        }

        return $app->status;
    }

    private static function statusBadgeText(App $app): string
    {
        $status = self::effectiveStatus($app);

        if ($status === App::STATE_ACTIVE) {
            if (! filled($app->expires_tariff_at)) {
                return App::STATE_ACTIVE_WORD;
            }

            return 'До '.Carbon::parse($app->expires_tariff_at)->format('Y-m-d');
        }

        if ($status === App::STATE_EXPIRES && filled($app->expires_tariff_at)) {
            $daysAgo = Carbon::parse($app->expires_tariff_at)
                ->startOfDay()
                ->diffInDays(now()->startOfDay());

            return 'Истёк '.$daysAgo.' дн.';
        }

        return match ($status) {
            App::STATE_CREATED => 'Можно подключить',
            App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
            default => App::STATE_EXPIRES_WORD,
        };
    }

    private static function safeRecordTitle(?App $app): string
    {
        if (! $app) {
            return '';
        }

        return App::getTitle((string) $app->name, $app->resource_name);
    }
}
