<?php

namespace App\Filament\Resources\Integrations\ContactMergeResource\Pages;

use App\Filament\Resources\Integrations\ContactMergeResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Jobs\ContactMerge\RunMerge;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditContactMerge extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = ContactMergeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                Auth::user()->account,
                fn () => $this->amocrmUpdate(),
            ),

            Action::make('run')
                ->label('Запустить склейку')
                ->icon('heroicon-o-play')
                ->action(function () {
                    if (!$this->record->active) {
                        Notification::make()
                            ->title('Интеграция выключена')
                            ->warning()
                            ->send();

                        return;
                    }

                    RunMerge::dispatch($this->record, Auth::user()->account);

                    Notification::make()
                        ->title('Склейка запущена')
                        ->success()
                        ->send();
                }),

            Action::make('list')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(ContactMergeResource::getUrl('list')),
        ];
    }
}
