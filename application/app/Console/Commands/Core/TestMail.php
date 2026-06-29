<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestMail extends Command
{
    protected $signature = 'app:test-mail {email : Recipient email address}';

    protected $description = 'Send a plain test email through the configured default mailer';

    public function handle(): int
    {
        $email = (string)$this->argument('email');

        $this->info('Mailer: ' . config('mail.default'));
        $this->info('Host: ' . (string)config('mail.mailers.smtp.host'));
        $this->info('Port: ' . (string)config('mail.mailers.smtp.port'));
        $this->info('Encryption: ' . ((string)config('mail.mailers.smtp.encryption') ?: 'none'));
        $this->info('From: ' . config('mail.from.address'));

        try {
            Mail::raw('Test email from CleverCRM at ' . now()->toIso8601String(), function ($message) use ($email): void {
                $message
                    ->to($email)
                    ->subject('CleverCRM test email');
            });
        } catch (Throwable $e) {
            $this->error($e::class . ': ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Test email sent to ' . $email);

        return self::SUCCESS;
    }
}
