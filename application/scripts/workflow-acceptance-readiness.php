<?php

// Read-only installation diagnostic. Never print tokens or CRM data.
$root = getenv('WORKFLOW_APP_ROOT') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$result = ['scheduler_timezone' => config('app.timezone'), 'php_signals' => function_exists('pcntl_signal')];
foreach (['alerts.channels.telegram', 'widget_lifecycle.telegram'] as $prefix) {
    $token = (string) config($prefix.'.token');
    $chat = (string) config($prefix.'.chat_id');
    $info = ['configured' => $token !== '' && $chat !== '', 'chat_fingerprint' => $chat === '' ? null : substr(hash('sha256', $chat), 0, 12)];
    if ($info['configured']) {
        try {
            $response = Illuminate\Support\Facades\Http::connectTimeout(10)->timeout(20)->post('https://api.telegram.org/bot'.$token.'/getChat', ['chat_id' => $chat]);
            $info += ['ok' => $response->json('ok') === true, 'chat_type' => $response->json('result.type'), 'chat_username' => $response->json('result.username'), 'http_status' => $response->status(), 'description' => $response->json('description')];
            if ($prefix === 'alerts.channels.telegram') {
                $bot = Illuminate\Support\Facades\Http::timeout(15)->get('https://api.telegram.org/bot'.$token.'/getMe');
                $info['bot_username'] = $bot->json('result.username');
            }
        } catch (Throwable $e) { $info['error'] = $e::class; $info['detail'] = str_replace($token, '[redacted]', $e->getMessage()); }
    }
    $result[$prefix] = $info;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
