<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class IndustryClinicsSalonsLifecycleController extends Controller
{
    public function redirect(Request $request): Response
    {
        $this->logCallback('install', $request);

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
}
