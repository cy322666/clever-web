<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SignUpWidget extends Mailable
{
    use Queueable, SerializesModels;

    public string $pass;

    public function __construct(public User $user, ?string $pass = null)
    {
        $this->pass = $pass;
    }

    public function build(): self
    {
        return $this->subject('Регистрация завершена — Clever Platform')
            ->view('emails.signup_widget') // resources/views/emails/signup_widget.blade.php
            ->with([
                'user' => $this->user,
                'pass' => $this->pass ?? null,
            ]);
    }
}
