<?php

namespace App\Services\amoCRM;

use App\Models\Core\Account;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
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

        if(!$this->checkAuth()) {

            $this->init();
        }
    }

    public function setDelay(int $second): static
    {
        $this->service->queries->setDelay($second);

        return $this;
    }

    public function checkAuth(): bool
    {
        if ($this->storage->model->code == null) {

            return false;
        }

        try {
            $this->service->account;

            return true;

        } catch (Exception $e) {

            Log::channel('amo_debug')->warning('fail check auth', [$e->getMessage()]);

            return false;
        }
    }

    /**
     * @throws Exception
     */
    public function init(): Client
    {
        if ($this->storage->model->refresh_token) {

            $oauth = $this->service->refreshAccessToken($this->storage->model->refresh_token);

            Log::channel('tokens')->info('refresh user : '.$this->user->email, $oauth);

        } else
            $oauth = $this->service->fetchAccessToken($this->storage->model->code);

        $this->storage->setOauthData($this->service, [
            'token_type'    => 'Bearer',
            'expires_in'    => $oauth['expires_in'],
            'access_token'  => $oauth['access_token'],
            'refresh_token' => $oauth['refresh_token'],
            'created_at'    => $oauth['created_at'] ?? time(),
        ]);

        $this->auth = true;

        return $this;
    }

    public function initCache(int $time = 3600) : Client
    {
        \Ufee\Amo\Services\Account::setCacheTime($time);

        return $this;
    }

    public function initLogs(bool $debug = true): Client
    {
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

}
