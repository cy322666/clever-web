<?php

namespace App\Services\amoCRM;

use App\Models\Core\Account;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;
use Ufee\Amo\Base\Models\QueryModel;
use Ufee\Amo\Oauthapi;

class Client
{
    public Oauthapi $service;
    public EloquentStorage $storage;
    public Account $account;
    public User $user;

    public bool $auth = false;
    public bool $logs = false;

    /**
     * @throws Exception
     */
    public function __construct(Account $account)
    {
        $this->account = $account;
        $this->user = $account->user;

        $this->storage = new EloquentStorage([
            'domain'    => $account->subdomain ?? null,
            'client_id' => $account->client_id ?? null,
            'client_secret' => $account->client_secret ?? null,
            'redirect_uri'  => $account->redirect_uri ?? null,
            'zone' => $account->zone ?? null,
        ], $account);

        Oauthapi::setOauthStorage($this->storage);

        $this->service = Oauthapi::setInstance([
            'domain'        => $this->storage->model->subdomain,
            'client_id'     => $this->storage->model->client_id,
            'client_secret' => $this->storage->model->client_secret,
            'redirect_uri'  => $this->storage->model->redirect_uri,
            'zone'          => $this->storage->model->zone,
        ]);

        $this->init();
    }

    public function setDelay(int $second): static
    {
        $this->service->queries->setDelay($second);

        return $this;
    }

    //проверка ключей первый раз
    public function checkAuth(): bool
    {
        if (!$this->storage->model->code ||
            !$this->storage->model->access_token) {

            return false;
        }

        try {
            $this->service->account;

            $this->auth = true;
            $this->clearAuthFailureThrottle();

            return true;

        } catch (Exception $e) {

            Log::error(__METHOD__.' fail check auth', [$e->getMessage()]);
            $this->notifyAuthFailure($e, 'check_auth');

            return false;
        }
    }

    /**
     *
     *
     * @throws Exception
     */
    public function init(): Client
    {
        $this->syncOauthData();

        $this->auth = true;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function ensureAccessToken(bool $forceRefresh = false): static
    {
        $this->syncOauthData($forceRefresh);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function refreshAccessToken(): static
    {
        return $this->ensureAccessToken(true);
    }

    public function initCache(int $time = 3600) : Client
    {
        return $this;

        \Ufee\Amo\Services\Account::setCacheTime($time);
    }

    public function initLogs(mixed $debug = true): Client
    {
        if (is_string($debug)) {
            $debug = filter_var($debug, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        } else {
            $debug = (bool)$debug;
        }

        return $this;

        if (!$debug) return $this;

        $this->service->queries->onResponseCode(429, function(QueryModel $query) {

            $this->user->amocrm_logs()->create([
                'code' => 429,
                'url'  => static::trimUrl($query->getUrl()),
                'method'  => $query->method,
                'details' => json_encode($query->toArray()),
            ]);
        });

        $this->service->queries->listen(
        /**
         * @param QueryModel $query
         * @return void
         */
        function(QueryModel $query) {

            $log =  $this->user
                ->amocrm_logs()
                ->create([
                    'code'  => $query->response->getCode(),
                    'url'   => $query->toArray()['url'] ?? '',
                    'start' => $query->startDate(),
                    'end'   => $query->endDate(),
                    'method'  => $query->method,
                    'details' => json_encode($query->toArray()),

                    'args' => json_encode($query->toArray()['args']) ?? null,
                    'body' => json_encode($query->toArray()['post_data']) ?? null,
                    'retries' => $query->toArray()['retries'] ?? null,
                    'memory_usage' => $query->toArray()['memory_usage'] ?? null,
                    'execution_time' => $query->toArray()['execution_time'] ?? null,
                ]);

            if ($query->response->getCode() === 0) {

                $log->error = $query->response->getError();
            } else
                $log->data = strlen($query->response->getData() > 1) ? $query->response->getData() : [];

            $log->save();
        });

        return $this;
    }

    private static function trimUrl(string $url): string
    {
        return strlen($url) > 250 ? mb_strimwidth($url, 0, 200, "...") : $url;
    }

    /**
     * @throws Exception
     */
    private function syncOauthData(bool $forceRefresh = false): void
    {
        try {
            if (!$this->storage->model->refresh_token && !$this->storage->model->code) {
                throw new Exception('Incorrect amoCRM oauth data');
            }

            if ($forceRefresh) {
                $oauth = $this->refreshOauthPayload();
                $this->persistOauthData($oauth);

                return;
            }

            if (!$this->storage->model->access_token) {
                $oauth = $this->issueInitialOauthPayload();
                $this->persistOauthData($oauth);

                return;
            }

            if ($this->tokenExpiresAt()?->subMinute()->isPast()) {
                $oauth = $this->refreshOauthPayload();
                $this->persistOauthData($oauth);

                return;
            }

            $this->auth = true;
            $this->clearAuthFailureThrottle();
        } catch (Throwable $e) {
            $this->auth = false;
            $this->notifyAuthFailure($e, $forceRefresh ? 'force_refresh' : 'sync_oauth');

            if ($e instanceof Exception) {
                throw $e;
            }

            throw new Exception($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * @throws Exception
     */
    private function refreshOauthPayload(): array
    {
        if (!$this->storage->model->refresh_token) {
            return $this->issueInitialOauthPayload();
        }

        return $this->service->refreshAccessToken($this->storage->model->refresh_token);
    }

    /**
     * @throws Exception
     */
    private function issueInitialOauthPayload(): array
    {
        if (!$this->storage->model->code) {
            throw new Exception('Incorrect amoCRM oauth data');
        }

        return $this->service->fetchAccessToken($this->storage->model->code);
    }

    private function persistOauthData(array $oauth): void
    {
        $this->storage->setOauthData($this->service, [
            'token_type' => 'Bearer',
            'expires_in' => $oauth['expires_in'],
            'access_token' => $oauth['access_token'],
            'refresh_token' => $oauth['refresh_token'],
            'created_at' => $oauth['created_at'] ?? time(),
        ]);

        $this->account->refresh();
        $this->auth = true;
        $this->clearAuthFailureThrottle();
    }

    private function tokenExpiresAt(): ?Carbon
    {
        if (!$this->storage->model->created_at || !$this->storage->model->expires_in) {
            return null;
        }

        $createdAt = is_numeric($this->storage->model->created_at)
            ? Carbon::createFromTimestamp((int)$this->storage->model->created_at)
            : Carbon::parse($this->storage->model->created_at);

        return $createdAt->copy()->addSeconds((int)$this->storage->model->expires_in);
    }

    private function notifyAuthFailure(Throwable $e, string $context): void
    {
        try {
            App::make(AmoAuthFailureNotifier::class)->notify($this->account, $e, $context);
        } catch (Throwable $notifyError) {
            Log::warning('amoCRM auth failure notify failed (ignored)', [
                'account_id' => $this->account->id,
                'user_id' => $this->account->user_id,
                'context' => $context,
                'error' => $notifyError->getMessage(),
            ]);
        }
    }

    private function clearAuthFailureThrottle(): void
    {
        try {
            App::make(AmoAuthFailureNotifier::class)->clearThrottle($this->account);
        } catch (Throwable $e) {
            Log::warning('amoCRM auth failure throttle clear failed (ignored)', [
                'account_id' => $this->account->id,
                'user_id' => $this->account->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
