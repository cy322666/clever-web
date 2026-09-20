<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowCredentialResource\Pages;
use App\Models\Workflows\WorkflowCredential;
use App\Services\Workflows\WorkflowCredentials;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Leek\FilamentWorkflows\WorkflowsPlugin;

class WorkflowCredentialResource extends Resource
{
    protected static ?string $model = WorkflowCredential::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Подключения сервисов';

    protected static ?string $modelLabel = 'подключение';

    protected static ?string $pluralModelLabel = 'Подключения сервисов';

    public static function canViewAny(): bool
    {
        return auth()->check() && (bool) auth()->user()?->is_root;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return WorkflowsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = WorkflowsPlugin::get()->getNavigationSort();

        return $sort !== null ? $sort + 2 : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user.accounts');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('Аккаунт')
                    ->description(fn (WorkflowCredential $record): ?string => $record->user?->accounts?->sortByDesc('id')->first()?->subdomain)
                    ->searchable()->sortable(),
                TextColumn::make('provider')->label('Сервис')->badge()
                    ->formatStateUsing(fn (string $state): string => WorkflowCredentials::providers()[$state] ?? $state),
                TextColumn::make('name')->label('Подключение')->searchable()->weight('medium'),
                IconColumn::make('secret_stored')->label('Секрет')->state(true)->boolean()
                    ->trueIcon('heroicon-o-lock-closed')->trueColor('success')->tooltip('Токен сохранён и скрыт'),
                TextColumn::make('updated_at')->label('Обновлено')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Подключений пока нет')
            ->emptyStateDescription('Они появятся после добавления в редакторе сценариев.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWorkflowCredentials::route('/')];
    }
}
