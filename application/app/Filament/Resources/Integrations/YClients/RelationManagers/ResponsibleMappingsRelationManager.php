<?php

namespace App\Filament\Resources\Integrations\YClients\RelationManagers;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\ResponsibleMapping;
use App\Models\Integrations\YClients\YClientsUser;
use App\Services\YClients\YClients;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResponsibleMappingsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'responsibleMappings';

    protected static ?string $title = 'Соответствие ответственных amoCRM и пользователей YClients';

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->description(
                'Для каждого ответственного amoCRM выберите одного или несколько пользователей YClients.'
            )
            ->columns([
                Tables\Columns\TextColumn::make('amo_user_name')
                    ->label('Пользователь amoCRM')
                    ->state(
                        fn(ResponsibleMapping $record): ?string => $this->amoStaffOptions(
                        )[$record->amo_user_id] ?? null
                    )
                    ->placeholder('Пользователь amoCRM не найден'),

                Tables\Columns\ViewColumn::make('yc_user_keys')
                    ->label('Пользователи YClients')
                    ->view('filament.resources.integrations.yclients.columns.responsible-users-select')
                    ->viewData(fn(ResponsibleMapping $record): array => [
                        'groupedOptions' => $this->yclientsUserOptions($record),
                    ]),

                Tables\Columns\ToggleColumn::make('active')
                    ->label('Активно'),
            ])
            ->defaultSort('amo_user_id')
            ->headerActions([
                Action::make('sync_yclients_users')
                    ->label('Обновить пользователей')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn() => $this->syncUsers()),
            ])
            ->recordActions([])
            ->bulkActions([])
            ->paginated([20, 50, 100])
            ->emptyStateHeading('Пользователи amoCRM ещё не загружены')
            ->emptyStateDescription('Нажмите «Обновить пользователей».')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    public function updateResponsibleMappingUsers(int|string $mappingId, array $ycUserKeys): void
    {
        $mapping = ResponsibleMapping::query()
            ->where('setting_id', $this->getOwnerRecord()->id)
            ->findOrFail($mappingId);

        $reservedKeys = $mapping->reservedUserKeysByOtherMappings();
        $allowedKeys = YClientsUser::query()
            ->where('setting_id', $this->getOwnerRecord()->id)
            ->get()
            ->map(fn(YClientsUser $user): string => $user->key())
            ->diff($reservedKeys);

        $mapping->update([
            'yc_user_keys' => collect($ycUserKeys)
                ->map(fn(mixed $key): string => (string)$key)
                ->intersect($allowedKeys)
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    private function syncUsers(): void
    {
        $setting = $this->getOwnerRecord();
        $yc = new YClients($setting);
        $apiErrors = 0;
        $ycSaved = 0;

        $companyIds = Record::query()
            ->where('setting_id', $setting->id)
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id')
            ->map(fn($companyId): string => (string)$companyId);

        foreach ($companyIds as $companyId) {
            try {
                foreach ($yc->getCompanyUsers($companyId) as $user) {
                    $ycUserId = YClients::companyUserId($user);

                    if ($ycUserId === '') {
                        continue;
                    }

                    YClientsUser::query()->updateOrCreate(
                        [
                            'setting_id' => $setting->id,
                            'company_id' => $companyId,
                            'yc_user_id' => $ycUserId,
                        ],
                        [
                            'yc_user_name' => YClients::companyUserName($user) ?: $ycUserId,
                        ]
                    );
                    $ycSaved++;
                }
            } catch (Throwable $e) {
                $apiErrors++;
                Log::warning('YClients users sync failed for responsible mapping.', [
                    'setting_id' => $setting->id,
                    'company_id' => $companyId,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
        }

        $amoCreated = 0;

        foreach ($this->amoStaffOptions()->keys() as $amoUserId) {
            $mapping = ResponsibleMapping::query()->firstOrCreate(
                [
                    'setting_id' => $setting->id,
                    'amo_user_id' => $amoUserId,
                ],
                [
                    'yc_user_keys' => [],
                    'active' => true,
                ]
            );

            if ($mapping->wasRecentlyCreated) {
                $amoCreated++;
            }
        }

        $notification = Notification::make()
            ->title('Пользователи обновлены')
            ->body(
                sprintf(
                    'Пользователей YClients сохранено: %d. Добавлено строк amoCRM: %d. Запросов YClients: %d. Ошибок API: %d.',
                    $ycSaved,
                    $amoCreated,
                    $companyIds->count(),
                    $apiErrors
                )
            );

        ($apiErrors > 0 ? $notification->warning() : $notification->success())->send();
    }

    private function amoStaffOptions(): Collection
    {
        return Staff::query()
            ->where('user_id', $this->getOwnerRecord()->user_id)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'staff_id');
    }

    private function yclientsUserOptions(ResponsibleMapping $mapping): array
    {
        $currentKeys = collect($mapping->yc_user_keys ?? []);
        $reservedKeys = collect($mapping->reservedUserKeysByOtherMappings());

        return YClientsUser::query()
            ->where('setting_id', $this->getOwnerRecord()->id)
            ->orderBy('company_name')
            ->orderBy('yc_user_name')
            ->get()
            ->filter(fn(YClientsUser $user): bool => $currentKeys->contains($user->key())
                || !$reservedKeys->contains($user->key()))
            ->groupBy(fn(YClientsUser $user): string => $user->company_name ?: $user->company_id)
            ->map(fn(Collection $users): array => $users
                ->mapWithKeys(fn(YClientsUser $user): array => [
                    $user->key() => $user->yc_user_name ?: $user->yc_user_id,
                ])
                ->all())
            ->all();
    }

}
