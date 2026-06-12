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
use Illuminate\Support\Facades\Log;
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
        $apiErrors = 0;
        $saveErrors = 0;

        try {
            $creators = Record::query()
                ->where('setting_id', $setting->id)
                ->whereNotNull('created_user_id')
                ->where('created_user_id', '!=', 0)
                ->select(['company_id', 'created_user_id'])
                ->distinct()
                ->get();
        } catch (Throwable $e) {
            $this->logSyncError('load-records', null, $e);

            Notification::make()
                ->title('Не удалось загрузить создателей записей')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($creators->isEmpty()) {
            Notification::make()
                ->title('Создатели записей не найдены')
                ->body('В истории YClients нет записей с заполненным created_user_id.')
                ->warning()
                ->send();

            return;
        }

        $creatorsByCompany = $creators->groupBy(fn(Record $record): string => (string)$record->company_id);

        foreach ($creatorsByCompany as $companyId => $companyCreators) {
            try {
                $companyUsers = collect($yc->getCompanyUsers($companyId))
                    ->filter(fn($user): bool => filled(YClients::companyUserId($user)));
            } catch (Throwable $e) {
                $companyUsers = collect();
                $apiErrors++;
                $this->logSyncError('company-users', $companyId, $e);
            }

            $usersToSave = $companyUsers
                ->map(fn($user): array => [
                    'id' => YClients::companyUserId($user),
                    'name' => YClients::companyUserName($user),
                ])
                ->keyBy('id');

            foreach ($companyCreators as $creator) {
                $ycUserId = (string)$creator->created_user_id;

                if (!$usersToSave->has($ycUserId)) {
                    $usersToSave->put($ycUserId, [
                        'id' => $ycUserId,
                        'name' => null,
                    ]);
                }
            }

            foreach ($usersToSave as $userData) {
                try {
                    $ycUserId = (string)$userData['id'];

                    $mapping = ResponsibleMapping::query()->firstOrNew([
                        'setting_id' => $setting->id,
                        'company_id' => $companyId,
                        'yc_user_id' => $ycUserId,
                    ]);

                    $wasRecentlyCreated = !$mapping->exists;
                    $mapping->company_name = $mapping->company_name ?: $companyId;
                    $mapping->yc_user_name = $userData['name'] ?: $mapping->yc_user_name ?: $ycUserId;
                    if ($wasRecentlyCreated) {
                        $mapping->active = true;
                    }

                    $mapping->save();

                    $wasRecentlyCreated ? $created++ : $updated++;
                } catch (Throwable $e) {
                    $saveErrors++;
                    $this->logSyncError('save-mapping', $companyId, $e);
                }
            }
        }

        $notification = Notification::make()
            ->title('Список создателей записей обновлён')
            ->body(
                sprintf(
                    'Добавлено: %d. Обновлено: %d. Ошибок API: %d. Ошибок сохранения: %d.',
                    $created,
                    $updated,
                    $apiErrors,
                    $saveErrors
                ) . ' Запросов YClients: ' . $creatorsByCompany->count()
            );

        ($apiErrors > 0 || $saveErrors > 0 ? $notification->warning() : $notification->success())->send();
    }

    private function logSyncError(string $stage, ?string $companyId, Throwable $e): void
    {
        Log::warning('YClients responsible mappings sync API error.', [
            'setting_id' => $this->getOwnerRecord()->id,
            'stage' => $stage,
            'company_id' => $companyId,
            'error' => $e->getMessage(),
            'exception' => $e::class,
        ]);
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
