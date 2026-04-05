<?php

namespace App\Mail;

use App\Models\Core\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AmoAuthFailed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Account $account,
        public string $context,
        public string $error,
        public int $cooldownMinutes,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Требуется повторная авторизация amoCRM — Clever Platform')
            ->view('emails.amo_auth_failed')
            ->with([
                'account' => $this->account,
                'user' => $this->account->user,
                'context' => $this->context,
                'error' => $this->error,
                'cooldownMinutes' => $this->cooldownMinutes,
            ]);
    }
}

