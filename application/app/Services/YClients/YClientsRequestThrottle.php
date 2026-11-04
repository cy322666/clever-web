<?php

namespace App\Services\YClients;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/** Shared admission for native YCLIENTS GET attempts, including connection retries. */
class YClientsRequestThrottle
{
    public const INTERVAL_MS = 350;

    public const ACQUIRE_SCRIPT = <<<'LUA'
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
local ready = math.max(
    tonumber(redis.call('GET', KEYS[1]) or '0'),
    tonumber(redis.call('GET', KEYS[2]) or '0')
)
if ready > now then return math.ceil(ready - now) end
redis.call('SET', KEYS[1], string.format('%.0f', now + tonumber(ARGV[1])), 'PX', tonumber(ARGV[1]) + 1000)
return 0
LUA;

    public const COOLDOWN_SCRIPT = <<<'LUA'
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
local requested = tonumber(ARGV[1])
if tonumber(ARGV[2]) == 1 then
    requested = math.max(now + 2000, requested)
else
    requested = now + math.max(2000, requested)
end
local ready = math.max(tonumber(redis.call('GET', KEYS[1]) or '0'), requested)
redis.call('SET', KEYS[1], string.format('%.0f', ready), 'PX', math.ceil(ready - now) + 1000)
return 1
LUA;

    /** All containers/accounts share one outbound-IP budget; never split it by token. */
    public function keys(): array
    {
        return ['yclients:throttle:{outbound-ip}:next', 'yclients:throttle:{outbound-ip}:cooldown'];
    }

    public function acquire(): void
    {
        $deadline = $this->milliseconds() + 30000;
        do {
            $wait = $this->evaluate(self::ACQUIRE_SCRIPT, $this->keys(), [self::INTERVAL_MS]);
            if ((! is_int($wait) && ! (is_string($wait) && ctype_digit($wait))) || (int) $wait < 0) {
                throw new RuntimeException('YCLIENTS admission returned an invalid result. Request not sent.');
            }
            if ((int) $wait === 0) {
                return;
            }
            if ((int) $wait >= $deadline - $this->milliseconds()) {
                throw new RuntimeException('YCLIENTS shared admission wait expired. Request not sent.');
            }
            $this->sleepMilliseconds((int) $wait);
            // Claim only now, not a future ticket: another worker's 429 must be rechecked.
        } while ($this->milliseconds() < $deadline);

        throw new RuntimeException('YCLIENTS shared admission wait expired. Request not sent.');
    }

    public function cooldown(?string $retryAfter): void
    {
        [$value, $absolute] = $this->retryAfter($retryAfter);
        $result = $this->evaluate(self::COOLDOWN_SCRIPT, [$this->keys()[1]], [$value, $absolute]);
        if ($result !== 1 && $result !== '1') {
            throw new RuntimeException('YCLIENTS shared cooldown could not be stored.');
        }
    }

    /** @return array{int, int} A duration in ms, or an absolute HTTP-date in ms. */
    protected function retryAfter(?string $value): array
    {
        $value = trim((string) $value);
        if ($value !== '' && ctype_digit($value)) {
            return [(int) (min((float) $value, 2147483647) * 1000), 0];
        }
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('GMT'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return [$date->getTimestamp() * 1000, 1];
            }
        }

        return [2000, 0];
    }

    protected function evaluate(string $script, array $keys, array $arguments): mixed
    {
        try {
            // Existing dedicated connection has bounded I/O and no reconnect retries.
            return Redis::connection('amocrm_throttle')->eval($script, count($keys), ...array_merge($keys, $arguments));
        } catch (Throwable $error) {
            throw new RuntimeException('YCLIENTS shared admission is unavailable. Request not sent.', 0, $error);
        }
    }

    protected function milliseconds(): int
    {
        return (int) (hrtime(true) / 1000000);
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
