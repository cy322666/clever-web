<?php

namespace App\Filament\Resources\Core\UserResource\RelationManagers;

use App\Models\App;
use Exception;
use Filament\Actions\ActionGroup;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Throwable;

class AppsRelationManager extends RelationManager
{
    protected static string $relationship = 'apps';

    protected static ?string $title = 'Интеграции';

    protected static ?string $recordTitleAttribute = 'name';

    protected function getTableQuery(): Builder|Relation|null
    {
        $todayStart = now()->startOfDay()->toDateTimeString();
        $soonEnd = now()->addDays(7)->endOfDay()->toDateTimeString();

        return $this->getOwnerRecord()
            ->apps()
            ->where('status', '!=', App::STATE_CREATED)
            ->orderByRaw(
                <<<'SQL'
CASE
    WHEN status = ? THEN 0
    WHEN status = ? AND expires_tariff_at IS NOT NULL AND expires_tariff_at < ? THEN 0
    WHEN status = ? AND expires_tariff_at IS NOT NULL AND expires_tariff_at BETWEEN ? AND ? THEN 1
    WHEN status = ? THEN 2
    WHEN status = ? THEN 3
    ELSE 4
END
SQL,
                [
                    App::STATE_EXPIRES,
                    App::STATE_ACTIVE,
                    $todayStart,
                    App::STATE_ACTIVE,
                    $todayStart,
                    $soonEnd,
                    App::STATE_INACTIVE,
                    App::STATE_ACTIVE,
                ]
            )
            ->orderByRaw('CASE WHEN expires_tariff_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_tariff_at')
            ->orderByDesc('installed_at')
            ->getQuery();
    }

    /**
     * @throws Exception
     */
    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->state(function ($record) {

                        return $record->resource_name::getRecordTitle($record);
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('risk')
                    ->label('Риск')
                    ->badge()
                    ->state(fn(App $app): string => self::riskLabel($app))
                    ->color(fn(App $app): string => self::riskColor($app)),

                Tables\Columns\TextColumn::make('expires_tariff_at')
                    ->label('Истекает')
                    ->state(fn(App $app): string => self::expiresLabel($app))
                    ->color(fn(App $app): string => self::expiresColor($app))
                    ->tooltip(fn(App $app): ?string => self::expiresTooltip($app))
                    ->sortable(),

                Tables\Columns\TextColumn::make('installed_at')
                    ->label('Установлен')
                    ->state(fn(App $app): string => self::installedLabel($app))
                    ->tooltip(fn(App $app): ?string => self::installedTooltip($app))
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Статус')
                    ->state(fn(App $app): int => self::effectiveStatus($app))
                    ->color(fn(int $state): string => match ($state) {
                        App::STATE_CREATED => 'gray',
                        App::STATE_INACTIVE => 'warning',
                        App::STATE_ACTIVE => 'success',
                        App::STATE_EXPIRES => 'danger',
                    })
                    ->formatStateUsing(fn(int $state): string => match ($state) {
                        App::STATE_CREATED => App::STATE_CREATED_WORD,
                        App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
                        App::STATE_ACTIVE => App::STATE_ACTIVE_WORD,
                        App::STATE_EXPIRES => App::STATE_EXPIRES_WORD,
                    })
                    ->sortable(),
            ])
            ->filters([])
            ->headerActions([])
            ->actions([
                ActionGroup::make([
                    Action::make('view')
                        ->label('Настроить')
                        ->url(function (Model $record) {
                            return $record->resource_name::getUrl('edit', ['record' => $record->setting_id]);
                        }),
                ])
                    ->label('Действия')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray'),
            ])
            ->bulkActions([])
            ->paginated(false)
            ->emptyStateHeading('Нет подключенных интеграций')
            ->emptyStateDescription('Перейдите в магазин')
            ->emptyStateIcon('heroicon-o-exclamation-triangle');
    }

    private static function effectiveStatus(App $app): int
    {
        $expiresAt = self::parseDate($app->expires_tariff_at);

        if ((int)$app->status === App::STATE_ACTIVE && $expiresAt?->lt(now()->startOfDay())) {
            return App::STATE_EXPIRES;
        }

        return (int)$app->status;
    }

    private static function riskLabel(App $app): string
    {
        return match (self::effectiveStatus($app)) {
            App::STATE_EXPIRES => 'Критичный',
            App::STATE_INACTIVE => 'Средний',
            App::STATE_ACTIVE => self::isExpiringSoon($app) ? 'Высокий' : 'Низкий',
            default => 'Низкий',
        };
    }

    private static function riskColor(App $app): string
    {
        return match (self::riskLabel($app)) {
            'Критичный' => 'danger',
            'Высокий' => 'warning',
            'Средний' => 'info',
            default => 'success',
        };
    }

    private static function expiresLabel(App $app): string
    {
        $expiresAt = self::parseDate($app->expires_tariff_at);

        if (!$expiresAt) {
            return '—';
        }

        if (self::effectiveStatus($app) === App::STATE_EXPIRES) {
            $days = $expiresAt->diffInDays(now()->startOfDay());

            return $days === 0
                ? 'Сегодня'
                : 'Просрочено на ' . $days . ' дн.';
        }

        $daysLeft = now()->startOfDay()->diffInDays($expiresAt, false);

        if ($daysLeft === 0) {
            return 'Сегодня';
        }

        return $daysLeft > 0
            ? 'Через ' . $daysLeft . ' дн.'
            : $expiresAt->format('d.m.Y');
    }

    private static function expiresColor(App $app): string
    {
        if (self::effectiveStatus($app) === App::STATE_EXPIRES) {
            return 'danger';
        }

        if (self::isExpiringSoon($app)) {
            return 'warning';
        }

        return 'gray';
    }

    private static function expiresTooltip(App $app): ?string
    {
        $expiresAt = self::parseDate($app->expires_tariff_at);

        return $expiresAt?->format('d.m.Y');
    }

    private static function installedLabel(App $app): string
    {
        $installedAt = self::parseDate($app->installed_at);

        if (!$installedAt) {
            return '—';
        }

        return $installedAt->diffForHumans();
    }

    private static function installedTooltip(App $app): ?string
    {
        $installedAt = self::parseDate($app->installed_at);

        return $installedAt?->format('d.m.Y H:i');
    }

    private static function isExpiringSoon(App $app): bool
    {
        if (self::effectiveStatus($app) !== App::STATE_ACTIVE) {
            return false;
        }

        $expiresAt = self::parseDate($app->expires_tariff_at);

        if (!$expiresAt) {
            return false;
        }

        $today = now()->startOfDay();

        return $expiresAt->betweenIncluded($today, $today->copy()->addDays(7));
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
