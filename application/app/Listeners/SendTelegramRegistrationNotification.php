<?php

namespace App\Listeners;

use App\Services\Core\PlatformTechnicalMonitor;
use Illuminate\Auth\Events\Registered;

class SendTelegramRegistrationNotification
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (!$user) {
            return;
        }

        app(PlatformTechnicalMonitor::class)->registration($user);
    }
}
