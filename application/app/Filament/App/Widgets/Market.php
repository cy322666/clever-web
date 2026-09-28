<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\Onboarding;
use App\Models\App;
use App\Services\Integrations\IntegrationProvisioningService;
use App\Support\Crm\CrmProvider;
use App\Support\Onboarding\IndustryProfile;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Market extends TableWidget
{
    protected static bool $isLazy = false;

    private bool $catalogSynced = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getFilteredQuery())
            ->columns([
                Stack::make([
                    Split::make([
                        TextColumn::make('title')
                            ->label('Название')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Medium)
                            ->limit(28)
                            ->state(fn (?App $app) => self::safeRecordTitle($app)),

                        TextColumn::make('crm_provider')
                            ->label('CRM')
                            ->alignRight()
                            ->badge()
                            ->color('gray')
                            ->state(fn (App $app): string => App::crmProviderLabels($app->name)),
                    ]),

                    TextColumn::make('excerpt')
                        ->label('')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->wrap()
                        ->extraAttributes(['class' => 'clever-market-card__description'])
                        ->state(
                            fn (?App $record) => filled($record)
                                ? Str::limit(trim(App::getTooltipText($record->name)), 160)
                                : null
                        )
                        ->visible(fn (?App $record) => filled(trim((string) App::getTooltipText($record?->name ?? '')))),

                    Split::make([
                        TextColumn::make('status')
                            ->label('Статус')
                            ->badge()
                            ->state(fn (App $app): string => self::statusBadgeText($app))
                            ->color(fn (App $app): string => match (self::effectiveStatus($app)) {
                                App::STATE_CREATED => 'gray',
                                App::STATE_INACTIVE => 'warning',
                                App::STATE_ACTIVE => 'success',
                                App::STATE_EXPIRES => 'danger',
                            }),

                        TextColumn::make('open')
                            ->label('')
                            ->alignRight()
                            ->color('primary')
                            ->weight(FontWeight::SemiBold)
                            ->state(fn (App $app): string => self::effectiveStatus($app) === App::STATE_CREATED
                                ? 'Подключить'
                                : 'Открыть'),
                    ]),
                ])->space(3),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->paginated(false)
            ->recordClasses('clever-market-card')
            ->recordUrl(
                fn (App $app): string => route('integrations.open', ['app' => $app->id])
            )
            ->header(fn () => view('filament.app.widgets.market-header', [
                'crmLabel' => CrmProvider::label(auth()->user()?->crm_provider),
                'settingsUrl' => Onboarding::getUrl(),
            ]))
            ->emptyStateIcon('heroicon-o-puzzle-piece')
            ->emptyStateHeading(fn (): string => 'Для '.CrmProvider::label(auth()->user()?->crm_provider).' пока нет интеграций')
            ->emptyStateDescription('Несовместимые виджеты скрыты. Вы можете изменить CRM в настройках платформы.')
            ->emptyStateActions([
                Action::make('change_crm')
                    ->label('Изменить CRM')
                    ->url(fn (): string => Onboarding::getUrl()),
            ])
            ->striped(false);
    }

    public function getColumnSpan(): int|string|array
    {
        return 2;
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
