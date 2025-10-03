<?php

namespace App\Livewire\Widgets;

use App\Models\App;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Fieldset;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Infolists\Infolist;
use Filament\Support\Enums\FontWeight;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Termwind\Enums\Color;

class Preview extends Widget implements HasForms, HasInfolists
{
    use InteractsWithInfolists;
    use InteractsWithForms;

    public App $app;

    public function widgetsInfolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->app)
            ->schema([
                Tabs::make('Label')
                    ->tabs([
                        Tabs\Tab::make('Основное')
                            ->schema([
                                TextEntry::make('name')
                                    ->label('')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight(FontWeight::Bold)
                                    ->state(fn(App $app) => $app->resource_name::getRecordTitle())
                                    ->url(fn(App $app) => $app->resource_name::getUrl('edit', ['record' => $app->setting_id]))
                                    ->alignLeft(),

                                TextEntry::make('status')
                                    ->label('')
                                    ->badge()
                                    ->color(fn (App $app): string => match ($app->status) {
                                        App::STATE_CREATED  => 'gray',
                                        App::STATE_INACTIVE, 0, 2 => 'warning',
                                        App::STATE_ACTIVE, 1   => 'success',
                                    })
                                    ->formatStateUsing(fn($state) => match($state) {
                                        App::STATE_CREATED  => App::STATE_CREATED_WORD,
                                        App::STATE_INACTIVE, 0, 2 => App::STATE_INACTIVE_WORD,
                                        App::STATE_ACTIVE, 1   => App::STATE_ACTIVE_WORD,
                                    }),
                                TextEntry::make('expires_tariff_at')
                                    ->label('')
                                    ->size(TextEntry\TextEntrySize::ExtraSmall)
                                    ->formatStateUsing(function (App $app) {

                                        if($app->status != 0) {

                                            return 'Истекает: '.$app->expires_tariff_at;
                                        } else
                                            return 'Триал';
                                    })
                                    ->alignLeft(),
                            ])->columns(),

                        Tabs\Tab::make('Описание')
                            ->schema([
                                TextEntry::make('description')
                                    ->label('')
                                    ->size(TextEntry\TextEntrySize::Small)
                                    ->state(fn(App $app) => $app->resource_name::getModel()::$description ?? '')
                            ])->columns(1),


                        Tabs\Tab::make('Цена')
                            ->schema([

                                /** @var Model $cost Resource -> Setting type*/
//                                TextEntry::make('cost_1_month')
//                                    ->label('')
//                                    ->state(fn (App $app) => '- 1 мес : '.$app->resource_name::getModel()::$cost['1_month'])
//                                    ->color(Color::GRAY_300)
//                                    ->alignLeft(),

                                TextEntry::make('cost_6_month')
                                    ->label('')
                                    ->state(fn (App $app) => '- 6 мес : '.$app->resource_name::getModel()::$cost['6_month'])
                                    ->color(Color::GRAY_300),

                                TextEntry::make('cost_12_month')
                                    ->label('')
                                    ->state(fn (App $app) => '- 12 мес : '.$app->resource_name::getModel()::$cost['12_month'])
                                    ->color(Color::GRAY_300),

                            ])
                            ->extraAttributes(['gap' => 1])
                            ->columns(1)
                    ]),
            ]);
    }

    public function render(): View
    {
        return view('filament.app.widgets.preview');
    }
}
