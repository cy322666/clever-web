<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\Market;
use App\Models\App;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use BaseDashboard\Concerns\HasFiltersForm;

    protected static string $routePath = 'dashboard';

    protected static ?string $title = 'Магазин';

    protected Width | string | null $maxContentWidth = Width::FiveExtraLarge;

    public function filtersForm(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('q')
                ->label('Поиск')
                ->placeholder('Название или описание')
                ->live(debounce: 400),

            Select::make('status')
                ->label('Статус')
                ->options([
                    'all' => 'Все',
                    (string)App::STATE_ACTIVE => App::STATE_ACTIVE_WORD,
                    (string)App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
                    (string)App::STATE_EXPIRES => App::STATE_EXPIRES_WORD,
                    (string)App::STATE_CREATED => 'Не установлена',
                ])
                ->default('all')
                ->live(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            Market::class
        ];
    }
}
