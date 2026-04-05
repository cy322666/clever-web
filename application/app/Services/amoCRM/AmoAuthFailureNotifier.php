<?php

namespace App\Services\amoCRM;

use App\Mail\AmoAuthFailed;
use App\Models\Core\Account;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AmoAuthFailureNotifier
{
    public function notify(Account $account, Throwable $exception, string $context = 'oauth'): void
    {
        if (!$this->shouldNotify($account)) {
            return;
        }

        $email = $account->user?->email;
        if (!$email) {
            return;
        }

        $cooldownMinutes = $this->cooldownMinutes();
        $cacheKey = $this->cacheKey($account);

        if (!Cache::add($cacheKey, now()->toIso8601String(), now()->addMinutes($cooldownMinutes))) {
            return;
        }

        try {
            Mail::mailer('failover')
                ->to($email)
                ->queue(new AmoAuthFailed($account, $context, $exception->getMessage(), $cooldownMinutes));
        } catch (Throwable $mailException) {
            Cache::forget($cacheKey);

            Log::warning('amoCRM auth failure email failed', [
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'email' => $email,
                'context' => $context,
                'error' => $mailException->getMessage(),
            ]);
        }
    }

    private function shouldNotify(Account $account): bool
    {
        return (bool)$account->active
            || !empty($account->access_token)
            || !empty($account->refresh_token)
            || !empty($account->subdomain);
    }

    private function cooldownMinutes(): int
    {
        return max(1, (int)config('integrations.amo_auth_alert.cooldown_minutes', 360));
    }

    private function cacheKey(Account $account): string
    {
        return 'amocrm:auth-alert:' . $account->id;
    }

    public function clearThrottle(Account $account): void
    {
        Cache::forget($this->cacheKey($account));
    }
}

