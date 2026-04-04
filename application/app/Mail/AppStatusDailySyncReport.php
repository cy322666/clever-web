<?php

namespace App\Mail;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppStatusDailySyncReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public array $items,
        public Carbon $syncDate,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Ежедневная синхронизация интеграций — Clever Platform')
            ->view('emails.app_status_daily_sync_report')
            ->with([
                'user' => $this->user,
                'items' => $this->items,
                'syncDate' => $this->syncDate,
            ]);
    }
}
