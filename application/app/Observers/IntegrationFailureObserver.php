<?php

namespace App\Observers;

use App\Services\Integrations\IntegrationErrorNotifier;
use BackedEnum;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

final class IntegrationFailureObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        $status = $model->getAttribute('status');
        $status = $status instanceof BackedEnum ? $status->value : $status;
        $error = $model->getAttribute('last_error');
        if (($status === 'failed' && ($model->wasRecentlyCreated || $model->wasChanged(['status', 'error_message', 'error'])))
            || (filled($error) && ($model->wasRecentlyCreated || $model->wasChanged('last_error')))) {
            app(IntegrationErrorNotifier::class)->modelFailed($model);
        }
    }
}
