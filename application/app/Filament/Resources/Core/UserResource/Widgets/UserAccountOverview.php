<?php

namespace App\Filament\Resources\Core\UserResource\Widgets;

use App\Models\App;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Throwable;

class UserAccountOverview extends StatsOverviewWidget
{
    public ?User $record = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        if (!$this->record) {
            return [
                Stat::make('Аккаунт', 'недоступен')
                    ->description('Запись пользователя не найдена')
                    ->color('gray'),
            ];
        }

        $today = now()->startOfDay();
        $soon = $today->copy()->addDays(7);

        $apps = $this->record->apps()
            ->where('status', '!=', App::STATE_CREATED)
            ->get(['status', 'expires_tariff_at']);

        $active = 0;
        $expired = 0;
        $expiringSoon = 0;

        foreach ($apps as $app) {
            $expiresAt = $this->parseDate($app->expires_tariff_at);
            $isExpired = (int)$app->status === App::STATE_EXPIRES
                || ((int)$app->status === App::STATE_ACTIVE && $expiresAt?->lt($today));

            if ($isExpired) {
                $expired++;

                continue;
            }

            if ((int)$app->status === App::STATE_ACTIVE) {
                $active++;

                if ($expiresAt?->betweenIncluded($today, $soon)) {
                    $expiringSoon++;
                }
            }
        }

        $activeAmoAccounts = $this->record
            ->accounts()
            ->where('active', true)
            ->get(['subdomain']);

        $amoConnected = $activeAmoAccounts->isNotEmpty();
        $subdomain = $activeAmoAccounts->pluck('subdomain')->filter()->first();
        $amoDescription = $amoConnected
            ? (
            $activeAmoAccounts->count() > 1
                ? 'Подключений: ' . $activeAmoAccounts->count()
                : ((filled($subdomain) ? $subdomain . '.amocrm.ru' : 'Аккаунт подключен'))
            )
            : 'Требуется авторизация';

        return [
            Stat::make('amoCRM', $amoConnected ? 'Подключена' : 'Не подключена')
                ->description($amoDescription)
                ->descriptionIcon($amoConnected ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
                ->color($amoConnected ? 'success' : 'danger'),

            Stat::make('Установленные интеграции', (string)$apps->count())
                ->description('Только установленные')
                ->descriptionIcon('heroicon-o-squares-2x2')
                ->color('info'),

            Stat::make('Активные', (string)$active)
                ->description('Доступны и не просрочены')
                ->descriptionIcon('heroicon-o-bolt')
                ->color('success'),

            Stat::make('Просроченные', (string)$expired)
                ->description('Нужно продление/переподключение')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($expired > 0 ? 'danger' : 'gray'),

            Stat::make('Истекают через 7 дней', (string)$expiringSoon)
                ->descriptionIcon('heroicon-o-clock')
                ->color($expiringSoon > 0 ? 'warning' : 'gray'),
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
