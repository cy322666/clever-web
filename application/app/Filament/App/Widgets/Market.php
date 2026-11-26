<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\Onboarding;
use App\Models\App;
use App\Support\Crm\CrmProvider;
use App\Support\Onboarding\IndustryProfile;
use Carbon\Carbon;
use Filament\Widgets\Widget;

class Market extends Widget
{
    //страница с виджетами на главной, это виджет с виджетами
    protected static bool $isLazy = false;

    protected string $view = 'filament.app.widgets.market';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $user = auth()->user();
        $recommended = array_flip(IndustryProfile::recommendedApps($user?->industry));
        $definitions = App::definitionsForCrmProvider(
            $user?->crm_provider,
            app()->environment('production') ? true : null,
        )->sortBy(fn (array $definition, string $name): array => [$recommended[$name] ?? PHP_INT_MAX, $name]);

        $apps = $user && $definitions->isNotEmpty()
            ? $user->apps()->whereIn('name', $definitions->keys())->get()->keyBy('name')
            : collect();

        $cards = $definitions->map(function (array $definition, string $name) use ($apps): array {
            $app = $apps->get($name);
            $status = $app ? self::effectiveStatus($app) : App::STATE_CREATED;

            return [
                'key' => $name,
                'category' => $definition['category'] ?? 'universal',
                'title' => App::getTitle($name, $definition['resource']),
                'logo' => asset($definition['logo'] ?? 'logo/clever_mini_logo.png'),
                'url' => $app ? route('integrations.open', ['app' => $app->id]) : route('integrations.connect', ['widget' => $name]),
                'connect' => $app === null,
                'status' => $app ? self::statusBadgeText($app) : null,
                'color' => match ($status) {
                    App::STATE_INACTIVE => 'warning',
                    App::STATE_ACTIVE => 'success',
                    App::STATE_EXPIRES => 'danger',
                    default => 'gray',
                },
                'action' => $status === App::STATE_CREATED ? 'Подключить' : 'Открыть',
            ];
        });

        return [
            'sections' => [
                'universal' => [
                    'title' => 'Универсальные',
                    'cards' => $cards->where('category', 'universal'),
                ],
                'industry' => [
                    'title' => 'Отраслевые',
                    'cards' => $cards->where('category', 'industry'),
                ],
            ],
            'isEmpty' => $cards->isEmpty(),
            'crmLabel' => CrmProvider::label($user?->crm_provider),
            'settingsUrl' => Onboarding::getUrl(),
        ];
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

    private static function statusBadgeText(App $app): ?string
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
            App::STATE_CREATED => null,
            App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
            default => App::STATE_EXPIRES_WORD,
        };
    }
}
