<?php

namespace App\Filament\Catalog\Widgets\Widgets;

//use App\Filament\Resources\Widgets\WidgetResource\Pages;
use App\Models\Widgets\Widget;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

//use Filament\Forms\Form;

class WidgetResource extends Resource
{
    protected static ?string $model = Widget::class;
//    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Виджеты';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Основное')->schema([
                Forms\Components\TextInput::make('title')->required()->maxLength(255),
                Forms\Components\TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('excerpt')->rows(3),
                Forms\Components\RichEditor::make('description')->columnSpanFull(),
                Forms\Components\TextInput::make('logo_url')->label('Logo URL')->url(),
            ])->columns(2),

            Section::make('Категории/Теги')->schema([
                Forms\Components\Select::make('categories')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->preload(),
                Forms\Components\TagsInput::make('tags'),
            ])->columns(2),

            Section::make('Ссылки')->schema([
//                Forms\Components\TextInput::make('install_url')->url(),
//                Forms\Components\TextInput::make('support_url')->url(),
//                Forms\Components\TextInput::make('website_url')->url(),
                Forms\Components\TextInput::make('demo_vk_url')->url(),
                Forms\Components\TextInput::make('demo_youtube_url')->url(),
            ])->columns(2),

            Section::make('Монетизация/Публикация')->schema([
                Forms\Components\Select::make('pricing_type')
                    ->options(['free' => 'Бесплатно', 'paid' => 'Платно'])
                    ->required(),
                Forms\Components\TextInput::make('price_from_rub')->numeric()->minValue(0),
                Forms\Components\TextInput::make('trial_days')->numeric()->minValue(0),
                Forms\Components\Toggle::make('is_featured'),
                Forms\Components\Toggle::make('is_published')->live(),
                Forms\Components\DateTimePicker::make('published_at')
                    ->visible(fn($get) => (bool)$get('is_published')),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('logo_url')->label('')->circular(),
            Tables\Columns\TextColumn::make('title')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('slug')->copyable(),
            Tables\Columns\IconColumn::make('is_published')->boolean(),
            Tables\Columns\IconColumn::make('is_featured')->boolean(),
            Tables\Columns\TextColumn::make('pricing_type')->badge(),
            Tables\Columns\TextColumn::make('installs_count')->sortable(),
        ])->filters([
            Tables\Filters\TernaryFilter::make('is_published'),
            Tables\Filters\TernaryFilter::make('is_featured'),
        ])->actions([
            EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Catalog\Widgets\Widgets\Pages\ListWidgets::route('/'),
            'create' => \App\Filament\Catalog\Widgets\Widgets\Pages\CreateWidget::route('/create'),
            'edit' => \App\Filament\Catalog\Widgets\Widgets\Pages\EditWidget::route('/{record}/edit'),
        ];
    }
}
