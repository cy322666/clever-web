<?php

namespace App\Livewire\Widgets;

use App\Models\App;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Infolists\Components\TextEntry;
use League\Uri\Components\Component;

class Preview extends Widget implements HasInfolists
{
    public App $app;

    protected string $view = 'filament.app.widgets.preview';

    public function mount(App $app): void
    {
        $this->app = $app;
    }

    public function getData(): array
    {
        return [
            'app' => $this->app,
        ];
    }

    public function widgetsInfolist(Schema $schema): Schema
    {
        return $schema
            ->record($this->app)
            ->inlineLabel()
            ->components([
                Section::make('Основное')
                    ->schema([
                        TextEntry::make('name')
                            ->state(fn(?App $app) => $app->resource_name::getRecordTitle()  ?? null),
                        TextEntry::make('status')
                            ->state(fn(?App $app) => $app->status ?? null),
                    ]),
                Section::make('Описание')
                    ->schema([
                        TextEntry::make('description')
                            ->state(fn(?App $app) => $app->description ?? null),
                    ]),
                Section::make('Цена')
                    ->schema([
                        TextEntry::make('cost_6_month')
                            ->state(fn(?App $app) => $app->resource_name::getModel()::$cost['6_month'] ?? null),
                        TextEntry::make('cost_12_month')
                            ->state(fn(?App $app) => $app->resource_name::getModel()::$cost['12_month'] ?? null),
                    ]),
            ]);
    }
}
