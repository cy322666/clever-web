<?php

namespace App\Mail;

use App\Models\Integrations\AmoData\Setting;
use App\Models\Integrations\AmoData\SyncRun;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AmoDataSyncFinished extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Setting $setting,
        public SyncRun $run,
    ) {
    }

    public function build(): self
    {
        $subject = $this->run->status === 'success'
            ? 'Выгрузка amoCRM завершена — Clever Platform'
            : 'Выгрузка amoCRM завершилась с ошибкой — Clever Platform';

        return $this->subject($subject)
            ->view('emails.amo_data_sync_finished')
            ->with([
                'setting' => $this->setting,
                'run' => $this->run,
                'user' => $this->setting->user,
            ]);
    }
}
