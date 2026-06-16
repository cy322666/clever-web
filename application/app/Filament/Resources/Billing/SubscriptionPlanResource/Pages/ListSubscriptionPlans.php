<?php

namespace App\Filament\Resources\Billing\SubscriptionPlanResource\Pages;

use App\Filament\Resources\Billing\SubscriptionPlanResource;
use App\Filament\Resources\Billing\Widgets\BlockedSubscriptionsNotice;
use App\Filament\Resources\Billing\Widgets\CurrentSubscriptionsTable;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionPlans extends ListRecords
{
    protected static string $resource = SubscriptionPlanResource::class;

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        $widgets = [
            CurrentSubscriptionsTable::class,
        ];

        if (!BlockedSubscriptionsNotice::canView()) {
            return $widgets;
        }

        return [
            ...$widgets,
            BlockedSubscriptionsNotice::class,
        ];
    }
}
