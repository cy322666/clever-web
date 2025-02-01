<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\TableResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\Integrations\Table;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class TableResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Table\Setting::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $slug = 'settings/table';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Таблицы';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Настройки')
                    ->description('Для работы интеграции заполните обязательные поля и выполните настройки')
                    ->schema([
                        Forms\Components\Repeater::make('settings')
                            ->label('Основное')
                            ->schema([

                                Forms\Components\TextInput::make('name_form')
                                    ->label('Название')
                                    ->hint('Для различения документов'),

                                Forms\Components\Select::make('tag')
                                    ->label('Тег/теги для amoCRM'),

                                Forms\Components\FileUpload::make('base_raw')
                                    ->label('База исходник')
                                    ->directory(fn() => 'table/raw/'.Auth::user()->account->subdomain)
                                    ->visibility('public')
                                    ->getUploadedFileNameForStorageUsing(
                                        fn (TemporaryUploadedFile $file): string => (string) str($file->getClientOriginalName())
                                            ->prepend(date('Y-m-d H-i-s').'-'),
                                    ),
                                Forms\Components\FileUpload::make('base_format')
                                    ->label('База обработанная')
                                    ->directory(fn() => 'table/format/'.Auth::user()->account->subdomain)
                                    ->visibility('public')
                                    ->getUploadedFileNameForStorageUsing(
                                        fn (TemporaryUploadedFile $file): string => (string) str($file->getClientOriginalName())
                                            ->prepend(date('Y-m-d H-i-s').'-'),
                                    ),
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('parsing')
                                        ->icon('heroicon-m-star')
                                        ->action(function ($set, $state) {
//
                                    Log::debug(__METHOD__, [$set, $state]);
//                                            Artisan::call('table:parsing', []);
                                        }),
                                ]),
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить базу')
                    ])
            ]);
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
            'edit' => Pages\EditTable::route('/{record}/edit'),
        ];
    }
}
