<?php

namespace App\Filament\Resources\Cases;

use App\Filament\Resources\Cases\CompanyCaseResource\Pages;
use App\Models\Cases\CompanyCase;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CompanyCaseResource extends Resource
{
    protected static ?string $model = CompanyCase::class;

    protected static ?string $slug = 'company-cases';
    // protected static ?string $recordRouteKeyName = 'slug';

    protected static ?string $navigationLabel = 'Кейсы';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Основное')->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Название кейса')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('company_name')
                    ->label('Компания')
                    ->maxLength(255),
                Forms\Components\TextInput::make('industry')
                    ->label('Отрасль')
                    ->maxLength(255),
                Forms\Components\Textarea::make('excerpt')
                    ->label('Короткое описание')
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\RichEditor::make('description')
                    ->label('Описание')
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('content_blocks')
                    ->label('Блоки кейса')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Тип блока')
                            ->options([
                                'text' => 'Текст',
                                'screenshot' => 'Скриншот',
                                'metrics' => 'Цифры',
                                'link' => 'Ссылка',
                                'testimonial' => 'Отзыв',
                                'list' => 'Список',
                                'gallery' => 'Галерея',
                                'video' => 'Видео',
                                'cta' => 'CTA',
                            ])
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('width')
                            ->label('Ширина блока')
                            ->options([
                                'half' => 'Половина ширины (1 столбец)',
                                'full' => 'Полная ширина (2 столбца)',
                            ])
                            ->default('half')
                            ->required(),
                        Forms\Components\TextInput::make('title')
                            ->label('Заголовок')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('body')
                            ->label('Текст')
                            ->rows(4)
                            ->visible(fn (Get $get) => $get('type') === 'text'),
                        Forms\Components\TextInput::make('image_url')
                            ->label('URL изображения')
                            ->url()
                            ->visible(fn (Get $get) => $get('type') === 'screenshot'),
                        Forms\Components\Textarea::make('caption')
                            ->label('Подпись к скриншоту')
                            ->rows(3)
                            ->visible(fn (Get $get) => $get('type') === 'screenshot'),
                        Forms\Components\Repeater::make('items')
                            ->label('Метрики')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label('Подпись')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('value')
                                    ->label('Значение')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('suffix')
                                    ->label('Суффикс')
                                    ->maxLength(50),
                            ])
                            ->defaultItems(1)
                            ->reorderable()
                            ->columns(3)
                            ->visible(fn (Get $get) => $get('type') === 'metrics'),
                        Forms\Components\Repeater::make('list_items')
                            ->label('Список')
                            ->schema([
                                Forms\Components\TextInput::make('text')
                                    ->label('Пункт')
                                    ->maxLength(255),
                            ])
                            ->defaultItems(1)
                            ->reorderable()
                            ->visible(fn (Get $get) => $get('type') === 'list'),
                        Forms\Components\Repeater::make('images')
                            ->label('Галерея')
                            ->schema([
                                Forms\Components\TextInput::make('url')
                                    ->label('URL изображения')
                                    ->url(),
                                Forms\Components\TextInput::make('caption')
                                    ->label('Подпись')
                                    ->maxLength(255),
                            ])
                            ->defaultItems(1)
                            ->reorderable()
                            ->columns(2)
                            ->visible(fn (Get $get) => $get('type') === 'gallery'),
                        Forms\Components\TextInput::make('video_url')
                            ->label('URL видео')
                            ->url()
                            ->visible(fn (Get $get) => $get('type') === 'video'),
                        Forms\Components\Textarea::make('video_caption')
                            ->label('Подпись к видео')
                            ->rows(3)
                            ->visible(fn (Get $get) => $get('type') === 'video'),
                        Forms\Components\TextInput::make('cta_url')
                            ->label('URL кнопки')
                            ->url()
                            ->visible(fn (Get $get) => $get('type') === 'cta'),
                        Forms\Components\TextInput::make('cta_label')
                            ->label('Текст кнопки')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === 'cta'),
                        Forms\Components\Textarea::make('cta_description')
                            ->label('Описание')
                            ->rows(3)
                            ->visible(fn (Get $get) => $get('type') === 'cta'),
                        Forms\Components\TextInput::make('url')
                            ->label('URL')
                            ->url()
                            ->visible(fn (Get $get) => $get('type') === 'link'),
                        Forms\Components\TextInput::make('label')
                            ->label('Текст ссылки')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === 'link'),
                        Forms\Components\Textarea::make('description')
                            ->label('Описание ссылки')
                            ->rows(3)
                            ->visible(fn (Get $get) => $get('type') === 'link'),
                        Forms\Components\Textarea::make('quote')
                            ->label('Текст отзыва')
                            ->rows(4)
                            ->visible(fn (Get $get) => $get('type') === 'testimonial'),
                        Forms\Components\TextInput::make('author')
                            ->label('Автор')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === 'testimonial'),
                        Forms\Components\TextInput::make('role')
                            ->label('Должность')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === 'testimonial'),
                        Forms\Components\TextInput::make('author_company')
                            ->label('Компания')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === 'testimonial'),
                        Forms\Components\TextInput::make('avatar_url')
                            ->label('URL аватара')
                            ->url()
                            ->visible(fn (Get $get) => $get('type') === 'testimonial'),
                    ])
                    ->collapsed()
                    ->collapsible()
                    ->reorderable()
                    ->defaultItems(0)
                    ->addActionLabel('+ Добавить блок')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('logo_url')
                    ->label('Logo URL')
                    ->url(),
                Forms\Components\TextInput::make('cover_url')
                    ->label('Cover URL')
                    ->url(),
                Forms\Components\TagsInput::make('tags')
                    ->label('Теги'),
            ])->columns(2),

            Section::make('Публикация')->schema([
                Forms\Components\TextInput::make('sort')
                    ->label('Сортировка')
                    ->numeric()
                    ->minValue(0)
                    ->default(100),
                Forms\Components\Toggle::make('is_featured')
                    ->label('Избранный'),
                Forms\Components\Toggle::make('is_published')
                    ->label('Опубликован')
                    ->live(),
                Forms\Components\DateTimePicker::make('published_at')
                    ->label('Дата публикации')
                    ->visible(fn ($get) => (bool) $get('is_published')),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('logo_url')
                ->label('')
                ->circular(),
            Tables\Columns\TextColumn::make('title')
                ->label('Кейс')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('company_name')
                ->label('Компания')
                ->searchable(),
            Tables\Columns\TextColumn::make('industry')
                ->label('Отрасль')
                ->toggleable(),
            Tables\Columns\TextColumn::make('slug')
                ->copyable()
                ->toggleable(),
            Tables\Columns\IconColumn::make('is_published')
                ->boolean(),
            Tables\Columns\IconColumn::make('is_featured')
                ->boolean(),
            Tables\Columns\TextColumn::make('published_at')
                ->dateTime()
                ->sortable(),
            Tables\Columns\TextColumn::make('sort')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Tables\Filters\TernaryFilter::make('is_published'),
            Tables\Filters\TernaryFilter::make('is_featured'),
        ]);
        // ->actions([
        //     ViewAction::make(),
        //     EditAction::make(),
        // ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanyCases::route('/'),
            'create' => Pages\CreateCompanyCase::route('/create'),
            'edit' => Pages\EditCompanyCase::route('/{record}/edit'),
            // 'view' => Pages\ViewCompanyCase::route('/{record}'),
        ];
    }
}
