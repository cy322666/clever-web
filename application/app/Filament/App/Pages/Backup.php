<?php

namespace App\Filament\App\Pages;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;

use Illuminate\Contracts\Support\Htmlable;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups as BaseBackups;

class Backup extends BaseBackups
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cpu-chip';

    public function getHeading(): string | Htmlable
    {
        return 'Application Backups';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Core';
    }

    protected function getActions(): array
    {
        return [
            Action::make('Create Backup')
                ->button()
                ->label(__('filament-spatie-backup::backup.pages.backups.actions.create_backup'))
                ->action('openOptionModal')
        ];
    }
}
