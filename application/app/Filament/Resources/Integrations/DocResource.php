<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\DocResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\Integrations\Docs\Setting;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DocResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $recordTitleAttribute = 'Документы';

    public static function getTransactions(): int
    {
        return 0;
    }
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Настройки')
                    ->hiddenLabel()
                    ->schema([
                        Forms\Components\Repeater::make('settings')
                            ->label('Основное')
                            ->schema([

                                Forms\Components\TextInput::make('link')
                                    ->label('Вебхук ссылка')
                                    ->copyable(),

                                Forms\Components\TextInput::make('name_form')
                                    ->label('Название')
                                    ->hint('Для различения документов'),

                                Forms\Components\TextInput::make('name_file')
                                    ->label('Формат названия документа'),

                                Forms\Components\Select::make('field_amo')
                                    ->label('Поле из amoCRM')
                                    ->options(Auth::user()->amocrm_fields()->pluck('name', 'id'))
                                    ->required(),

                                Forms\Components\FileUpload::make('template')
                                    ->label('Шаблон')
                                    ->directory(fn() => 'docs/templates/'.Auth::user()->account->subdomain)
                                    ->visibility('public')
                                    ->getUploadedFileNameForStorageUsing(
                                        fn (TemporaryUploadedFile $file): string => (string) str($file->getClientOriginalName())
                                            ->prepend(date('Y-m-d H-i-s').'-'),
                                    ),

                                Forms\Components\Radio::make('format')
                                    ->label('Формат файла')
                                    ->options([
                                        'docx' => 'WORD',
                                        'pdf'  => 'PDF',
                                    ])
                                    ->required(),
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить документ')
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'edit' => Pages\EditDoc::route('/{record}/edit'),
        ];
    }
}
