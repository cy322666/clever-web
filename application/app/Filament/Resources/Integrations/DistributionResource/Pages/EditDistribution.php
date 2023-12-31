<?php

namespace App\Filament\Resources\Integrations\DistributionResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\Distribution\TransactionsResource;
use App\Filament\Resources\Integrations\DistributionResource;
use App\Filament\Resources\Integrations\Tilda\FormResource;
use App\Helpers\Actions\UpdateButton;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDistribution extends EditRecord
{
    protected static string $resource = DistributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::getAction($this->record),

            Actions\Action::make('instruction')
                ->label('Инструкция')
                ->url('https://youtu.be/b5aPWhK2oc8?si=nSGpU-XRSlTRScNQ')
                ->openUrlInNewTab(),

            Actions\Action::make('logs')
                ->label('Логи')
                ->url(TransactionsResource::getUrl()),

            Actions\Action::make('schedule')
                ->label('График')
                ->url(ScheduleResource::getUrl())//['record' => $this->getRecord()->id])
        ];
    }

    protected function mutateFormDataBeforeFill(array $data) : array
    {
        if ($data['settings']) {
            $data['settings'] = json_decode($data['settings'], true);

            for($i = 0; count($data['settings']) !== $i; $i++) {

                $data['settings'][$i]['link'] = \route('distribution.hook', [
                    'user' => Auth::user()->uuid,
                    'template' => $i,
                ]);
            }
        }

        return $data;
    }
}
