<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class IndustryClinicsSalonsLifecycleController extends Controller
{
    public function redirect(Request $request): Response
    {
        $this->logCallback('install', $request);
        $this->sendTelegramNotification('install', $request);

        return response(
            '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Clever</title></head>'
            .'<body style="font-family:Arial,sans-serif;padding:32px;color:#23262f">'
            .'<h1 style="font-size:24px">Решение установлено</h1>'
            .'<p>Настройка «Клиники и салоны» продолжится в amoCRM.</p>'
            .'</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function off(Request $request): JsonResponse
    {
        $this->logCallback('off', $request);
        $this->sendTelegramNotification('off', $request);

        return response()->json(['ok' => true]);
    }

    private function logCallback(string $event, Request $request): void
    {
        $payload = $request->all();

        foreach (['code', 'signature'] as $secret) {
            if (filled(data_get($payload, $secret))) {
                data_set($payload, $secret, '[received]');
            }
        }

        Log::info("amocrm.industry-clinics-salons.{$event} received", [
            'method' => $request->method(),
            'payload' => $payload,
            'referer' => (string) $request->input('referer', $request->header('referer', '')),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function sendTelegramNotification(string $event, Request $request): void
    {
        $token = trim((string) config('industry_solutions.telegram.token', ''));
        $chatId = trim((string) config('industry_solutions.telegram.chat_id', ''));

        if ($token === '' || $chatId === '') {
            Log::warning('amocrm.industry-clinics-salons.telegram is not configured');

            return;
        }

        $accountId = trim((string) $request->input('account_id', $request->input('account.id', '')));
        $clientId = trim((string) $request->input('client_uuid', $request->input('client_id', '')));
        $referer = trim((string) $request->input('referer', $request->header('referer', '')));
        $platform = trim((string) $request->input('platform', ''));

        $lines = [
            $event === 'install' ? '🟢 Виджет установлен' : '🔴 Виджет отключён',
            'Решение: Клиники и салоны',
            'Аккаунт: '.($accountId !== '' ? $accountId : 'не передан'),
            'Домен: '.($referer !== '' ? $referer : 'не передан'),
            'ID интеграции: '.($clientId !== '' ? $clientId : 'не передан'),
        ];

        if ($platform !== '') {
            $lines[] = 'Платформа: '.$platform;
        }

        $lines[] = 'Время: '.now()->format('d.m.Y H:i:s');

        $body = [
            'chat_id' => $chatId,
            'text' => implode("\n", $lines),
            'disable_web_page_preview' => true,
        ];

        $messageThreadId = trim((string) config('industry_solutions.telegram.message_thread_id', ''));
        if ($messageThreadId !== '') {
            $body['message_thread_id'] = $messageThreadId;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(2)
                ->timeout(3)
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', $body);

            if (! $response->successful() || $response->json('ok') !== true) {
                Log::warning('amocrm.industry-clinics-salons.telegram rejected', [
                    'event' => $event,
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('amocrm.industry-clinics-salons.telegram failed', [
                'event' => $event,
                'exception' => $exception::class,
            ]);
        }
    }
}
