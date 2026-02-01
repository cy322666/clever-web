<?php

namespace App\Filament\App\Widgets;

use App\Filament\Resources\Integrations\AlfaResource;
use App\Models\App;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
//                        ->tooltip(fn(?App $app) => App::getTooltipText($app->name))
                        ->state(fn(?App $app) => $app->resource_name::getRecordTitle()),

                    TextColumn::make('status')
                        ->label('Статус')
                        ->alignRight()
                        ->badge()
                        ->state(fn (App $app): string => match ($app->status) {
                            App::STATE_CREATED  => App::STATE_CREATED_WORD,
                            App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
                            App::STATE_ACTIVE   => 'До '.$app->expires_tariff_at,
                            App::STATE_EXPIRES  => App::STATE_EXPIRES_WORD,
                        })
                        ->color(fn (App $app): string => match ($app->status) {
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
                fn (App $app) => $app->resource_name::getSlug().'/'.$app->setting_id.'/edit'
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
            ->striped();
//            ->header(view('tables.header', [
//                'heading' => 'Clients',
//            ]))
//            ->description('Manage your clients here.')
//            ->heading();//TODO

    }

    public function getColumnSpan(): int | string | array
    {
        return 2;
    }
}
