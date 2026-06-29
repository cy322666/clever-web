<?php

namespace App\Console\Commands\Core;

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Throwable;

class SendPasswordResetLink extends Command
{
    protected $signature = 'app:send-password-reset-link {email : User email address}';

    protected $description = 'Send a Filament password reset link immediately, without queueing the notification';

    public function handle(): int
    {
        $email = (string)$this->argument('email');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (!$user) {
            $this->error('User not found: ' . $email);

            return self::FAILURE;
        }

        if (!$user->canAccessPanel(Filament::getPanel('app'))) {
            $this->error('User cannot access app panel: ' . $email);

            return self::FAILURE;
        }

        $token = Password::broker(Filament::getAuthPasswordBroker())->createToken($user);

        $notification = app(FilamentResetPassword::class, ['token' => $token]);
        $notification->url = Filament::getPanel('app')->getResetPasswordUrl($token, $user);

        try {
            Notification::sendNow($user, $notification);
        } catch (Throwable $e) {
            $this->error($e::class . ': ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Password reset link sent to ' . $email);

        return self::SUCCESS;
    }
}
