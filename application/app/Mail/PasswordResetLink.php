<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $url,
        public int $expiresInMinutes,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Сброс пароля — Clever Platform')
            ->view('emails.password_reset')
            ->with([
                'user' => $this->user,
                'url' => $this->url,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
