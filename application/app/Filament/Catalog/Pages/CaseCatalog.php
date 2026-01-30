<?php

namespace App\Filament\Catalog\Pages;

use App\Filament\Catalog\Widgets\CaseCards;
use App\Models\Cases\CompanyCase;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class CaseCatalog extends Dashboard
{
    use Dashboard\Concerns\HasFiltersForm;

    protected static bool $shouldRegisterNavigation = false;
    protected static string $routePath = 'cases';
    protected static ?string $title = '';
    public ?string $q = null;
    public array $tags = [];
    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public function filtersForm(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('q')
                ->label('Поиск')
                ->placeholder('Название, компания или описание')
                ->live(debounce: 400),

            Select::make('tags')
                ->label('Теги')
                ->options(fn () => $this->getTagOptions())
                ->multiple()
                ->searchable(),

            Select::make('categories')
                ->label('Сферы')
                ->options(fn () => $this->getTagOptions())
                // ->multiple()
                ->searchable(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            CaseCards::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getTagOptions(): array
    {
        return CompanyCase::query()
            ->where('is_published', true)
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $tag) => [$tag => $tag])
            ->toArray();
    }
}
