<?php

namespace App\Filament\Catalog\Widgets;

use App\Models\Cases\CompanyCase;
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

class CaseCards extends TableWidget
{
    use InteractsWithPageFilters;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->getFilteredQuery())
            ->columns([
                Stack::make([
                    Split::make([
                        TextColumn::make('title')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Medium)
                            ->tooltip(fn (?CompanyCase $record) => filled($record?->excerpt)
                            ? Str::limit(trim($record->excerpt), 160)
                            : null
                        )
                            // ->tooltip(fn (?CompanyCase $record) => $record?->title),
                    ]),

                    TextColumn::make('company_name')
                        ->label('')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->wrap()
                        ->visible(fn (?CompanyCase $record) => filled($record?->company_name)),

                    // TextColumn::make('excerpt')
                    //     ->label('')
                    //     ->color('gray')
                    //     ->size(TextSize::Small)
                    //     ->wrap()
                    //     ->extraAttributes(['class' => 'mt-3'])
                    //     ->state(fn (?CompanyCase $record) => filled($record?->excerpt)
                    //         ? Str::limit(trim($record->excerpt), 160)
                    //         : null
                    //     )
                    //     ->visible(fn (?CompanyCase $record) => filled($record?->excerpt)),

                                TextColumn::make('industry')
                            ->label('')
                            // ->alignRight()
                            ->badge()
                            ->color('warning')
                            ->visible(fn (?CompanyCase $record) => filled($record?->tags) && is_array($record->tags) && count($record->tags) > 0)
                ])->space(3),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->paginated(false)
            ->recordUrl(fn (CompanyCase $case) => url('/catalog/cases/' . $case->slug))
            ->heading(false);
    }

    protected function getFilteredQuery(): Builder
    {
        $page = $this->getPage();

        return CompanyCase::query()
            ->where('is_published', true)
            ->when(filled($page->q ?? null), function (Builder $query) use ($page) {
                $query->where(function (Builder $sub) use ($page) {
                    $term = $page->q;
                    $sub->where('title', 'like', "%{$term}%")
                        ->orWhere('company_name', 'like', "%{$term}%")
                        ->orWhere('excerpt', 'like', "%{$term}%");
                });
            })
            ->when(!empty($page->tags ?? null), function (Builder $query) use ($page) {
                $query->where(function (Builder $sub) use ($page) {
                    foreach ((array) $page->tags as $tag) {
                        $sub->orWhereJsonContains('tags', $tag);
                    }
                });
            })
            ->orderByDesc('is_featured')
            ->orderBy('sort')
            ->orderByDesc('published_at');
    }

    public function getColumnSpan(): int|string|array
    {
        return 2;
    }
}
