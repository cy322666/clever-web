<?php

namespace App\Filament\Catalog\Pages;

use App\Models\Cases\CompanyCase;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class CaseShow extends Page implements HasInfolists
{
    use InteractsWithInfolists;

    protected static bool $shouldRegisterNavigation = false;
    protected static string $routePath = 'cases/{slug}';
    protected static ?string $title = '';

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    // protected static string $view = 'filament-panels::pages.page';

    public CompanyCase $record;

    public function getView(): string
    {
        return 'filament-panels::pages.page';
    }

    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    protected function hasInfolist(): bool
    {
        return (bool) count($this->getSchema('infolist')->getComponents());
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedSchema::make('infolist'),
            ]);
    }

    public function mount(string $slug): void
    {
        $query = CompanyCase::query()->where('slug', $slug);

        if (!Auth::check()) {
            $query->where('is_published', true);
        }

        $this->record = $query->firstOrFail();
    }

    public function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make()
                    ->schema([
                        TextEntry::make('back_link')
                            ->label('')
                            ->hiddenLabel()
                            ->state(fn () => '<a href="' . url('/catalog/cases') . '" class="text-sm text-gray-500 hover:underline">← Назад к кейсам</a>')
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'mb-6']),

                Section::make()
                    ->schema([
                        ImageEntry::make('cover_url')
                            ->label('')
                            ->height('256px')
                            ->width('100%')
                            ->columnSpanFull()
                            ->visible(fn () => filled($this->record->cover_url)),
                    ])
                    ->columnSpanFull()
                    ->visible(fn () => filled($this->record->cover_url))
                    ->extraAttributes(['class' => 'mb-6']),

                Section::make()
                    ->schema([
                        TextEntry::make('title')
                            ->label('')
                            ->size(TextSize::Large)
                            ->weight('bold')
                            ->color('primary')
                            ->hiddenLabel()
                            ->columnSpanFull(),

                        TextEntry::make('company_name')
                            ->label('')
                            ->size(TextSize::Medium)
                            ->color('gray')
                            ->hiddenLabel()
                            ->columnSpan(1)
                            ->visible(fn () => filled($this->record->company_name)),

                        TextEntry::make('industry')
                            ->label('')
                            ->badge()
                            ->color('warning')
                            ->hiddenLabel()
                            ->columnSpan(1)
                            ->visible(fn () => filled($this->record->industry)),

                        TextEntry::make('tags')
                            ->label('')
                            ->hiddenLabel()
                            ->badge()
                            ->separator(',')
                            ->columnSpanFull()
                            ->visible(fn () => !empty($this->record->tags)),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'mb-6']),

                // Section::make()
                //     ->schema([
                //         TextEntry::make('excerpt')
                //             ->label('')
                //             ->columnSpanFull()
                //             ->visible(fn () => filled($this->record->excerpt)),
                //     ])
                //     ->columnSpanFull()
                //     ->visible(fn () => filled($this->record->excerpt))
                //     ->extraAttributes(['class' => 'mb-6']),

                Section::make()
                    ->schema([
                       
                        TextEntry::make('description')
                            ->label('Текст')
                            ->html()
                            ->columnSpanFull(),

                        TextEntry::make('description')->html(),

                    


                        RichContentRenderer::make('description')
                            // ->label('')
                            // ->hiddenLabel()
                            ->content($this->record->description)
                            ->toHtml(),

                        TextEntry::make('description')
                            ->label('')
                            ->hiddenLabel()
                            ->formatStateUsing(fn ($state) => new HtmlString($state ?? ''))
                            ->columnSpanFull()
                            ->visible(fn () => filled($this->record->description)),
                    ])
                    ->columnSpanFull()
                    ->visible(fn () => filled($this->record->description))
                    ->extraAttributes(['class' => 'mb-6']),

                ViewEntry::make('content_blocks')
                    ->view('filament.catalog.infolists.components.content-blocks')
                    // ->columnSpanFull()
                    ->visible(fn () => !empty($this->record->content_blocks)),
            ])
            ->columns(2)
            ->record($this->record);
    }
}
