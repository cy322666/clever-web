<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AmoDisconnected extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public array $widgets = [],
        public array $subdomains = [],
    ) {
    }

    public function build(): self
    {
        return $this->subject('amoCRM отключена — Clever Platform')
            ->view('emails.amo_disconnected')
            ->with([
                'user' => $this->user,
                'widgets' => $this->widgets,
                'subdomains' => $this->subdomains,
            ]);
    }
}
