<?php

declare(strict_types=1);

namespace App\Services\Workflows\Testing;

use Closure;
use RuntimeException;
use Throwable;

/** Bounded read-after-write verification for the exact temporary QA subscription only. */
final class WorkflowAcceptanceWebhookSubscription
{
    private const PATH = '/api/v4/webhooks';
    private const BUDGET_SECONDS = 45.0;
    private const POLL_SECONDS = 2.0;
    private const SETTLE_SECONDS = 12.0;
    private const ABSENCE_CONFIRMATIONS = 3;
    private const MAX_PAGES = 20;

    private Closure $request;
    private Closure $record;
    private Closure $sleep;
    private Closure $now;

    /** Request returns the decoded API response, as Client::requestV4 does. Clock/sleep use seconds. */
    public function __construct(callable $request, callable $record, ?callable $sleep = null, ?callable $now = null)
    {
        $this->request = Closure::fromCallable($request);
        $this->record = Closure::fromCallable($record);
        $this->sleep = Closure::fromCallable($sleep ?? static fn(float $seconds) => usleep((int)ceil($seconds * 1_000_000)));
        $this->now = Closure::fromCallable($now ?? static fn(): float => hrtime(true) / 1_000_000_000);
    }

    public function install(string $destination, array $events): array
    {
        $this->validateDestination($destination);
        $events = $this->validateEvents($events);
        $deadline = ($this->now)() + self::BUDGET_SECONDS;
        $this->emit(['phase'=>'install','method'=>'POST','path'=>self::PATH,'status'=>'attempt','attempt'=>1,'expected_events'=>$events]);
        try {
            $response = ($this->request)('POST', self::PATH, ['destination'=>$destination,'settings'=>$events], []);
            $this->emit(['phase'=>'install','method'=>'POST','path'=>self::PATH,'status'=>'accepted','attempt'=>1,
                'webhook_id'=>$this->responseId($response),'http_status'=>null]);
        } catch (Throwable $error) {
            // A timeout can mean the mutation happened. Resolve by GET; never replay POST.
            $this->emit($this->requestFailure('install','POST',$error)+['attempt'=>1]);
        }

        $attempt = 0;
        $reason = 'подписка не появилась в списке amoCRM';
        while (($this->now)() < $deadline) {
            $attempt++;
            try {
                $hooks = $this->readAll($deadline, 'install', $attempt);
                $matching = array_values(array_filter($hooks, static fn(array $hook): bool => $hook['destination'] === $destination));
                $inspection = ['phase'=>'install','method'=>'GET','path'=>self::PATH,'status'=>'pending','attempt'=>$attempt,
                    'destination_matches'=>$matching!==[],'webhook_id'=>null,'missing_events'=>$events,'disabled'=>null];
                foreach ($matching as $hook) {
                    $missing = array_values(array_diff($events, $hook['settings'] ?? []));
                    $enabled = array_key_exists('disabled',$hook) && in_array($hook['disabled'],[false,0,'0'],true);
                    $inspection['webhook_id'] = $this->responseId($hook);
                    $inspection['missing_events'] = $missing;
                    $inspection['disabled'] = $enabled ? false : true;
                    if ($enabled && $missing === [] && ($this->now)() < $deadline) {
                        $inspection['status'] = 'verified';
                        $this->emit($inspection);
                        return ['status'=>'installed','webhook_id'=>$inspection['webhook_id'],'attempts'=>$attempt,'expected_events'=>$events];
                    }
                    $reason = !$enabled ? 'подписка выключена или её включение не подтверждено'
                        : 'в подписке отсутствуют события: '.implode(', ',$missing);
                }
                if ($matching === []) $reason = 'подписка не появилась в списке amoCRM';
                $this->emit($inspection);
            } catch (Throwable $error) {
                $reason = 'amoCRM не позволила прочитать полный список подписок';
                $this->emit($this->requestFailure('install','GET',$error)+['attempt'=>$attempt]);
            }
            $this->pause($deadline);
        }
        $this->emit(['phase'=>'install','method'=>'GET','path'=>self::PATH,'status'=>'failed','attempt'=>$attempt,
            'error'=>'verification_timeout','reason'=>$reason]);
        throw new RuntimeException('Не удалось подтвердить тестовый вебхук amoCRM за 45 секунд: '.$reason.'.');
    }

