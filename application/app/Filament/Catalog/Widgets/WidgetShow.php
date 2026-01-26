<?php

namespace App\Filament\Catalog\Widgets;

//use App\Models\Widget;
use App\Models\App;
use App\Models\Widgets\Widget;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class WidgetShow extends TableWidget
{
    use InteractsWithPageFilters;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn() => $this->getFilteredQuery())
            ->columns([
                Stack::make([
                    Split::make([
                        TextColumn::make('title')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Medium)
                            ->tooltip(fn(?Widget $record) => $record?->title)
                            ->formatStateUsing(
                                fn(string $state, Widget $record) => $record->is_featured ? $state . ' ★' : $state
                            ),

                        TextColumn::make('pricing')
                            ->label('')
                            ->alignRight()
                            ->badge()
                            ->state(function (?Widget $record): string {
                                if ($record->pricing_type === 'free') {
                                    $pricing = 'Free';
                                } else {
                                    $pricing = $record->price_from_rub
                                        ? 'от ' . number_format((int)$record->price_from_rub, 0, '.', ' ') . ' ₽'
                                        : 'Paid';
                                }

                                $installs = $record->installs_count
                                    ? ' · ' . number_format((int)$record->installs_count, 0, '.', ' ') . ' установок'
                                    : '';

                                return $pricing . $installs;
                            })
                            ->color(fn(?Widget $record) => $record->pricing_type === 'free' ? 'gray' : 'warning'),
                    ]),

                    TextColumn::make('excerpt')
                        ->label('')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->wrap()
                        ->extraAttributes(['class' => 'mt-3'])
                        ->state(
                            fn(?Widget $record) => filled($record?->excerpt) ? Str::limit(
                                trim($record->excerpt),
                                160
                            ) : null
                        )
                        ->visible(fn(?Widget $record) => filled($record?->excerpt)),
                ])->space(3), // ← добавит вертикальный gap между Split и excerpt,
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->paginated(false)
            ->recordUrl(fn(Widget $w) => url('/catalog/widgets/' . $w->slug))
            ->heading(false);
    }

    protected function getFilteredQuery()
    {
        $page = $this->getPage();

        return Widget::query();
//            ->when($page->q, fn ($q) =>
//            $q->where('title', 'like', "%{$page->q}%")
//            )
//            ->when($page->pricing, fn ($q) =>
//            $q->where('pricing_type', $page->pricing)
//            );
    }

    public function getWidgetsProperty()
    {
        return \App\Models\Widgets\Widget::query()
            ->where('is_published', true);
//            ->when($this->q !== '', function ($query) {
//                $query->where(function ($q) {
//                    $q->where('title', 'like', "%{$this->q}%")
//                        ->orWhere('excerpt', 'like', "%{$this->q}%");
//                });
//            })
//            ->when($this->pricing, fn ($q) => $q->where('pricing_type', $this->pricing))
//            ->when($this->category, function ($q) {
//                $q->whereHas('categories', fn ($c) => $c->where('slug', $this->category));
//            })
//            ->when($this->sort === 'featured', fn ($q) => $q->orderByDesc('is_featured')->orderByDesc('installs_count'))
//            ->when($this->sort === 'installs', fn ($q) => $q->orderByDesc('installs_count'))
//            ->when($this->sort === 'title', fn ($q) => $q->orderBy('title'))
//            ->paginate(18);
    }

    public function getColumnSpan(): int|string|array
    {
        return 2;
    }

//    public function getTitle(): string
//    {
//        return $this->widget->title;
//    }
//
//    public static function getRoutePath(Panel $panel): string
//    {
//        return 'widgets/{slug}';
//    }
}
