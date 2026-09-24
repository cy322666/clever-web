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
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FinderResource extends Resource
{
    use SettingResource, TenantResource;

    protected static ?string $model = Setting::class;

    protected static ?string $recordTitleAttribute = 'Контроль ответов';

    protected static ?string $modelLabel = 'Контроль ответов';

    protected static ?string $pluralModelLabel = 'Контроль ответов';

    protected static ?string $slug = 'integrations/finder';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->hiddenLabel()->extraAttributes(['class' => 'self-start h-fit'])->schema([
                Fieldset::make('Рабочее время')->schema([
                    Toggle::make('settings.working_time')->label('Учитывать рабочее время')->live(),
                    Select::make('settings.timezone')->label('Часовой пояс')->searchable()->required()
                        ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers())),
                    Repeater::make('settings.schedule')->label('Расписание')->addActionLabel('Добавить расписание')
                        ->visible(fn (Get $get) => (bool) $get('settings.working_time'))->minItems(1)->maxItems(21)->reorderable(false)
                        ->schema([
                            CheckboxList::make('days')->label('Дни недели')->options([1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'])
                                ->columns(['default' => 2, 'sm' => 7])->gridDirection('row')->required()->columnSpanFull(),
                            TimePicker::make('from')->label('С')->seconds(false)->format('H:i')->required(),
                            TimePicker::make('to')->label('До')->seconds(false)->format('H:i')->required(),
                        ])->columns(2)->columnSpanFull()->helperText('Поддерживаются ночные смены. Одинаковое время начала и окончания означает сутки.'),
                ])->columns(2),
                Fieldset::make('Если не было ответа')->schema([
                    Group::make()->schema([
                        TextInput::make('settings.hours')->label('Часы')->numeric()->integer()->minValue(0)->maxValue(168)->required(),
                        TextInput::make('settings.minutes')->label('Минуты')->numeric()->integer()->minValue(0)->maxValue(59)->required(),
                        TextInput::make('settings.max_attempts')->label('Повторить до')->suffix('раз')->numeric()->integer()->minValue(1)->maxValue(100)->required(),
                    ])->columns(['default' => 1, 'sm' => 3])->columnSpanFull(),
                    Toggle::make('settings.run_workflow')->label('Запустить сценарий')->live()->columnSpanFull(),
                    self::workflowSelect('settings.workflow_id', 'Сценарий при просрочке')
                        ->visible(fn (Get $get) => (bool) $get('settings.run_workflow'))->required(fn (Get $get) => (bool) $get('settings.run_workflow'))->columnSpanFull(),
                    Toggle::make('settings.create_task')->label('Поставить задачу')->live()->columnSpanFull(),
                    Group::make()->visible(fn (Get $get) => (bool) $get('settings.create_task'))->schema([
                        Select::make('settings.responsible_user_id')->label('Для кого')->searchable()->selectablePlaceholder(false)
                            ->formatStateUsing(fn ($state) => blank($state) ? 0 : $state)
                            ->options(fn () => [0 => 'Текущий ответственный'] + Staff::query()->where('user_id', Auth::id())->where('active', true)->pluck('name', 'staff_id')->all())
                            ->helperText('Ответственный за сделку беседы. Если сделки нет, ответственный за контакт.'),
                        Select::make('settings.task_type_id')->label('Тип задачи')->searchable()->required()
                            ->options(fn () => \App\Services\Workflows\WorkflowNodeReferences::options('task_types') ?: [1 => 'Связаться', 2 => 'Встреча']),
                        TextInput::make('settings.task_text')->label('Текст задачи')->required()->maxLength(1000),
                        TextInput::make('settings.task_due_minutes')->label('Срок задачи, минут после создания')->numeric()->integer()->minValue(1)->maxValue(10080)->required(),
                    ])->columns(2)->columnSpanFull(),
                ])->columns(2),
                Fieldset::make('Если ответили после срабатывания')->schema([
                    Toggle::make('settings.run_reply_workflow')->label('Запустить сценарий после ответа')->live()->columnSpanFull(),
                    self::workflowSelect('settings.reply_workflow_id', 'Сценарий после ответа')
                        ->visible(fn (Get $get) => (bool) $get('settings.run_reply_workflow'))->required(fn (Get $get) => (bool) $get('settings.run_reply_workflow'))->columnSpanFull(),
                ]),
            ])->columnSpan(['default' => 1, 'lg' => 2])->columnOrder(['default' => 1, 'lg' => 2]),
            Section::make('Тарифы')->extraAttributes(['class' => 'self-start h-fit'])->compact()->schema([
                View::make('filament.integrations.finder.pricing')->viewData(['cost' => Setting::$cost]),
            ])->columnOrder(['default' => 2, 'lg' => 1]),
        ])->columns(['default' => 1, 'lg' => 3]);
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
