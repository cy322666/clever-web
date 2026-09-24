<?php

namespace App\Filament\Resources\Integrations\Finder;

use App\Filament\Resources\Integrations\Finder\Pages\EditFinder;
use App\Filament\Resources\Integrations\Finder\Pages\FinderHistory;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\Integrations\Finder\Setting;
use App\Models\Workflows\Workflow;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FinderResource extends Resource
{
    use SettingResource, TenantResource;

    protected static ?string $model = Setting::class;

    protected static ?string $recordTitleAttribute = 'Finder';

    protected static ?string $slug = 'integrations/finder';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Finder')->description('Используется общая авторизация amoCRM аккаунта платформы. Проверка ответа выполняется раз в минуту.')->schema([
                Toggle::make('enabled')->label('Отслеживать ответы на диалоги')->live(),
                TextEntry::make('connection')->label('Подключение сообщений')->state(fn (?Setting $record) => $record?->connected_at
                    ? 'События сообщений подключены. Последний вебхук: '.($record->last_webhook_at?->format('d.m.Y H:i') ?? 'ещё не получен')
                    : 'Сохраните настройки и нажмите «Подключить сообщения amoCRM».'),
            ]),
            Section::make('Рабочее время')->schema([
                Toggle::make('settings.working_time')->label('Учитывать рабочее время')->live(),
                Select::make('settings.timezone')->label('Часовой пояс')->searchable()->required()
                    ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers())),
                Repeater::make('settings.schedule')->label('Расписание')->addActionLabel('Добавить расписание')
                    ->visible(fn (Get $get) => (bool) $get('settings.working_time'))->minItems(1)->maxItems(21)->reorderable(false)
                    ->schema([
                        CheckboxList::make('days')->label('Дни недели')->options([1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'])
                            ->columns(7)->required()->columnSpanFull(),
                        TimePicker::make('from')->label('С')->seconds(false)->format('H:i')->required(),
                        TimePicker::make('to')->label('До')->seconds(false)->format('H:i')->required(),
                    ])->columns(2)->helperText('Ночные смены поддерживаются. Одинаковое время начала и окончания означает сутки. Пересечения считаются один раз.'),
            ]),
            Section::make('Если не было ответа')->schema([
                TextInput::make('settings.hours')->label('Часы')->numeric()->integer()->minValue(0)->maxValue(168)->required(),
                TextInput::make('settings.minutes')->label('Минуты')->numeric()->integer()->minValue(0)->maxValue(59)->required(),
                Toggle::make('settings.run_workflow')->label('Запустить сценарий')->live()->columnSpanFull(),
                self::workflowSelect('settings.workflow_id', 'Сценарий при просрочке')
                    ->visible(fn (Get $get) => (bool) $get('settings.run_workflow'))->required(fn (Get $get) => (bool) $get('settings.run_workflow'))->columnSpanFull(),
                Toggle::make('settings.create_task')->label('Поставить задачу')->live()->columnSpanFull(),
                Section::make('Задача')->visible(fn (Get $get) => (bool) $get('settings.create_task'))->schema([
                    Select::make('settings.responsible_user_id')->label('Для кого')->placeholder('Ответственный за сделку или контакт')->searchable()
                        ->options(fn () => Staff::query()->where('user_id', Auth::id())->where('active', true)->pluck('name', 'staff_id')->all()),
                    Select::make('settings.task_type_id')->label('Тип задачи')->searchable()->required()
                        ->options(fn () => \App\Services\Workflows\WorkflowNodeReferences::options('task_types') ?: [1 => 'Связаться', 2 => 'Встреча']),
                    TextInput::make('settings.task_text')->label('Текст задачи')->required()->maxLength(1000),
                    TextInput::make('settings.task_due_minutes')->label('Срок задачи, минут после создания')->numeric()->integer()->minValue(1)->maxValue(10080)->required(),
                ])->columns(2)->columnSpanFull(),
                TextInput::make('settings.max_attempts')->label('Проверять циклично до')->suffix('раз')->numeric()->integer()->minValue(1)->maxValue(100)->required()
                    ->helperText('Действия повторяются через указанный интервал, пока менеджер не ответит клиенту. Новое входящее сообщение не сбрасывает таймер.')->columnSpanFull(),
            ])->columns(2),
            Section::make('Если ответили после срабатывания')->schema([
                Toggle::make('settings.run_reply_workflow')->label('Запустить сценарий после ответа')->live(),
                self::workflowSelect('settings.reply_workflow_id', 'Сценарий после ответа')
                    ->visible(fn (Get $get) => (bool) $get('settings.run_reply_workflow'))->required(fn (Get $get) => (bool) $get('settings.run_reply_workflow')),
                TextEntry::make('help')->hiddenLabel()->state('Используются ваши активные сценарии «Потоков» с запуском через основной вход. Для них нужен действующий доступ к «Потокам». Счётчик проверки: {{finder.attempt}}. Исходящие сообщения менеджера и Salesbot считаются ответом.'),
            ]),
        ])->columns(1);
    }

    private static function workflowSelect(string $field, string $label): Select
    {
        return Select::make($field)->label($label)->searchable()->options(fn () => Workflow::query()
            ->where('user_id', Auth::id())->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([]);
    }

    public static function getPages(): array
    {
        return ['edit' => EditFinder::route('/{record}/edit'), 'history' => FinderHistory::route('/history')];
    }
}
