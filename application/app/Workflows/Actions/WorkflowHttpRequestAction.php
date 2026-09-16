<?php

namespace App\Workflows\Actions;

use App\Forms\Components\WorkflowValueInput;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use RuntimeException;

class WorkflowHttpRequestAction
{
    use WorkflowAction;

    public static function workflowType(): string { return 'http_request'; }
    public static function workflowName(): string { return 'HTTP-запрос'; }
    public static function workflowDescription(): string { return 'Запрос к внешнему API'; }
    public static function workflowIcon(): string { return 'heroicon-o-globe-alt'; }
    public static function workflowCategory(): string { return 'Управление потоком'; }
    public static function workflowDefaultConfig(): array { return ['method'=>'GET', 'timeout'=>15, 'headers'=>'{}', 'body'=>'']; }
    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [
            WorkflowValueInput::make('method')->label('Метод')->options(array_combine(['GET','POST','PUT','PATCH','DELETE','HEAD'], ['GET','POST','PUT','PATCH','DELETE','HEAD']))->default('GET')->required(),
            WorkflowValueInput::make('url')->label('URL')->placeholder('https://api.example.com/…')->required(),
            WorkflowValueInput::make('headers')->label('Заголовки · JSON')->multiline(3)->default('{}'),
            WorkflowValueInput::make('body')->label('Тело · JSON')->multiline(6),
            WorkflowValueInput::make('timeout')->label('Таймаут, секунд')->default(15),
        ];
    }

    protected function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
    }

    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        try {
            $config = $context ? $context->resolve($config) : $config;
            $url = (string)($config['url'] ?? '');
            $parts = parse_url($url);
            $host = strtolower(trim($parts['host'] ?? '', '[]'));
            if (!$host || !in_array($parts['scheme'] ?? '', ['http','https'], true) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
                throw new RuntimeException('Укажите публичный HTTP(S) URL без логина и пароля в адресе.');
            }
            $blocked = array_map('strtolower', config('filament-workflows.http_request.blocked_domains', []));
            $allowed = array_map('strtolower', config('filament-workflows.http_request.allowed_domains', []));
            if (in_array($host, $blocked, true) || ($allowed !== [] && !in_array($host, $allowed, true))) throw new RuntimeException('Этот домен запрещён настройками HTTP-запросов.');
            $method = strtoupper((string)($config['method'] ?? 'GET'));
            if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE','HEAD'], true)) throw new RuntimeException('Недопустимый HTTP-метод.');
            $timeout = filter_var($config['timeout'] ?? 15, FILTER_VALIDATE_INT);
            if (!$timeout || $timeout < 1 || $timeout > 30) throw new RuntimeException('Таймаут: от 1 до 30 секунд.');
            $headers = $config['headers'] ?? [];
            if (is_string($headers)) $headers = json_decode($headers ?: '{}', true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($headers)) throw new RuntimeException('Заголовки должны быть JSON-объектом.');
            foreach ($headers as $key => $value) {
                if (!is_string($key) || !preg_match('/^[A-Za-z0-9-]+$/D', $key) || !is_scalar($value) || preg_match('/[\r\n]/', (string)$value) || in_array(strtolower($key), ['host','content-length','transfer-encoding','connection','proxy-authorization'], true)) throw new RuntimeException('Недопустимый заголовок запроса.');
            }
            $body = $config['body'] ?? '';
            if (is_string($body) && $body !== '') $body = trim($body) === '{}' ? (object)[] : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (strlen(json_encode($body)) > 1048576) throw new RuntimeException('Тело запроса превышает 1 МБ.');
            if ($context?->getTriggerSource() === 'test' || $context?->getVariable('_dry_run') || $context?->getVariable('_test_mode')) {
                return ['success'=>true, 'output'=>['dry_run'=>true, 'method'=>$method, 'message'=>'HTTP-запрос не отправлен в тестовом режиме.']];
            }
            $ips = $this->addresses($host);
            if (!$ips) throw new RuntimeException('Не удалось определить публичный адрес сервера.');
            foreach ($ips as $ip) {
                $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
                if (!$public || \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, ['100.64.0.0/10','198.18.0.0/15']) || (str_contains($ip, ':') ? !preg_match('/^[23][0-9a-f]{3}:/i', $ip) : (int)explode('.', $ip)[0] >= 224)) throw new RuntimeException('Запросы во внутреннюю сеть запрещены.');
            }
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            // Pin the validated address: no second DNS lookup, redirects or environment proxy.
            $address = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
            $options = ['allow_redirects'=>false, 'proxy'=>'', 'verify'=>true, 'stream'=>true,
                'curl'=>[CURLOPT_RESOLVE=>[$host.':'.$port.':'.$address]],
            ];
            if (!in_array($method, ['GET','HEAD'], true) && $body !== '') $options['json'] = $body;
            $response = Http::withHeaders($headers)->withHeaders(['Accept-Encoding'=>'identity'])->connectTimeout(5)->timeout($timeout)->send($method, $url, $options);
            $stream = $response->toPsrResponse()->getBody();
            try {
                $raw = '';
                while (!$stream->eof() && strlen($raw) <= 1048576) $raw .= $stream->read(min(8192, 1048577 - strlen($raw)));
                if (strlen($raw) > 1048576) throw new RuntimeException('Ответ HTTP превышает 1 МБ.');
            } finally { $stream->close(); }
            $decoded = json_decode($raw, true);
            $output = ['status'=>$response->status(), 'body'=>json_last_error() === JSON_ERROR_NONE ? $decoded : $raw, 'success'=>$response->successful()];
            return $response->successful() ? ['success'=>true,'output'=>$output] : ['success'=>false,'error'=>'HTTP '.$response->status(),'output'=>$output];
        } catch (\JsonException) {
            return ['success'=>false,'error'=>'Некорректный JSON в заголовках или теле запроса.'];
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return ['success'=>false,'error'=>'Сервер недоступен или истёк таймаут HTTP-запроса.'];
        } catch (\Throwable $e) {
            return ['success'=>false,'error'=>$e instanceof RuntimeException ? $e->getMessage() : 'Не удалось выполнить HTTP-запрос.'];
        }
    }
}
