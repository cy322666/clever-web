<?php

namespace App\Filament\Resources\Integrations\ImportExcel;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages\EditImport;
use App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages\ListImport;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use App\Services\ImportExcel\ExcelImport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Novadaemon\FilamentPrettyJson\Form\PrettyJsonField;

class ImportResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = ImportSetting::class;

    protected static ?string $slug = 'integrations/import-excel';

    protected static bool $shouldRegisterNavigation = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $recordTitleAttribute = 'Импорт Excel';

    protected static ?string $navigationLabel = 'Импорт из Excel';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make()
                    ->hiddenLabel()
                    ->schema([

                        Section::make()
                            ->label('Инструкция')
                            ->schema([

                                TextEntry::make('instruction')
                                    ->hiddenLabel()
                                    ->bulleted()
                                    ->size(TextSize::Small)
                                    ->state(fn() => ImportSetting::$instruction),

                                TextEntry::make('ps')
                                    ->hiddenLabel()
                                    ->size(TextSize::ExtraSmall)
                                    ->state(
                                        fn(
                                        ) => 'Если есть сложности то смотри Видео инструкцию (кнопка справа) или напиши в чат ниже'
                                    ),
                            ]),

                        Section::make('Стандартные параметры')
                            ->schema([
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

                                Forms\Components\TextInput::make('default_sale')
                                    ->label('Бюджет')
                                    ->nullable(),

                                Forms\Components\TextInput::make('contact_name')
                                    ->label('Название контакта')
                                    ->nullable(),

                                Forms\Components\TextInput::make('company_name')
                                    ->label('Название компании')
                                    ->nullable(),

                                Forms\Components\TextInput::make('lead_name')
                                    ->label('Название сделки')
                                    ->nullable(),
                            ])->columns(2),

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

                                Forms\Components\Toggle::make('update_existing_company')
                                    ->label('Обновлять существующие компании')
                                    ->default(true)
                                    ->helperText('Если контакт найден по телефону/email, обновить его данные')
                                    ->visible(fn($get) => $get('check_duplicates')),

//                                Forms\Components\Toggle::make('update_existing_leads')
//                                    ->label('Обновлять существующие сделки')
//                                    ->default(false)
//                                    ->helperText('Если сделка найдена, обновить её данные')
//                                    ->visible(fn($get) => $get('check_duplicates')),

//                        Forms\Components\Toggle::make('link_contact_to_company')
//                            ->label('Связывать контакт с компанией')
//                            ->default(true)
//                            ->helperText('Если компания найдена, связать с ней контакт'),

                                Forms\Components\TextInput::make('tag')
                                    ->label('Тег для сущностей')
                                    ->nullable(),
                            ]),
