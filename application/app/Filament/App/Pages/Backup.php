<?php

namespace App\Filament\App\Pages;

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
}
