<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\AnalyticResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\ActiveLead\Lead;
use App\Models\Integrations\Analytic;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class AnalyticResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Analytic\Setting::class;

    protected static ?string $slug = 'settings/analytic';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Аналитика';

    public static function getTransactions(): int
    {
        return 0;
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Настройки')
                    ->schema([
/*
 * DB_CONNECTION=pgsql
DB_HOST=85.198.111.25
DB_PORT=5420
DB_DATABASE=eurolos
DB_USERNAME=root
DB_PASSWORD=pQLkm8NOk1ssgOBox
 */
//                        Forms\Components\TextInput::make('driver'),
//                        Forms\Components\TextInput::make('host'),
//                        Forms\Components\TextInput::make('database'),
//                        Forms\Components\TextInput::make('login'),
//                        Forms\Components\TextInput::make('password'),

                        Forms\Components\Repeater::make('settings')
                            ->label('Заказы')
                            ->schema([

//                                Forms\Components\Repeater::make('order_settings')
//                                    ->label('Заказы')
//                                    ->schema([

                                Forms\Components\TextInput::make('link_form')
                                    ->label('Вебхук ссылка')
                                    ->copyable()
                                    ->disabled(),

                                Forms\Components\TextInput::make('name_form')
                                    ->label('Название')
                                    ->hint('Для различения настроек форм'),

                                Forms\Components\Select::make('pipeline_id')
                                    ->label('Воронка')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->required(),

                                Forms\Components\Select::make('status_id_order')
                                    ->label('Этап новых заказов')
                                    ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                    ->searchable(),

                                Forms\Components\Select::make('response_user_id_order')
                                    ->label('Отв. по заказам')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->searchable(),

                                Forms\Components\Select::make('status_id_order_close')
                                    ->label('Этап оплаченных заказов')
                                    ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                    ->searchable(),

                                Forms\Components\TextInput::make('tag_order')->label('Тег для заказов'),

                                Forms\Components\Radio::make('utms')
                                    ->label('Действия с метками')
                                    ->options([
                                        'merge'   => 'Дополнять',
                                        'rewrite' => 'Перезаписывать',
                                    ])
                                    ->required(),

                                Forms\Components\Radio::make('is_union')
                                    ->label('Объединять повторные сделки')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

                                Forms\Components\Repeater::make('fields')
                                    ->label('Соотношение полей сделки')
                                    ->schema([

                                        Forms\Components\TextInput::make('field_form')
                                            ->label('Поле из формы')
                                            ->required(),

                                        Forms\Components\Select::make('field_amo')
                                            ->label('Поле из amoCRM')
                                            ->options(Auth::user()->amocrm_fields()->pluck('name', 'id'))
                                            ->required(),
                                    ])
                                    ->columns()
                                    ->collapsible()
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->reorderableWithDragAndDrop(false)
                                    ->addActionLabel('+ Добавить поле')
//                                    ])
//                                    ->columns()
//                                    ->collapsible()
//                                    ->defaultItems(1)
//                                    ->reorderable(false)
//                                    ->reorderableWithDragAndDrop(false)
//                                    ->addActionLabel('+ Добавить форму'),
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить настройку'),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!Auth::user()->is_root) {

            $query->where('user_id', Auth::id());
        }

        return $query;
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
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
            'edit' => Pages\EditAnalytic::route('/{record}/edit'),
        ];
    }

    public static function clearTransactions(int $days = 7): bool
    {
//        Transaction::query()
//            ->where('created_at', '<', Carbon::now()
//                ->subDays($days)
//                ->format('Y-m-d')
//            )->delete();

        return true;
    }
}
