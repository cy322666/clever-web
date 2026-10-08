<?php

namespace App\Services\amoCRM;

use App\Models\Core\Account;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Shared admission control, not a workflow/job retry mechanism.
 * Redis TIME avoids clock skew; both budgets are claimed in one atomic script.
 */
class AmoCrmRequestThrottle
{
    public const ACQUIRE_SCRIPT = <<<'LUA'
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
local ready = math.max(
    tonumber(redis.call('GET', KEYS[1]) or '0'),
    tonumber(redis.call('GET', KEYS[2]) or '0'),
    tonumber(redis.call('GET', KEYS[3]) or '0')
)
if ready > now then return math.ceil(ready - now) end
redis.call('SET', KEYS[1], string.format('%.0f', now + tonumber(ARGV[1])), 'PX', tonumber(ARGV[1]) + 1000)
redis.call('SET', KEYS[2], string.format('%.0f', now + tonumber(ARGV[2])), 'PX', tonumber(ARGV[2]) + 1000)
return 0
LUA;

    public const COOLDOWN_SCRIPT = <<<'LUA'
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
local until_at = math.max(tonumber(redis.call('GET', KEYS[1]) or '0'), now + tonumber(ARGV[1]))
redis.call('SET', KEYS[1], string.format('%.0f', until_at), 'PX', math.ceil(until_at - now) + 1000)
return 1
LUA;

    public function acquire(Account $account): void
    {
        $keys = $this->keys($account);
        $deadline = $this->monotonicMilliseconds() + $this->maxWaitMilliseconds();

        do {
            $wait = $this->evaluate(self::ACQUIRE_SCRIPT, $keys, [
                max(200, (int) config('amocrm-throttle.integration_interval_ms', 200)),
                max(25, (int) config('amocrm-throttle.account_interval_ms', 25)),
            ]);

            if (! is_numeric($wait) || (int) $wait < 0) {
                throw new RuntimeException('Не удалось проверить общий лимит запросов amoCRM. Запрос не отправлен.');
            }
            if ((int) $wait === 0) {
                return;
            }

            $remaining = $deadline - $this->monotonicMilliseconds();
            if ((int) $wait >= $remaining) {
                throw new RuntimeException('amoCRM временно ограничивает запросы. Время безопасного ожидания истекло; запрос не отправлен.');
            }

            $this->sleepMilliseconds((int) $wait);
            // No future reservations: a 429 in another worker must be observed after sleep.
        } while ($this->monotonicMilliseconds() < $deadline);

        throw new RuntimeException('Истекло время ожидания очереди запросов amoCRM. Запрос не отправлен.');
    }

    public function cooldown(Account $account, ?string $retryAfter = null, int $attempt = 1): void
    {
        $seconds = $this->retryAfterSeconds($retryAfter);
        $fallback = max(2, (int) config('amocrm-throttle.fallback_cooldown_seconds', 2));
        $milliseconds = (int) ceil(max($seconds ?? 0, $fallback * (2 ** min(4, max(0, $attempt - 1)))) * 1000);
        // Account-wide because a 429 does not identify which of amoCRM's budgets was exceeded.
        $result = $this->evaluate(self::COOLDOWN_SCRIPT, [$this->keys($account)[2]], [$milliseconds]);
        if ((int) $result !== 1) {
            throw new RuntimeException('Не удалось сохранить общую паузу amoCRM. Повторный запрос остановлен.');
        }
    }

    /** @return array{string, string, string} */
    public function keys(Account $account): array
    {
        $host = $this->accountHost($account);
        // Missing integration metadata shares a conservative bucket, never an unthrottled path.
        $integration = strtolower(trim((string) $account->client_id)) ?: 'unknown-integration';
        $accountHash = hash('sha256', $host);
        // Same hash tag keeps the atomic keys in one Redis Cluster slot.
        $prefix = 'amocrm:throttle:{'.$accountHash.'}:';

        return [
            $prefix.'integration:'.hash('sha256', $integration),
            $prefix.'account',
            $prefix.'cooldown',
        ];
    }

    protected function accountHost(Account $account): string
    {
        $endpoint = trim((string) $account->endpoint);
        $domain = strtolower(trim((string) $account->subdomain));
        $host = '';
        if ($domain !== '') {
            $zone = strtolower(trim((string) $account->zone)) ?: 'ru';
            $suffix = match ($zone) {
                'com', 'amocrm.com', 'kommo.com' => 'amocrm.com',
                'ru', 'amocrm.ru' => 'amocrm.ru',
                default => 'amocrm.'.$zone,
            };
            $host = str_contains($domain, '.') ? $domain : $domain.'.'.$suffix;
        }

        $host = $this->normalizeHost($host);
        if ($endpoint !== '') {
            $endpointHost = $this->normalizeHost((string) parse_url($endpoint, PHP_URL_HOST));
            // SDK uses subdomain/zone, raw HTTP uses endpoint. They must share one identity.
            if ($endpointHost === '' || ($host !== '' && $host !== $endpointHost)) {
                throw new RuntimeException('Адрес подключения amoCRM не совпадает с аккаунтом. Запрос не отправлен.');
            }
            $host = $endpointHost;
        }

        if ($host === '' || ! preg_match('/^[a-z0-9][a-z0-9.-]*$/', $host)) {
            throw new RuntimeException('Не определён аккаунт amoCRM для ограничения запросов. Запрос не отправлен.');
        }

        return $host;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));

        return preg_replace('/\.kommo\.com$/', '.amocrm.com', $host) ?? $host;
    }

    protected function retryAfterSeconds(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            // Retain long cooldowns even when they exceed this caller's bounded wait.
            return (int) min((float) $value, 2147483647);
        }
        // Accept HTTP-date, not arbitrary relative date expressions.
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('GMT'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return max(0, $date->getTimestamp() - $this->wallTimeSeconds());
            }
        }

        return null;
    }

    /** @param list<string> $keys @param list<int> $arguments */
    protected function evaluate(string $script, array $keys, array $arguments): mixed
    {
        try {
            return Redis::connection((string) config('amocrm-throttle.redis_connection', 'amocrm_throttle'))
                ->eval($script, count($keys), ...array_merge($keys, $arguments));
        } catch (Throwable $error) {
            // Do not leak Redis URLs/passwords and do not fall back to a per-process limiter.
            throw new RuntimeException('Общий ограничитель запросов amoCRM недоступен. Запрос не отправлен.', 0, $error);
        }
    }

    protected function maxWaitMilliseconds(): int
    {
        return max(1, min(60, (int) config('amocrm-throttle.max_wait_seconds', 30))) * 1000;
    }

    protected function monotonicMilliseconds(): int
    {
        return (int) (hrtime(true) / 1000000);
    }

    protected function wallTimeSeconds(): int
    {
        return time();
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