//                            ->columns(1),
                        Section::make('Соотношение полей')
                            ->description('Настройте соответствие столбцов Excel и полей в amoCRM')
                            ->schema([
                                Forms\Components\Repeater::make('fields_leads')
                                    ->label('Поля сделки')
                                    ->schema([
                                        Forms\Components\TextInput::make('excel_column')
                                            ->label('Столбец Excel')
                                            ->required(),

                                        Forms\Components\Select::make('field_id')
                                            ->label('Поле в amoCRM')
                                            ->options(Field::getLeadSelectFields())
                                            ->searchable()
                                            ->required(fn($get) => !$get('special_field'))
                                            ->reactive()
                                            ->visible(fn($get) => !$get('special_field')),
                                    ])
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

                                Forms\Components\Repeater::make('fields_contacts')
                                    ->label('Поля контакта')
                                    ->schema([
                                        Forms\Components\TextInput::make('excel_column')
                                            ->label('Столбец Excel')
                                            ->required(),

                                        Forms\Components\Select::make('field_id')
                                            ->label('Поле в amoCRM')
                                            ->options(Field::getContactSelectFields())
                                            ->searchable()
                                            ->required(fn($get) => !$get('special_field'))
                                            ->reactive()
                                            ->visible(fn($get) => !$get('special_field')),
                                    ])
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

                                Forms\Components\Repeater::make('fields_companies')
                                    ->label('Поля компании')
                                    ->schema([
                                        Forms\Components\TextInput::make('excel_column')
                                            ->label('Столбец Excel')
                                            ->required(),

                                        Forms\Components\Select::make('field_id')
                                            ->label('Поле в amoCRM')
                                            ->options(Field::getCompanySelectFields())
                                            ->searchable()
                                            ->required(fn($get) => !$get('special_field'))
                                            ->reactive()
                                            ->visible(fn($get) => !$get('special_field')),
                                    ])
                                    ->collapsible()
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->addActionLabel('+ Добавить поле'),
//                                    ->itemLabel(
//                                        fn(array $state): ?string => ($state['excel_column'] ?? 'Столбец') . ' → ' .
//                                            match ($state['entity_type'] ?? null) {
//                                                'lead' => 'Сделка',
//                                                'contact' => 'Контакт',
//                                                'company' => 'Компания',
//                                                default => '?'
//                                            } . ' (' . ($state['special_field'] ?? 'поле') . ')'
//                                    ),
                            ]),

                        Section::make('Загрузка файла')
                            ->description('Загрузите Excel файл для импорта данных в amoCRM')
                            ->schema([

                                PrettyJsonField::make('headers')
                                    ->label('Заголовки')
                                    ->disabled(),

                                FileUpload::make('file_path')
                                    ->label('Excel файл')
                                    ->acceptedFileTypes([
                                        'application/vnd.ms-excel',
                                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                        'text/csv',
                                        'application/csv',
                                    ])
                                    ->maxSize(10240)
                                    ->disk('exports')
                                    ->preserveFilenames()
                                    ->afterStateUpdated(function ($state, Set $set, ImportSetting $setting) {
                                        if (!$state) {
                                            return;
                                        }

                                        try {
                                            // Получаем имя файла (строку)
                                            $fileName = is_string($state) ? $state : $state->getClientOriginalName();

                                            // Путь к временному файлу (как в вашем рабочем коде)
                                            $tempFilePath = Storage::disk('local')->path($fileName);

                                            // Проверяем временный файл
                                            if (!file_exists($tempFilePath)) {
                                                // Если не нашли во временных, пробуем в exports
                                                $tempFilePath = Storage::disk('exports')->path($fileName);
                                            }

                                            if (!file_exists($tempFilePath)) {
                                                throw new \Exception("Файл не найден: {$fileName}");
                                            }

                                            // Читаем заголовки
                                            $headings = (new HeadingRowImport)->toArray($tempFilePath);
                                            $headers = $headings[0] ?? [];

                                            // Очищаем от пустых значений и преобразуем в JSON
                                            $headers = array_values(array_filter($headers));
                                            $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE);

                                            // Сохраняем
                                            $set('headers', $headersJson);
                                            $setting->headers = $headersJson;

                                        } catch (\Exception $e) {
                                            $set('headers', json_encode(['error' => $e->getMessage()]));
                                        }
                                    })
                                    ->live()
                                    ->helperText('Поддерживаются файлы .xlsx / .xls / .csv (до 10 МБ)'),
                            ]),

                    ])
                    ->columnSpan(2),

                Section::make()
                    ->schema([

                        Action::make('instruction')
                            ->label('Видео инструкция')
                            ->url('')
                            ->disabled()
                            ->openUrlInNewTab(),

                        Section::make()
                            ->schema([

                                TextEntry::make('price6')
                                    ->label('Полгода')
                                    ->money('RU', divideBy: 100)
                                    ->size(TextSize::Medium)
                                    ->state(fn($model): string => $model::$cost['6_month']),

                                TextEntry::make('price12')
                                    ->label('Год')
                                    ->money('RU', divideBy: 100)
                                    ->size(TextSize::Medium)
                                    ->state(fn($model): string => $model::$cost['12_month']),

                                TextEntry::make('bonus')
                                    ->hiddenLabel()
                                    ->size(TextSize::Small)
                                    ->state('*Бесплатно при продлении лицензий через интегратора Clever'),

                                TextEntry::make('bonus2')
                                    ->hiddenLabel()
                                    ->size(TextSize::Small)
                                    ->state('Чтобы узнать больше напишите в чат ниже'),
                            ])
                    ])
                    ->compact()
                    ->columnSpan(1),

            ])->columns(3);
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
