<?php

namespace App\Filament\Catalog\Pages;

use App\Filament\Catalog\Widgets\WidgetShow;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class WidgetCatalog extends Dashboard
{
    use Dashboard\Concerns\HasFiltersForm;

    protected static bool $shouldRegisterNavigation = false;
    protected static string $routePath = 'widgets';
    protected static ?string $title = '';
    public ?string $q = null;
    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public function filtersForm(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('q')
                ->label('Поиск')
                ->placeholder('Название или описание')
                ->live(debounce: 400),

            Select::make('pricing')
                ->label('Тариф')
                ->options([
                    'free' => 'Free',
                    'paid' => 'Paid',
                ])
                ->live(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            WidgetShow::class
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
