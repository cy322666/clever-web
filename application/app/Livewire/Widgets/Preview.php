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
                                        App::STATE_INACTIVE => 'warning',
                                        App::STATE_ACTIVE   => 'success',
                                    })
                                    ->formatStateUsing(fn($state) => match($state) {
                                        App::STATE_CREATED  => App::STATE_CREATED_WORD,
                                        App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
                                        App::STATE_ACTIVE   => App::STATE_ACTIVE_WORD,
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

                                TextEntry::make('cost')
                                    ->label('')
                                    ->state(function (App $app) {

                                        return 'Бесплатно';
                                    })
                                    ->color(Color::GRAY_300)
                                    ->alignLeft(),
                            ])->columns(),
                    ]),
            ]);
    }

    public function render(): View
    {
        return view('filament.app.widgets.preview');
    }
}
