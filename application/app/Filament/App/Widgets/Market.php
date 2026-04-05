<?php

namespace App\Filament\App\Widgets;

use App\Models\App;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class Market extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->query(function () {

                $app = App::query()
                    ->where('user_id', auth()->id());

                if (env('APP_ENV') === 'production') {
                    $noPublic = App::noPublicNames();

                    foreach ($noPublic as $name) {
                        $app->where('name', '!=', $name);
                    }
                }

                return $app;
            })
            ->columns([
                Stack::make([
                    Split::make([

                        TextColumn::make('title')
                        ->label('Название')
                        ->weight(FontWeight::Bold)
                        ->size(TextSize::Medium)
                            ->tooltip(fn(?App $app) => App::getTooltipText($app->name))
                            ->limit(28)
                            ->state(fn(?App $app) => self::safeRecordTitle($app)),

                    TextColumn::make('status')
                        ->label('Статус')
                        ->alignRight()
                        ->badge()
                        ->state(fn(App $app): string => self::statusBadgeText($app))
                        ->color(fn(App $app): string => match (self::effectiveStatus($app)) {
                            App::STATE_CREATED  => 'gray',
                            App::STATE_INACTIVE => 'warning',
                            App::STATE_ACTIVE   => 'success',
                            App::STATE_EXPIRES  => 'danger',
                        }),
                    ]),

//                     TextColumn::make('description')
//                         ->label('')
//                         ->size(TextSize::Small)
//                         ->wrap()
//                         ->extraAttributes(['class' => 'mt-3'])
//                         ->state(
//                             fn(?App $record) => Str::limit(
//                                 trim(App::getTooltipText($record->name)),
//                                 100,
//                             )
//                         ),
                ])->space(3),
            ])
            ->contentGrid([
                'md' => 3,
                'xl' => 3,
            ])
//            ->groups([
//                Group::make('status')
//                    ->label('Статус')
//                    ->getTitleFromRecordUsing(fn (App $app): string => ucfirst($app->getStatusLabel())),
//            ])
//            ->groupingSettingsHidden()
//            ->defaultGroup('status')
            ->paginated(false)
            ->recordUrl(
                fn(App $app): string => route('integrations.open', ['app' => $app->id])
            )
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    //
                ]),
            ])
            ->heading(false)
            ->striped(false);

    }

    public function getColumnSpan(): int | string | array
    {
        return 2;
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

            return $daysAgo === 0
                ? 'Заканчивается сегодня'
                : 'Просрочено на ' . $daysAgo . ' дн.';
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

        $resourceClass = (string)$app->resource_name;
        if (class_exists($resourceClass) && method_exists($resourceClass, 'getRecordTitle')) {
            return (string)$resourceClass::getRecordTitle();
        }

        return (string)$app->name;
    }
}
