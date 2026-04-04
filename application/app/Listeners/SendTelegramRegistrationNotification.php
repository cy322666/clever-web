<?php

namespace App\Listeners;

use App\Services\Telegram;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SendTelegramRegistrationNotification
{
    public function handle(Registered $event): void
    {
        $chatId = (string)config('services.telegram.chat_id');
        $token = (string)config('services.telegram.token');

        if (blank($chatId) || blank($token)) {
            return;
        }

        $user = $event->user;

        if (!$user) {
            return;
        }

        $createdAt = $user->created_at
            ? Carbon::parse($user->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i:s')
            : now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $name = trim((string)($user->name ?? ''));
        $email = trim((string)($user->email ?? ''));

        $message = implode("\n", [
            'Новая регистрация на платформе',
            'ID: ' . $user->id,
            'Имя: ' . ($name !== '' ? $name : '-'),
            'Email: ' . ($email !== '' ? $email : '-'),
            'Время: ' . $createdAt,
            'URL: ' . config('app.url'),
        ]);

        try {
            Telegram::send($message, $chatId, $token, [], false);
        } catch (\Throwable $e) {
            Log::warning('Telegram registration notification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
