<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class IndustryClinicsSalonsLifecycleController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $this->logCallback('install', $request);
        app(AmoCrmWidgetLifecycleTelegramNotifier::class)->notify(
            'install',
            'industry-clinics-salons',
            $request->all(),
            ['referer' => (string) $request->input('referer', $request->header('referer', ''))],
        );

        $referer = trim((string) $request->input('referer', ''));
        $host = parse_url(str_contains($referer, '://') ? $referer : 'https://'.$referer, PHP_URL_HOST);
        $target = is_string($host) && preg_match('/^[a-z0-9-]+\.(?:amocrm\.(?:ru|com)|kommo\.com)$/i', $host)
            ? 'https://'.strtolower($host).'/settings/widgets/'
            : route('filament.app.pages.dashboard');

        return redirect()->away($target, 303)
            ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function off(Request $request): JsonResponse
    {
        $this->logCallback('off', $request);
        app(AmoCrmWidgetLifecycleTelegramNotifier::class)->notify(
            'off',
            'industry-clinics-salons',
            $request->all(),
            ['referer' => (string) $request->input('referer', $request->header('referer', ''))],
        );

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
