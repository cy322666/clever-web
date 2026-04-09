<?php

namespace App\Filament\Resources\Integrations\DistributionResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\Distribution\TransactionsResource;
use App\Filament\Resources\Integrations\DistributionResource;
use App\Filament\Resources\Integrations\Tilda\FormResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EditDistribution extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = DistributionResource::class;

    protected function getHeaderActions(): array
    {
        return [

            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                $this->record->amoAccount(true),
                fn () => $this->amocrmUpdate(),
            ),

            Actions\Action::make('logs')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(TransactionsResource::getUrl()),

            Actions\Action::make('schedule')
                ->label('График')
                ->icon('heroicon-o-calendar-days')
                ->url(ScheduleResource::getUrl())//['record' => $this->getRecord()->id])
        ];
    }

    protected function mutateFormDataBeforeFill(array $data) : array
    {
        if (!empty($data['settings'])) {
            $settings = is_string($data['settings'])
                ? json_decode($data['settings'], true)
                : (array)$data['settings'];

            $settings = is_array($settings) ? $settings : [];

            foreach ($settings as $index => &$setting) {
                $setting = is_array($setting) ? $setting : [];
                $queueUuid = $setting['queue_uuid'] ?? null;
                if (!is_string($queueUuid) || $queueUuid === '') {
                    $queueUuid = (string)Str::uuid();
                }
                $setting['queue_uuid'] = $queueUuid;
                $setting['link'] = route('distribution.hook', [
                    'user' => Auth::user()->uuid,
                    'template' => $queueUuid,
                ]);
            }
            unset($setting);

            $data['settings'] = $settings;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $settings = $data['settings'] ?? [];
        $settings = is_array($settings) ? $settings : [];

        foreach ($settings as &$setting) {
            $setting = is_array($setting) ? $setting : [];
            $setting['queue_uuid'] = $setting['queue_uuid'] ?? (string)Str::uuid();
            unset($setting['link']);
        }
        unset($setting);

        $data['settings'] = json_encode(array_values($settings), JSON_UNESCAPED_UNICODE);

        return $data;
    }
}
