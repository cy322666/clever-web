<?php

namespace App\Filament\Resources\Integrations\Distribution\ScheduleResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\DistributionResource;
use App\Jobs\Bizon\ViewerSend;
use App\Models\amoCRM\Staff;
use App\Models\Integrations\Bizon\Webinar;
use App\Models\Integrations\Distribution\Scheduler;
use App\Models\User;
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

    //страница с индивидуальным графиком

    public static function getEloquentQuery(): Builder
    {
        $query = Staff::query();

//        if (!Auth::user()->is_root) {
//
//            $query->where('user_id', Auth::id());
//        }

        return $query;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
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

//                ::make('active')
//                    ->label('Создан')
//                    ->sortable(),
//
//                Tables\Columns\TextColumn::make('count')
//                    ->label('Зрителей')
//                    ->state(
//                        fn(Webinar $webinar) => $webinar->viewers()->count()
//                    ),
//
//                Tables\Columns\TextColumn::make('success')
//                    ->label('Отправлено')
//                    ->state(
//                        fn(Webinar $webinar) => $webinar->viewers()->where('status', 1)->count()
//                    ),

//                Tables\Columns\TextColumn::make('fails')
//                    ->label('Ошибок')
//                    ->state(
//                        fn(Webinar $webinar) => $webinar->viewers()->where('status', 2)->count()
//                    ),

                //TODO relationship methods
//                Tables\Columns\BooleanColumn::make('status')
//                    ->label('Выгружен')
//                    ->state(fn(Webinar $webinar) =>
//                        $webinar
//                            ->viewers()
//                            ->where('status', 1)
//                            ->count() ==
//                        $webinar
//                            ->viewers()
//                            ->count()
//                    )
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([
                Action::make('scheduleSave')
                    ->form([

                        Section::make('Настройки графика')
                            ->description('Заполните выходные и рабочие периоды')
                            ->schema([
                                Repeater::make('settings')
                                    ->name('')
                                    ->schema([

                                        Flatpickr::make('period')
                                            ->name('Период')
                                            ->allowInput() // Allow a user to manually input the date in the textbox (make the textbox editable)
                                            ->hourIncrement(1) // Intervals of incrementing hours in a time picker
                                            ->minuteIncrement(10) // Intervals of minute increment in a time picker
                                            ->enableSeconds(false) // Enable seconds in a time picker
                                            ->animate() // Animate transitions in the datepicker.
                                            ->dateFormat('Y-m-d H:i') // Set the main date format
                                            ->ariaDateFormat('Y-m-d H:i') // Aria
                                            //TODO можно тему ебнуть смену
                                            ->theme(\Coolsam\FilamentFlatpickr\Enums\FlatpickrTheme::DARK) // Set the datepicker theme (applies for all the date-pickers in the current page). For type sanity, Checkout the FlatpickrTheme enum class for a list of allowed themes.
                                            ->mode(\Coolsam\FilamentFlatpickr\Enums\FlatpickrMode::RANGE) // Set the mode as single, range or multiple. Alternatively, you can just use ->range() or ->multiple()
                                            ->monthSelectorType(\Coolsam\FilamentFlatpickr\Enums\FlatpickrMonthSelectorType::STATIK)
                                            ->nextArrow('>')
                                            ->prevArrow('<')
                                            ->minTime(now()->format('H:i:s'))

                                            ->enableTime()
                                            ->multiple()


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
            ], ActionsPosition::BeforeColumns)
            ->paginated([20, 30, 'all'])
            ->poll('30s')
            ->bulkActions([
//                Tables\Actions\BulkAction::make('dispatched')
//                    ->action(function (Collection $collection) {
//
//                        $collection->each(function (Webinar $webinar) {
//
//                            $user    = $webinar->user;
//                            $setting = $user->bizon_settings;
//
//                            $viewers = $webinar
//                                ->viewers()
//                                ->where('status', 0)
//                                ->get();
//
//                            $delay = 0;
//
//                            foreach ($viewers as $viewer) {
//
//                                ViewerSend::dispatch($viewer, $setting, $user->account)->delay(++$delay);
//                            }
//                        });
//                    })
//                    ->label('Догрузить'),
            ])
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