    public function remove(string $destination): array
    {
        $this->validateDestination($destination);
        $deadline = ($this->now)() + self::BUDGET_SECONDS;
        $deletions = 0;
        $attempt = 0;
        $absentSince = null;
        $confirmations = 0;
        // Always send one scoped DELETE after an attempted installation, even if GET was stale.
        $this->deleteExact($destination, ++$deletions);
        while (($this->now)() < $deadline) {
            $attempt++;
            try {
                $hooks = $this->readAll($deadline, 'remove', $attempt);
                $found = array_values(array_filter($hooks, static fn(array $hook): bool => $hook['destination'] === $destination));
                if ($found !== []) {
                    $confirmations = 0;
                    $absentSince = null;
                    $this->emit(['phase'=>'remove','method'=>'GET','path'=>self::PATH,'status'=>'pending','attempt'=>$attempt,
                        'destination_matches'=>true,'webhook_id'=>$this->responseId($found[0]),'reason'=>'subscription_reappeared']);
                    // Repeat only after GET positively identifies this exact QA destination.
                    if (($this->now)() < $deadline) $this->deleteExact($destination, ++$deletions);
                } else {
                    $now = ($this->now)();
                    $absentSince ??= $now;
                    $confirmations++;
                    $settled = $now - $absentSince;
                    $verified = $confirmations >= self::ABSENCE_CONFIRMATIONS && $settled >= self::SETTLE_SECONDS && $now < $deadline;
                    $this->emit(['phase'=>'remove','method'=>'GET','path'=>self::PATH,'status'=>$verified?'verified':'pending',
                        'attempt'=>$attempt,'destination_matches'=>false,'absence_confirmations'=>$confirmations,'settled_seconds'=>$settled]);
                    if ($verified) return ['status'=>'removed','removed'=>true,'delete_attempts'=>$deletions,
                        'absence_confirmations'=>$confirmations,'settled_seconds'=>$settled];
                }
            } catch (Throwable $error) {
                // An unreadable page breaks the continuous proof of absence.
                $confirmations = 0;
                $absentSince = null;
                $this->emit($this->requestFailure('remove','GET',$error)+['attempt'=>$attempt]);
            }
            $this->pause($deadline);
        }
        $this->emit(['phase'=>'remove','method'=>'GET','path'=>self::PATH,'status'=>'failed','attempt'=>$attempt,
            'error'=>'absence_not_confirmed','delete_attempts'=>$deletions,'absence_confirmations'=>$confirmations]);
        throw new RuntimeException('Не удалось подтвердить удаление тестового вебхука amoCRM за 45 секунд. Нужна проверка восстановления перед следующим прогоном.');
    }

    private function deleteExact(string $destination, int $attempt): void
    {
        $this->emit(['phase'=>'remove','method'=>'DELETE','path'=>self::PATH,'status'=>'attempt','attempt'=>$attempt]);
        try {
            $response = ($this->request)('DELETE',self::PATH,['destination'=>$destination],[]);
            $this->emit(['phase'=>'remove','method'=>'DELETE','path'=>self::PATH,'status'=>'accepted','attempt'=>$attempt,
                'webhook_id'=>$this->responseId($response),'http_status'=>null]);
        } catch (Throwable $error) {
            // A response timeout does not justify blind mutation retries. GET resolves the outcome.
            $this->emit($this->requestFailure('remove','DELETE',$error)+['attempt'=>$attempt]);
        }
    }

