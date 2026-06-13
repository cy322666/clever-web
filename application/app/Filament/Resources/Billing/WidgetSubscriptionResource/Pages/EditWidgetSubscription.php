<?php

namespace App\Filament\Resources\Billing\WidgetSubscriptionResource\Pages;

use App\Filament\Resources\Billing\WidgetSubscriptionResource;
use App\Services\Billing\WidgetSubscriptionAccessService;
use Filament\Resources\Pages\EditRecord;

class EditWidgetSubscription extends EditRecord
{
    protected static string $resource = WidgetSubscriptionResource::class;

    protected function afterSave(): void
    {
        app(WidgetSubscriptionAccessService::class)->syncSubscriptionToLegacyApp($this->record);
    }
}
