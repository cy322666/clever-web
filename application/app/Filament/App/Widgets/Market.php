<?php

namespace App\Filament\App\Widgets;

use App\Models\App;
use App\Services\Integrations\IntegrationProvisioningService;
use Carbon\Carbon;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Market extends TableWidget
{
    use InteractsWithPageFilters;

    private bool $catalogSynced = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn(): Builder => $this->getFilteredQuery())
            ->columns([
                Stack::make([
                    Split::make([
                        TextColumn::make('title')
                            ->label('Название')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Medium)
                            ->tooltip(fn(?App $app) => $app ? App::getTooltipText($app->name) : null)
                            ->limit(28)
                            ->state(fn(?App $app) => self::safeRecordTitle($app)),

                        TextColumn::make('status')
                            ->label('Статус')
                            ->alignRight()
                            ->badge()
                            ->state(fn(App $app): string => self::statusBadgeText($app))
                            ->color(fn(App $app): string => match (self::effectiveStatus($app)) {
                                App::STATE_CREATED => 'gray',
                                App::STATE_INACTIVE => 'warning',
                                App::STATE_ACTIVE => 'success',
                                App::STATE_EXPIRES => 'danger',
                            }),
                    ]),

                    TextColumn::make('excerpt')
                        ->label('')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->wrap()
                        ->extraAttributes(['class' => 'mt-3'])
                        ->state(
                            fn(?App $record) => filled($record)
                                ? Str::limit(trim(App::getTooltipText($record->name)), 160)
                                : null
                        )
                        ->visible(fn(?App $record) => filled(trim((string)App::getTooltipText($record?->name ?? '')))),
                ])->space(3),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->paginated(false)
            ->recordUrl(
                fn(App $app): string => route('integrations.open', ['app' => $app->id])
            )
            ->heading(false)
            ->striped(false);
    }

    public function getColumnSpan(): int | string | array
    {
        return 2;
    }

    protected function getFilteredQuery(): Builder
    {
        $this->syncCatalog();

        $query = App::query()
            ->where('user_id', auth()->id());

        $availableNames = app()->environment('production')
            ? App::definitionNames(true)
            : App::definitionNames();

        $query->whereIn('name', $availableNames);

        $status = (string)($this->pageFilters['status'] ?? 'all');
        if ($status !== '' && $status !== 'all') {
            $query->where('status', (int)$status);
        }

        $search = trim((string)($this->pageFilters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%' . $search . '%');

                $matchedByMeta = $this->matchedAppNamesByMeta($search);
                if (!empty($matchedByMeta)) {
                    $builder->orWhereIn('name', $matchedByMeta);
                }
            });
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
        if (!$user) {
            return;
        }

        app(IntegrationProvisioningService::class)->syncCatalogForUser($user);
    }

    private function matchedAppNamesByMeta(string $search): array
    {
        $search = Str::lower(trim($search));
        if ($search === '') {
            return [];
        }

        return App::query()
            ->where('user_id', auth()->id())
            ->whereIn('name', App::definitionNames())
            ->get(['name', 'resource_name'])
            ->filter(function (App $app) use ($search): bool {
                $title = Str::lower(self::safeRecordTitle($app));
                $tooltip = Str::lower(App::getTooltipText($app->name));

                return Str::contains($title . ' ' . $tooltip, $search);
            })
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
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
            if (!filled($app->expires_tariff_at)) {
                return App::STATE_ACTIVE_WORD;
            }

            return 'До ' . Carbon::parse($app->expires_tariff_at)->format('Y-m-d');
        }

        if ($status === App::STATE_EXPIRES && filled($app->expires_tariff_at)) {
            $daysAgo = Carbon::parse($app->expires_tariff_at)
                ->startOfDay()
                ->diffInDays(now()->startOfDay());

            return $daysAgo . ' дн.';
        }

        return match ($status) {
            App::STATE_CREATED => App::STATE_CREATED_WORD,
            App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
            default => App::STATE_EXPIRES_WORD,
        };
    }

    private static function safeRecordTitle(?App $app): string
    {
        if (!$app) {
            return '';
        }

        return App::getTitle((string)$app->name, $app->resource_name);
    }
}
