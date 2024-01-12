<?php

namespace App\Filament\Resources\Integrations\Distribution\ScheduleResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\DistributionResource;
use App\Jobs\Bizon\ViewerSend;
use App\Models\amoCRM\Staff;
use App\Models\Integrations\Bizon\Webinar;
use App\Models\Integrations\Distribution\Scheduler;
use App\Models\User;
use Carbon\Carbon;
use Coolsam\FilamentFlatpickr\Enums\FlatpickrMode;
use Coolsam\FilamentFlatpickr\Enums\FlatpickrMonthSelectorType;
use Coolsam\FilamentFlatpickr\Enums\FlatpickrTheme;
use Coolsam\FilamentFlatpickr\Forms\Components\Flatpickr;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ListSchedule extends ListRecords
{
    protected static string $resource = ScheduleResource::class;

    //страница с таблицей сотрудников

    protected function getTableQuery(): ?Builder
    {
        $query = Staff::query();

        if (!Auth::user()->is_root) {

            $query->where('user_id', Auth::id());
        }
        return $query;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->hidden()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                TextColumn::make('group_name')
                    ->label('Отдел')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('login')
                    ->label('Почта')
                    ->wrap()
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([
                Action::make('scheduleSave')
                    ->label('Периоды')
                    ->form([

                        Section::make('Настройки графика')
                            ->description('Заполните выходные и рабочие периоды')
                            ->schema([
                                Repeater::make('settings')
                                    ->name('Периоды')
                                    ->schema([

                                        //TODO можно тему ебнуть смену
                                        Flatpickr::make('period')
                                            ->name('Период')
                                            ->minTime('00:00')
                                            ->use24hr()
                                            ->allowInput() // Allow a user to manually input the date in the textbox (make the textbox editable)
//                                            ->hourIncrement() // Intervals of incrementing hours in a time picker
//                                            ->minuteIncrement(10) // Intervals of minute increment in a time picker
                                            ->enableSeconds(false) // Enable seconds in a time picker
                                            ->animate() // Animate transitions in the datepicker.
                                            ->dateFormat('Y-m-d H:i') // Set the main date format
                                            ->ariaDateFormat('Y-m-d H:i') // Aria
                                            ->theme(FlatpickrTheme::DARK) // Set the datepicker theme (applies for all the date-pickers in the current page). For type sanity, Checkout the FlatpickrTheme enum class for a list of allowed themes.
                                            ->mode(FlatpickrMode::RANGE) // Set the mode as single, range or multiple. Alternatively, you can just use ->range() or ->multiple()
                                            ->monthSelectorType(FlatpickrMonthSelectorType::STATIK)
                                            ->nextArrow()
                                            ->prevArrow('<')
//                                            ->minTime(now()->format('H:i:s'))
                                            ->enableTime()
                                            ->required(),

                                        Radio::make('type')
                                            ->label('Тип периода')
                                            ->options([
                                                'work' => 'Рабочий',
                                                'free' => 'Выходной',
                                            ])
                                            ->required(),
                                    ])
                                    ->columns()
                                    ->collapsible()
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->reorderableWithDragAndDrop(false)
                                    ->addActionLabel('+ Добавить период')
                            ]),
                    ])
                    ->fillForm(function(Staff $staff){

                        $settings = $staff->scheduler->settings ?? null;
                        $dataForm = [];

                        if ($settings)
                            foreach (json_decode($settings, true) as $setting) {

                                $dataForm[] = [
                                    'period' => $setting['at'].' to '.$setting['to'],
                                    'type'   => $setting['type'],
                                ];
                            }

                        return ['settings' => $dataForm];
                    })
                    ->action(function ($data, $record): void {

                        $periods = [];

                        foreach ($data['settings'] as $setting) {

                            $at = trim(explode('to', $setting['period'])[0]);
                            $to = trim(explode('to', $setting['period'])[1]);

                            $periods[] = [
                                'type' => $setting['type'],
                                'at'   => $at,
                                'to'   => $to,
                            ];
                        }

                        Scheduler::query()->updateOrCreate([
                            'staff_id' => $record->id,
                        ],[
                            'settings' => json_encode($periods),
                            'user_id'  => $record->user_id,
                        ]);
                    })
            ])
            ->paginated([20, 30, 'all'])
            ->poll('30s')
            ->emptyStateActions([]);
    }

    public function staffSchedule($data)
    {
        dd($data);
    }

//    protected function getHeaderActions(): array
//    {
//        return [
//            Actions\DeleteAction::make(),
//        ];
//    }
}
