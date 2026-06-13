<?php

namespace App\Filament\Resources\Billing\WidgetSubscriptionResource\Pages;

use App\Filament\Resources\Billing\WidgetSubscriptionResource;
use App\Services\Billing\WidgetSubscriptionAccessService;
use Filament\Resources\Pages\CreateRecord;

class CreateWidgetSubscription extends CreateRecord
{
    protected static string $resource = WidgetSubscriptionResource::class;

    protected function afterCreate(): void
    {
        app(WidgetSubscriptionAccessService::class)->syncSubscriptionToLegacyApp($this->record);
    }
}