    private function readAll(float $deadline, string $phase, int $attempt): array
    {
        $all = [];
        $seen = [];
        for ($page=1; $page<=self::MAX_PAGES; $page++) {
            if (($this->now)() >= $deadline) throw new RuntimeException('Истекло время проверки подписок.');
            $this->emit(['phase'=>$phase,'method'=>'GET','path'=>self::PATH,'status'=>'attempt','attempt'=>$attempt,'page'=>$page]);
            // Never follow an API-supplied URL; only increment the page on the known endpoint.
            $response = ($this->request)('GET', self::PATH, [], ['limit'=>250,'page'=>$page]);
            if (!is_array($response)) throw new RuntimeException('Некорректный ответ списка подписок.');
            // Client::requestV4 also maps malformed/empty success bodies to []. Only an
            // explicit collection can establish that the exact subscription is absent.
            $rows = $response['_embedded']['webhooks'] ?? null;
            if (!is_array($rows)) throw new RuntimeException('В ответе amoCRM отсутствует список подписок.');
            foreach ($rows as $hook) {
                if (!is_array($hook) || !is_string($hook['destination']??null) || !is_array($hook['settings']??[])) {
                    throw new RuntimeException('Некорректная запись в списке подписок amoCRM.');
                }
                foreach ($hook['settings']??[] as $event) if (!is_string($event)) throw new RuntimeException('Некорректные события подписки amoCRM.');
                $all[] = $hook;
            }
            $next = !empty($response['_links']['next']);
            $this->emit(['phase'=>$phase,'method'=>'GET','path'=>self::PATH,'status'=>'accepted','attempt'=>$attempt,
                'page'=>$page,'hook_count'=>count($rows),'has_next'=>$next,'http_status'=>null]);
            if (!$next) return $all;
            $fingerprint = hash('sha256',serialize($rows));
            if (isset($seen[$fingerprint])) throw new RuntimeException('amoCRM повторяет страницу списка подписок.');
            $seen[$fingerprint] = true;
        }
        throw new RuntimeException('Список подписок превысил безопасный лимит в 20 страниц.');
    }

    private function requestFailure(string $phase, string $method, Throwable $error): array
    {
        $status = null;
        if (preg_match('/(?:returned|ошибку|HTTP)\s+([1-5][0-9]{2})(?:\D|$)/u',$error->getMessage(),$match)) $status=(int)$match[1];
        // Exception text can contain a token-bearing callback or a raw CRM response.
        return ['phase'=>$phase,'method'=>$method,'path'=>self::PATH,'status'=>'failed','http_status'=>$status,
            'error'=>'request_or_response_failed','reason'=>$status?'amoCRM вернула HTTP '.$status:'Запрос или проверка ответа amoCRM не завершились успешно'];
    }

    private function responseId(mixed $response): ?int
    {
        $value=is_array($response)?($response['id']??$response['_embedded']['webhooks'][0]['id']??null):null;
        return is_numeric($value) && (int)$value>0 ? (int)$value : null;
    }

    private function validateDestination(string $destination): void
    {
        $parts=parse_url($destination);
        if (!$parts || ($parts['scheme']??'')!=='https' || empty($parts['host']) || empty($parts['path']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Некорректный адрес тестовой подписки amoCRM.');
        }
    }

    private function validateEvents(array $events): array
    {
        foreach ($events as $event) if (!is_string($event) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$event)) throw new RuntimeException('Некорректный код события тестовой подписки amoCRM.');
        if ($events===[]) throw new RuntimeException('Не указаны события тестовой подписки amoCRM.');
        return array_values(array_unique($events));
    }

    private function pause(float $deadline): void
    {
        $remaining=$deadline-($this->now)();
        if ($remaining>0) ($this->sleep)(min(self::POLL_SECONDS,$remaining));
    }

    private function emit(array $event): void
    {
        try { ($this->record)($event); }
        catch (Throwable) { /* Full log/report storage must not stop account recovery. */ }
    }
}
