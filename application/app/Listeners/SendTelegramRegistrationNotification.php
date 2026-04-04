<?php

namespace App\Listeners;

use App\Services\Core\AlertService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Carbon;

class SendTelegramRegistrationNotification
{
    public function handle(Registered $event): void
    {
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

        AlertService::info(
            title: 'Новая регистрация',
            message: $message,
            context: ['user_id' => $user->id],
            dedupeKey: 'registration:' . $user->id,
            ttlSeconds: 86400,
        );
    }
}
