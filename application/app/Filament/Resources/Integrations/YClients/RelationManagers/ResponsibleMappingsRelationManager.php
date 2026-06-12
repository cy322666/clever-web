<?php

namespace App\Filament\Resources\Integrations\YClients\RelationManagers;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\ResponsibleMapping;
use App\Services\YClients\YClients;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Throwable;

class ResponsibleMappingsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'responsibleMappings';

    protected static ?string $title = 'Ответственные по создателю записи YClients';

    protected static ?string $recordTitleAttribute = 'yc_user_name';

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->description(
                'Новая или свободная активная сделка получает выбранного ответственного. Уже привязанная к записи сделка не переназначается.'
            )
            ->columns([
                Tables\Columns\TextColumn::make('company_name')
                    ->label('Филиал')
                    ->description(fn(ResponsibleMapping $record): string => 'ID: ' . $record->company_id)
                    ->searchable(),

                Tables\Columns\TextColumn::make('yc_user_name')
                    ->label('Кто создал запись в YClients')
                    ->description(fn(ResponsibleMapping $record): string => 'ID: ' . $record->yc_user_id)
                    ->searchable(),

                Tables\Columns\TextColumn::make('amo_user_name')
                    ->label('Ответственный amoCRM')
                    ->state(fn(ResponsibleMapping $record): ?string => Staff::query()
                        ->where('user_id', $this->getOwnerRecord()->user_id)
                        ->where('staff_id', $record->amo_user_id)
                        ->value('name'))
                    ->placeholder('Не выбран'),

                Tables\Columns\IconColumn::make('active')
                    ->label('Активно')
                    ->boolean(),
            ])
            ->defaultSort('company_name')
            ->headerActions([
                Action::make('sync_yclients_users')
                    ->label('Обновить список пользователей')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn() => $this->syncObservedCreators()),

                CreateAction::make()
                    ->label('Добавить вручную')
                    ->form($this->mappingForm()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Сопоставить')
                    ->form($this->mappingForm()),

                DeleteAction::make(),
            ])
            ->bulkActions([])
            ->paginated([20, 50, 100])
            ->emptyStateHeading('Создатели записей ещё не загружены')
            ->emptyStateDescription('Нажмите «Обновить список пользователей», затем выберите ответственных amoCRM.')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    private function syncObservedCreators(): void
    {
        $setting = $this->getOwnerRecord();
        $yc = new YClients($setting);
        $created = 0;
        $updated = 0;
        $failed = 0;

        $creators = Record::query()
            ->where('setting_id', $setting->id)
            ->whereNotNull('created_user_id')
            ->where('created_user_id', '!=', 0)
            ->select(['company_id', 'created_user_id'])
            ->distinct()
            ->get();

        foreach ($creators as $creator) {
            try {
                $companyId = (string)$creator->company_id;
                $ycUserId = (string)$creator->created_user_id;
                $companyUser = $yc->findCompanyUserById($companyId, $ycUserId);
                $staff = $yc->findStaffByUserId($companyId, $ycUserId);

                $mapping = ResponsibleMapping::query()->firstOrNew([
                    'setting_id' => $setting->id,
                    'company_id' => $companyId,
                    'yc_user_id' => $ycUserId,
                ]);

                $wasRecentlyCreated = !$mapping->exists;
                $mapping->company_name = $yc->getBranchTitle($companyId) ?: $mapping->company_name ?: $companyId;
                $mapping->yc_user_name = data_get($companyUser, 'name')
                    ?: data_get($companyUser, 'full_name')
                        ?: data_get($staff, 'name')
                            ?: $mapping->yc_user_name
                                ?: $ycUserId;
                if ($wasRecentlyCreated) {
                    $mapping->active = true;
                }

                $mapping->save();

                $wasRecentlyCreated ? $created++ : $updated++;
            } catch (Throwable) {
                $failed++;
            }
        }

        $notification = Notification::make()
            ->title('Список создателей записей обновлён')
            ->body(sprintf('Добавлено: %d. Обновлено: %d. Ошибок API: %d.', $created, $updated, $failed));

        ($failed > 0 ? $notification->warning() : $notification->success())->send();
    }

    private function mappingForm(): array
    {
        return [
            TextInput::make('company_name')
                ->label('Филиал')
                ->disabled()
                ->dehydrated(),

            TextInput::make('company_id')
                ->label('ID филиала YClients')
                ->required(),

            TextInput::make('yc_user_name')
                ->label('Кто создал запись в YClients')
                ->disabled()
                ->dehydrated(),

            TextInput::make('yc_user_id')
                ->label('ID пользователя YClients')
                ->required(),

            Select::make('amo_user_id')
                ->label('Ответственный amoCRM')
                ->options(fn() => Staff::query()
                    ->where('user_id', $this->getOwnerRecord()->user_id)
                    ->where('active', true)
                    ->orderBy('name')
                    ->pluck('name', 'staff_id'))
                ->searchable()
                ->required(),

            Toggle::make('active')
                ->label('Использовать соответствие')
                ->default(true),
        ];
    }
}
