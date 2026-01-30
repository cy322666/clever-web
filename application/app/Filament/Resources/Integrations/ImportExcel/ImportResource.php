<?php

namespace App\Filament\Resources\Integrations\ImportExcel;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages\EditImport;
use App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages\ImportPage;
use App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages\ListImport;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Imports\amoCRM\ExcelImport;
use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use BackedEnum;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ImportResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = ImportSetting::class;

    protected static ?string $slug = 'integrations/import-excel';

    protected static bool $shouldRegisterNavigation = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $recordTitleAttribute = 'Импорт в amoCRM';

    protected static ?string $navigationLabel = 'Импорт из Excel';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Основные настройки')
                    ->schema([

                        Forms\Components\Select::make('default_pipeline_id')
                            ->label('Воронка по умолчанию')
                            ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                            ->searchable()
                            ->nullable(),

                        Forms\Components\Select::make('default_status_id')
                            ->label('Статус по умолчанию')
                            ->options(Status::getTriggerStatuses())
                            ->searchable()
                            ->nullable(),

                        Forms\Components\Select::make('default_responsible_user_id')
                            ->label('Ответственный по умолчанию')
                            ->options(Staff::getWithUser()->pluck('name', 'staff_id'))
                            ->searchable()
                            ->nullable(),

                        Forms\Components\Select::make('special_field')
                            ->label('Стандартное поле')
                            ->options(function ($get) {
                                $entityType = $get('entity_type');

                                $leadFields = [
                                    'name' => 'Название сделки',
                                    'sale' => 'Сумма',
                                    'status_id' => 'Статус',
                                    'pipeline_id' => 'Воронка',
                                    'responsible_user_id' => 'Ответственный',
//                                            'created_at' => 'Дата создания',
//                                            'closed_at' => 'Дата закрытия',
                                ];

                                $contactFields = [
                                    'name' => 'Имя',
                                    'phone' => 'Телефон',
                                    'email' => 'Email',
                                    'position' => 'Должность',
                                    'responsible_user_id' => 'Ответственный',
                                ];

                                $companyFields = [
                                    'name' => 'Название компании',
                                    'responsible_user_id' => 'Ответственный',
                                ];

                                return match ($entityType) {
                                    'lead' => $leadFields,
                                    'contact' => $contactFields,
                                    'company' => $companyFields,
                                    default => [],
                                };
                            })
                            ->searchable()
                            ->reactive()
                            ->helperText('Выберите стандартное поле или оставьте пустым для кастомного')
                            ->nullable(),

//                        Forms\Components\TextInput::make('default_lead_name')
//                            ->label('Название сделки по умолчанию')
//                            ->placeholder('Новая сделка из импорта')
//                            ->nullable(),

                        Section::make('Поведение при дублях')
                            ->schema([
//                        Forms\Components\Toggle::make('check_duplicates')
//                            ->label('Проверять дубли')
//                            ->default(true)
//                            ->helperText('Искать существующие контакты и сделки перед созданием новых')
//                            ->reactive(),

                                Forms\Components\Toggle::make('update_existing_contacts')
                                    ->label('Обновлять существующие контакты')
                                    ->default(true)
                                    ->helperText('Если контакт найден по телефону/email, обновить его данные')
                                    ->visible(fn($get) => $get('check_duplicates')),

                                Forms\Components\Toggle::make('update_existing_leads')
                                    ->label('Обновлять существующие сделки')
                                    ->default(false)
                                    ->helperText('Если сделка найдена, обновить её данные')
                                    ->visible(fn($get) => $get('check_duplicates')),

//                        Forms\Components\Toggle::make('link_contact_to_company')
//                            ->label('Связывать контакт с компанией')
//                            ->default(true)
//                            ->helperText('Если компания найдена, связать с ней контакт'),

                                Forms\Components\TextInput::make('tag')
                                    ->label('Тег для сущностей')
                                    ->helperText('Будет добавлен к контактам и сделкам после импорта')
                                    ->nullable(),
                            ]),
//                            ->columns(1),
                        Section::make('Соотношение полей')
                            ->description('Настройте соответствие столбцов Excel и полей в amoCRM')
                            ->schema([
                                Forms\Components\Repeater::make('fields_mapping')
                                    ->label('Маппинг полей')
                                    ->schema([
                                        Forms\Components\TextInput::make('excel_column')
                                            ->label('Столбец Excel')
                                            ->placeholder('Название столбца')
                                            ->required()
                                            ->helperText('Название столбца'),

                                        Forms\Components\Select::make('entity_type')
                                            ->label('Тип сущности')
                                            ->options([
                                                'lead' => 'Сделка',
                                                'contact' => 'Контакт',
                                                'company' => 'Компания',
                                            ])
                                            ->required()
                                            ->native(false),

                                        Forms\Components\Select::make('field_id')
                                            ->label('Кастомное поле в amoCRM')
                                            ->options(function ($get) {
                                                $entityType = $get('entity_type');
                                                $specialField = $get('special_field');

                                                // Если выбрано стандартное поле, не показываем кастомные
                                                if ($specialField) {
                                                    return [];
                                                }

                                                if (!$entityType) {
                                                    return [];
                                                }

                                                $fieldType = match ($entityType) {
                                                    'lead' => 'leads',
                                                    'contact' => 'contacts',
                                                    'company' => 'companies',
//                                            default => null,
                                                };

                                                if (!$fieldType) {
                                                    return [];
                                                }

                                                return Field::query()
                                                    ->where('user_id', Auth::id())
                                                    ->where('entity_type', $fieldType)
                                                    ->pluck('name', 'id');
                                            })
                                            ->searchable()
                                            ->required(fn($get) => !$get('special_field'))
                                            ->reactive()
                                            ->visible(fn($get) => !$get('special_field'))
                                            ->helperText('Выберите поле amoCRM'),
                                    ])
                                    ->columns(4)
                                    ->collapsible()
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->addActionLabel('+ Добавить поле')
                                    ->itemLabel(
                                        fn(array $state): ?string => ($state['excel_column'] ?? 'Столбец') . ' → ' .
                                            match ($state['entity_type'] ?? null) {
                                                'lead' => 'Сделка',
                                                'contact' => 'Контакт',
                                                'company' => 'Компания',
                                                default => '?'
                                            } . ' (' . ($state['special_field'] ?? 'поле') . ')'
                                    ),
                            ]),

                        Section::make('Загрузка файла')
                            ->description('Загрузите Excel файл для импорта данных в amoCRM')
                            ->schema([
                                FileUpload::make('file')
                                    ->label('Excel файл')
                                    ->acceptedFileTypes(
                                        [
                                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                            'application/vnd.ms-excel'
                                        ]
                                    )
                                    ->directory('imports/amocrm')
                                    ->visibility('private')
                                    ->required()
                                    ->helperText('Поддерживаются файлы .xlsx и .xls'),
                            ]),

                    ]),
//                    ->columns(2),


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
                //
            ])
            ->bulkActions([
                //
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
            'edit' => EditImport::route('/{record}/edit'),
            'list' => ListImport::route('/list'),
        ];
    }

    public static function getTransactions(): int
    {
        return ImportRecord::query()
            ->where('user_id', \Illuminate\Support\Facades\Auth::id())
            ->count();
    }
}
