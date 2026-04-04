<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppSubscriptionExpired extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $appName,
        public int $appId,
        public ?string $expiresAt,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Подписка интеграции завершилась — Clever Platform')
            ->view('emails.app_subscription_expired')
            ->with([
                'user' => $this->user,
                'appName' => $this->appName,
                'appId' => $this->appId,
                'expiresAt' => $this->expiresAt,
            ]);
    }
}
